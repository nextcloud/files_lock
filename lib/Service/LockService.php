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


namespace OCA\FilesLock\Service;


use daita\MySmallPhpTools\Traits\TStringTools;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\AlreadyLockedException;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCP\Files\Node;


/**
 * Class LockService
 *
 * @package OCA\FilesLock\Service
 */
class LockService {


	const PREFIX = 'files_lock';


	use TStringTools;


	/** @var LocksRequest */
	private $locksRequest;

	/** @var MiscService */
	private $miscService;


	public function __construct(LocksRequest $locksRequest, MiscService $miscService) {
		$this->locksRequest = $locksRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $userId
	 * @param int $fileId
	 *
	 * @return FileLock
	 * @throws AlreadyLockedException
	 */
	public function lockFile(string $userId, int $fileId): FileLock {
		$lock = new FileLock();
		$lock->setUserId($userId);
		$lock->setFileId($fileId);

		$this->lock($lock);

		return $lock;
	}


	/**
	 * @param FileLock $lock
	 *
	 * @throws AlreadyLockedException
	 */
	public function lock(FileLock $lock) {
		$this->generateToken($lock);
		try {
			$known = $this->locksRequest->getFromFileId($lock->getFileId());

			throw new AlreadyLockedException('File is already locked by ' . $known->getUserId());
		} catch (LockNotFoundException $e) {
			$this->locksRequest->save($lock);
		}
	}


	/**
	 * @param Node $file
	 */
	public function unlock(Node $file) {
	}


	/**
	 * @param int $fileId
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	public function getLocksFromFileId(int $fileId): FileLock {
		return $this->locksRequest->getFromFileId($fileId);
	}


	/**
	 * @param FileLock $lock
	 */
	public function generateToken(FileLock $lock) {
		if ($lock->getToken() !== '') {
			return;
		}

		$lock->setToken(self::PREFIX . '-' . $this->uuid());
	}


}

