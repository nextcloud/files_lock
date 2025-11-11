<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class LocksRequest
 *
 * @package OCA\FilesLock\Db
 */
class LocksRequest extends LocksRequestBuilder {
	/**
	 * @param FileLock $lock
	 */
	public function save(FileLock $lock): void {
		$qb = $this->getLocksInsertSql();
		$qb->setValue('user_id', $qb->createNamedParameter($lock->getOwner()))
			->setValue('file_id', $qb->createNamedParameter($lock->getFileId()))
			->setValue('token', $qb->createNamedParameter($lock->getToken()))
			->setValue('creation', $qb->createNamedParameter($lock->getCreatedAt()))
			->setValue('type', $qb->createNamedParameter($lock->getType()))
			->setValue('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->setValue('owner', $qb->createNamedParameter($lock->getDisplayName()));

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
		$qb = $this->getLocksUpdateSql();
		$qb->set('token', $qb->createNamedParameter($lock->getToken()))
			->set('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->set('user_id', $qb->createNamedParameter($lock->getOwner()))
			->set('owner', $qb->createNamedParameter($lock->getDisplayName()))
			->set('scope', $qb->createNamedParameter($lock->getScope()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lock->getId())));

		$qb->executeStatement();
	}


	/**
	 * @param FileLock $lock
	 */
	public function delete(FileLock $lock) {
		$qb = $this->getLocksDeleteSql();
		$qb->limitToId($lock->getId());

		$qb->executeStatement();
	}


	/**
	 * @param int[] $ids
	 */
	public function removeIds(array $ids) {
		if (empty($ids)) {
			return;
		}

		$qb = $this->getLocksDeleteSql();
		$qb->limitToIds($ids);

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
		$qb->limitToFileId($fileId);

		return $this->getLockFromRequest($qb);
	}

	/**
	 * @param list<int> $fileIds
	 *
	 * @return list<FileLock>
	 * @throws LockNotFoundException
	 */
	public function getFromFileIds(array $fileIds): array {
		$qb = $this->getLocksSelectSql();
		$qb->limitToFileIds($fileIds);

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
	 * @param int $limit how many locks to retrieve (0 for all, default)
	 *
	 * @return FileLock[]
	 * @throws Exception
	 */
	public function getLocksOlderThan(int $timeout, int $limit = 0): array {
		$now = \OC::$server->get(ITimeFactory::class)->getTime();
		$oldCreationTime = $now - $timeout * 60;
		$qb = $this->getLocksSelectSql();
		$qb->andWhere($qb->expr()->lt('l.creation', $qb->createNamedParameter($oldCreationTime, IQueryBuilder::PARAM_INT)));

		if ($limit !== 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getLocksFromRequest($qb);
	}
}
