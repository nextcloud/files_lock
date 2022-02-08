<?php declare(strict_types=1);


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
		$qb->setValue('user_id', $qb->createNamedParameter($lock->getUserId()))
		   ->setValue('file_id', $qb->createNamedParameter($lock->getFileId()))
		   ->setValue('token', $qb->createNamedParameter($lock->getToken()))
		   ->setValue('creation', $qb->createNamedParameter($lock->getCreation()))
		   ->setValue('type', $qb->createNamedParameter($lock->getLockType()));

		try {
			$qb->execute();
		} catch (UniqueConstraintViolationException $e) {
		}

		$lock->setCreation(time());
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
	 * @return FileLock[]
	 */
	public function getAll(): array {
		$qb = $this->getLocksSelectSql();

		return $this->getLocksFromRequest($qb);
	}


	/**
	 * @param int $timeout
	 *
	 * @return FileLock[]
	 * @throws Exception
	 */
	public function getLocksOlderThan(int $timeout): array {
		$qb = $this->getLocksSelectSql();
		$qb->limitToCreation($timeout);

		return $this->getLocksFromRequest($qb);
	}


}

