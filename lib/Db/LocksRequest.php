<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use Exception;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LocksRequest {
	public const TABLE_LOCKS = 'files_lock';
	private static $tables = [
		self::TABLE_LOCKS,
	];

	public function __construct(
		private IDBConnection $connection,
		private ITimeFactory $timeFactory,
		private ConfigService $configService,
	) {
	}



	public function save(FileLock $lock) {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE_LOCKS)
			->setValue('user_id', $qb->createNamedParameter($lock->getOwner()))
			->setValue('file_id', $qb->createNamedParameter($lock->getFileId()))
			->setValue('token', $qb->createNamedParameter($lock->getToken()))
			->setValue('creation', $qb->createNamedParameter($lock->getCreatedAt()))
			->setValue('type', $qb->createNamedParameter($lock->getType()))
			->setValue('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->setValue('owner', $qb->createNamedParameter($lock->getDisplayName()));

		$qb->executeStatement();
		$lock->setId($qb->getLastInsertId());
	}

	public function update(FileLock $lock) {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE_LOCKS)
			->set('token', $qb->createNamedParameter($lock->getToken()))
			->set('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->set('user_id', $qb->createNamedParameter($lock->getOwner()))
			->set('owner', $qb->createNamedParameter($lock->getDisplayName()))
			->set('scope', $qb->createNamedParameter($lock->getScope()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId())));

		$qb->executeStatement();
	}

	public function delete(FileLock $lock) {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId())));
		$qb->executeStatement();
	}


	/**
	 * @param int[] $ids
	 */
	public function removeIds(array $ids) {
		if (empty($ids)) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids)));
		$qb->executeStatement();
	}


	/**
	 * @param int $fileId
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	public function getFromFileId(int $fileId): FileLock {
		$qb = $this->getLocksSelectSql();
		$qb
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

		return $this->getLockFromRequest($qb);
	}

	/**
	 * @param list<int> $fileIds
	 *
	 * @return list<FileLock>
	 */
	public function getFromFileIds(array $fileIds): array {
		$qb = $this->getLocksSelectSql();
		$qb->where($qb->expr()->in('id', $qb->createNamedParameter($fileIds)));

		return $this->getLocksFromRequest($qb);
	}


	/**
	 * @return FileLock[]
	 */
	public function getAll(): array {
		$qb = $this->getLocksSelectSql();

		return $this->getLocksFromRequest($qb);
	}


	/**
	 * @param int $timeout in minutes
	 *
	 * @return FileLock[]
	 * @throws Exception
	 */
	public function getLocksOlderThan(int $timeout): array {
		$now = $this->timeFactory->getTime();
		$oldCreationTime = $now - $timeout * 60;
		$qb = $this->getLocksSelectSql();
		$qb->where($qb->expr()->lt('l.creation', $qb->createNamedParameter($oldCreationTime, IQueryBuilder::PARAM_INT)));

		$a = $this->getLocksFromRequest($qb);
		var_dump($a);

		return $a;
	}

	protected function getLocksSelectSql(): IQueryBuilder {
		$qb = $this->connection->getQueryBuilder();

		$qb->select('l.id', 'l.user_id', 'l.file_id', 'l.token', 'l.creation', 'l.type', 'l.ttl', 'l.owner')
			->from(self::TABLE_LOCKS, 'l');

		return $qb;
	}

	protected function getLockFromRequest(IQueryBuilder $qb): FileLock {
		try {
			$cursor = $qb->executeQuery();
			$data = $cursor->fetch();
			$cursor->closeCursor();

			if ($data === false) {
				throw new LockNotFoundException();
			}
			$result = $this->parseLockSelectSql($data);
		} catch (Exception $e) {
			throw new LockNotFoundException($e->getMessage());
		}

		return $result;
	}

	public function getLocksFromRequest(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			try {
				$rows[] = $this->parseLockSelectSql($data);
			} catch (Exception) {
			}
		}
		$cursor->closeCursor();

		return $rows;
	}

	public function parseLockSelectSql(array $data): FileLock {
		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->importFromDatabase($data);

		return $lock;
	}
}
