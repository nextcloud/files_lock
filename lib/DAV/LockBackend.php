<?php

declare(strict_types=1);


/**
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\FilesLock\DAV;

use Exception;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\NotFoundException;
use Sabre\DAV\Locks\Backend\BackendInterface;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Server;

class LockBackend implements BackendInterface {

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var bool */
	private $absolute = false;

	public function __construct(
		Server $server, FileService $fileService, LockService $lockService, bool $absolute
	) {
		$this->server = $server;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->absolute = $absolute;
	}


	/**
	 * @param string $uri
	 * @param bool $returnChildLocks
	 *
	 * @return LockInfo[]
	 */
	public function getLocks($uri, $returnChildLocks): array {
		$locks = [];
		try {
			// TODO: check parent
			$file = $this->getFileFromUri($uri);
			$lock = $this->lockService->getLockFromFileId($file->getId());

			$userLock = $this->server->httpRequest->getHeader('X-User-Lock');
			if ($userLock && $lock->getType() === ILock::TYPE_USER && $lock->getOwner() === \OC::$server->getUserSession()->getUser()->getUID()) {
				return [];
			}

			return [$lock->toLockInfo()];
		} catch (Exception $e) {
			return $locks;
		}
	}


	/**
	 * Locks a uri
	 *
	 * @param string $uri
	 * @param LockInfo $lockInfo
	 *
	 * @return bool
	 */
	public function lock($uri, LockInfo $lockInfo): bool {
		try {
			$file = $this->getFileFromUri($uri);
			$lock = $this->lockService->lock(new LockContext(
				$file,
				ILock::TYPE_TOKEN,
				$lockInfo->token
			));
			$lock->setUserId(\OC::$server->getUserSession()->getUser()->getUID());
			$lock->setTimeout($lockInfo->timeout ?? 0);
			$lock->setToken($lockInfo->token);
			$lock->setDisplayName($lockInfo->owner);
			$lock->setScope($lockInfo->scope);
			$this->lockService->update($lock);
			return true;
		} catch (NotFoundException $e) {
			return true;
		} catch (OwnerLockedException $e) {
			return false;
		}
	}


	/**
	 * Removes a lock from a uri
	 *
	 * @param string $uri
	 * @param LockInfo $lockInfo
	 *
	 * @return bool
	 */
	public function unlock($uri, LockInfo $lockInfo): bool {
		try {
			$file = $this->getFileFromUri($uri);
		} catch (NotFoundException $e) {
			return true;
		}
		$this->lockService->unlock(new LockContext(
			$file,
			ILock::TYPE_TOKEN,
			$lockInfo->token
		));
		return true;
	}

	/**
	 * @throws NotFoundException
	 */
	private function getFileFromUri(string $uri) {
		if ($this->absolute) {
			return $this->fileService->getFileFromAbsoluteUri($uri);
		}

		return $this->fileService->getFileFromUri($uri);
	}
}
