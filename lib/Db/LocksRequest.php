<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
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
	public function save(FileLock $lock) {
		$qb = $this->getLocksInsertSql();
		$qb->setValue('user_id', $qb->createNamedParameter($lock->getOwner()))
			->setValue('file_id', $qb->createNamedParameter($lock->getFileId()))
			->setValue('token', $qb->createNamedParameter($lock->getToken()))
			->setValue('creation', $qb->createNamedParameter($lock->getCreatedAt()))
			->setValue('type', $qb->createNamedParameter($lock->getType()))
			->setValue('ttl', $qb->createNamedParameter($lock->getTimeout()));

		try {
			$qb->execute();
			$lock->setId($qb->getLastInsertId());
		} catch (UniqueConstraintViolationException $e) {
		}
	}

	public function update(FileLock $lock) {
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

		$qb->execute();
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

		$qb->execute();
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
	 *
	 * @return FileLock[]
	 * @throws Exception
	 */
	public function getLocksOlderThan(int $timeout): array {
		$qb = $this->getLocksSelectSql();
		$qb->andWhere($qb->expr()->lt('l.creation', $qb->createNamedParameter($timeout * 60, IQueryBuilder::PARAM_INT)));

		return $this->getLocksFromRequest($qb);
	}
}
