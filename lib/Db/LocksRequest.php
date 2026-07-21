<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use OCA\FilesLock\ConfigLexicon;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Class LocksRequest
 *
 * @package OCA\FilesLock\Db
 */
class LocksRequest {
	public const string TABLE_LOCKS = 'files_lock';
	private readonly int $timeout;

	public function __construct(
		IAppConfig $appConfig,
		private readonly IDBConnection $connection,
	) {
		$this->timeout = $appConfig->getAppValueInt(ConfigLexicon::LOCK_TIMEOUT) * 60;
	}

	public function save(FileLock $lock): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE_LOCKS);
		$qb->setValue('user_id', $qb->createNamedParameter($lock->getOwner()))
			->setValue('file_id', $qb->createNamedParameter($lock->getFileId()))
			->setValue('token', $qb->createNamedParameter($lock->getToken()))
			->setValue('creation', $qb->createNamedParameter($lock->getCreatedAt()))
			->setValue('type', $qb->createNamedParameter($lock->getType()))
			->setValue('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->setValue('owner', $qb->createNamedParameter($lock->getDisplayName() ?? ''));

		try {
			$qb->executeStatement();
			$lock->setId($qb->getLastInsertId());
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return;
			}
			throw $e;
		}
	}

	public function update(FileLock $lock): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE_LOCKS);
		$qb->set('token', $qb->createNamedParameter($lock->getToken()))
			->set('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->set('user_id', $qb->createNamedParameter($lock->getOwner()))
			->set('owner', $qb->createNamedParameter($lock->getDisplayName() ?? ''))
			->set('scope', $qb->createNamedParameter($lock->getScope()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId())));

		$qb->executeStatement();
	}

	public function delete(FileLock $lock): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId())));

		$qb->executeStatement();
	}

	/**
	 * @param int[] $ids
	 */
	public function removeIds(array $ids): void {
		if (empty($ids)) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$qb->executeStatement();
	}

	/**
	 * @throws LockNotFoundException
	 */
	public function getFromFileId(int $fileId): FileLock {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user_id', 'file_id', 'token', 'creation', 'type', 'ttl', 'owner')
			->from(self::TABLE_LOCKS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

		return $this->getLockFromRequest($qb->executeQuery());
	}

	/**
	 * @param list<int> $fileIds
	 *
	 * @return list<FileLock>
	 * @throws LockNotFoundException
	 */
	public function getFromFileIds(array $fileIds): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user_id', 'file_id', 'token', 'creation', 'type', 'ttl', 'owner')
			->from(self::TABLE_LOCKS)
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->getLocksFromRequest($qb->executeQuery());
	}

	/**
	 * @return list<FileLock>
	 */
	public function getAll(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user_id', 'file_id', 'token', 'creation', 'type', 'ttl', 'owner')
			->from(self::TABLE_LOCKS);

		return $this->getLocksFromRequest($qb->executeQuery());
	}

	/**
	 * @param int $timeout in minutes
	 * @param int $limit how many locks to retrieve (0 for all, default)
	 *
	 * @return list<FileLock>
	 * @throws Exception
	 */
	public function getLocksOlderThan(int $timeout, int $limit = 0): array {
		$now = Server::get(ITimeFactory::class)->getTime();
		$oldCreationTime = $now - $timeout * 60;
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user_id', 'file_id', 'token', 'creation', 'type', 'ttl', 'owner')
			->from(self::TABLE_LOCKS)
			->andWhere($qb->expr()->lt('creation', $qb->createNamedParameter($oldCreationTime, IQueryBuilder::PARAM_INT)));

		if ($limit !== 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getLocksFromRequest($qb->executeQuery());
	}

	/**
	 * @throws LockNotFoundException
	 */
	protected function getLockFromRequest(IResult $result): FileLock {
		$row = $result->fetch();
		if ($row === false) {
			throw new LockNotFoundException('Lock not found');
		}

		return $this->parseLockSelectSql($row);
	}

	/**
	 * @return list<FileLock>
	 */
	public function getLocksFromRequest(IResult $result): array {
		$locks = [];
		while ($row = $result->fetch()) {
			$locks[] = $this->parseLockSelectSql($row);
		}
		return $locks;
	}

	public function parseLockSelectSql(array $data): FileLock {
		$lock = new FileLock($this->timeout);
		$lock->importFromDatabase($data);

		return $lock;
	}
}
