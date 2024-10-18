<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\FilesLock\DAV;

use Exception;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Sabre\DAV\Locks\Backend\BackendInterface;
use Sabre\DAV\Locks\LockInfo;

class LockBackend implements BackendInterface {
	public function __construct(
		private FileService $fileService,
		private LockService $lockService,
		private bool $absolute,
	) {
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

			if ($lock->getType() === ILock::TYPE_USER && $lock->getOwner() === \OC::$server->getUserSession()->getUser()->getUID()) {
				return [];
			}

			$lock->setUri($uri);

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
	private function getFileFromUri(string $uri): Node {
		if ($this->absolute) {
			return $this->fileService->getFileFromAbsoluteUri($uri);
		}

		return $this->fileService->getFileFromUri($uri);
	}
}
