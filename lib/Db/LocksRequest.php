<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use OCA\FilesLock\Cron\Unlock;
use OCA\FilesLock\Exceptions\LockConflictException;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class LocksRequest
 *
 * @package OCA\FilesLock\Db
 */
class LocksRequest {
	public const string TABLE_LOCKS = 'files_lock';
	private const array COLUMNS = ['id', 'user_id', 'file_id', 'token', 'creation', 'type', 'ttl', 'owner', 'scope', 'expires_at'];

	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	/**
	 * Insert a new lock. The unique index on file_id guarantees at most one row per
	 * file; a violation is reported as LockConflictException so the caller can
	 * re-read the winning lock.
	 *
	 * @throws LockConflictException
	 * @throws Exception
	 */
	public function save(FileLock $lock): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(self::TABLE_LOCKS);
		$qb->setValue('user_id', $qb->createNamedParameter($lock->getOwner()))
			->setValue('file_id', $qb->createNamedParameter($lock->getFileId(), IQueryBuilder::PARAM_INT))
			->setValue('token', $qb->createNamedParameter($lock->getToken()))
			->setValue('creation', $qb->createNamedParameter($lock->getCreatedAt(), IQueryBuilder::PARAM_INT))
			->setValue('type', $qb->createNamedParameter($lock->getType(), IQueryBuilder::PARAM_INT))
			->setValue('ttl', $qb->createNamedParameter(max(0, $lock->getTimeout()), IQueryBuilder::PARAM_INT))
			->setValue('owner', $qb->createNamedParameter($lock->getDisplayName() ?? ''))
			->setValue('scope', $qb->createNamedParameter($lock->getScope(), IQueryBuilder::PARAM_INT))
			->setValue('expires_at', $qb->createNamedParameter($lock->getExpiresAt(), IQueryBuilder::PARAM_INT));

		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new LockConflictException('A lock already exists for file ' . $lock->getFileId(), 0, $e);
			}
			throw $e;
		}
		$lock->setId($qb->getLastInsertId());
	}

	public function update(FileLock $lock): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE_LOCKS);
		$qb->set('token', $qb->createNamedParameter($lock->getToken()))
			->set('ttl', $qb->createNamedParameter(max(0, $lock->getTimeout()), IQueryBuilder::PARAM_INT))
			->set('expires_at', $qb->createNamedParameter($lock->getExpiresAt(), IQueryBuilder::PARAM_INT))
			->set('user_id', $qb->createNamedParameter($lock->getOwner()))
			->set('owner', $qb->createNamedParameter($lock->getDisplayName() ?? ''))
			->set('scope', $qb->createNamedParameter($lock->getScope(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId(), IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function delete(FileLock $lock): void {
		$this->removeIds([$lock->getId()]);
	}

	/**
	 * @param int[] $ids
	 */
	public function removeIds(array $ids): void {
		foreach (array_chunk($ids, IQueryBuilder::MAX_IN_PARAMETERS) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete(self::TABLE_LOCKS)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$qb->executeStatement();
		}
	}

	/**
	 * Remove the given locks, but only those that are still expired at $now.
	 *
	 * The cleanup paths read a batch of expired locks and delete it afterwards;
	 * in between, the owner may have refreshed one of them, which reuses the same
	 * row. Deleting by id alone would drop a lock that is valid again by then.
	 *
	 * @param int[] $ids
	 *
	 * @return int number of rows removed
	 */
	public function removeExpiredIds(array $ids, int $now): int {
		$removed = 0;
		foreach (array_chunk($ids, IQueryBuilder::MAX_IN_PARAMETERS) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete(self::TABLE_LOCKS)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNotNull('expires_at'))
				->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

			$removed += $qb->executeStatement();
		}

		return $removed;
	}

	/**
	 * @param list<int> $fileIds
	 */
	public function removeByFileIds(array $fileIds): void {
		foreach (array_chunk($fileIds, IQueryBuilder::MAX_IN_PARAMETERS) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete(self::TABLE_LOCKS)
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$qb->executeStatement();
		}
	}

	/**
	 * Remove the lock of a file if it has expired at $now.
	 *
	 * @return bool whether a row was removed
	 */
	public function removeExpired(int $fileId, int $now): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * @throws LockNotFoundException
	 */
	public function getFromFileId(int $fileId): FileLock {
		$qb = $this->connection->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_LOCKS)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $this->getLockFromRequest($qb->executeQuery());
	}

	/**
	 * @param list<int> $fileIds
	 *
	 * @return list<FileLock>
	 */
	public function getFromFileIds(array $fileIds): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_LOCKS)
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->getLocksFromRequest($qb->executeQuery());
	}

	/**
	 * @return list<FileLock>
	 */
	public function getAll(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_LOCKS);

		return $this->getLocksFromRequest($qb->executeQuery());
	}

	/**
	 * Locks whose expiry lies at or before $now.
	 *
	 * @param int $limit how many locks to retrieve (0 for all, default)
	 *
	 * @return list<FileLock>
	 * @throws Exception
	 */
	public function getExpired(int $now, int $limit = 0): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from(self::TABLE_LOCKS)
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

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
		$result->closeCursor();
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
		$result->closeCursor();
		return $locks;
	}

	public function parseLockSelectSql(array $data): FileLock {
		$lock = new FileLock();
		$lock->importFromDatabase($data);

		return $lock;
	}

	public function uninstall(): void {
		$this->connection->dropTable(self::TABLE_LOCKS);
		$this->removeFromJobs();
		$this->removeFromMigrations();
	}

	public function removeFromMigrations(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('migrations');
		$qb->where($qb->expr()->eq('app', $qb->createNamedParameter('files_lock')));
		$qb->executeStatement();
	}

	public function removeFromJobs(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($qb->expr()->eq('class', $qb->createNamedParameter(Unlock::class)));
		$qb->executeStatement();
	}
}
