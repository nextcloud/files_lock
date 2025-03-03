<?php

declare(strict_types=1);


/**
 * Files_Lock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FilesLock\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCP\AppFramework\Utility\ITimeFactory;
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
			->setValue('ttl', $qb->createNamedParameter($lock->getTimeout()))
			->setValue('owner', $qb->createNamedParameter($lock->getDisplayName()));

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
		$now = \OC::$server->get(ITimeFactory::class)->getTime();
		$oldCreationTime = $now - $timeout * 60;
		$qb = $this->getLocksSelectSql();
		$qb->andWhere($qb->expr()->lt('l.creation', $qb->createNamedParameter($oldCreationTime, IQueryBuilder::PARAM_INT)));

		return $this->getLocksFromRequest($qb);
	}
}
