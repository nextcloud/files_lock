<?php
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\Constants;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\NoLockProviderException;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;

class LockWrapper extends Wrapper {
	private ILockManager $lockManager;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var IUserSession */
	private $userSession;


	/**
	 * LockWrapper constructor.
	 *
	 * @param $arguments
	 */
	public function __construct($arguments) {
		parent::__construct($arguments);

		$this->lockManager = $arguments['lock_manager'];
		$this->userSession = $arguments['user_session'];
		$this->fileService = $arguments['file_service'];
		$this->lockService = $arguments['lock_service'];
	}


	/**
	 * @param $path
	 * @param $permissions
	 *
	 * @return bool
	 * @throws LockedException
	 */
	protected function checkPermissions($path, $permissions): bool {
		$viewerId = '';
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$viewerId = $user->getUID();
			$ownerId = $viewerId;
		} else {
			$ownerId = $this->getOwner($path);
		}

		/** @var FileLock $lock */
		if (!$this->isLocked($ownerId, $path, $viewerId, $lock)) {
			return true;
		}

		switch ($permissions) {
			case Constants::PERMISSION_READ:
				return true;

			case Constants::PERMISSION_DELETE:
			case Constants::PERMISSION_UPDATE:
				throw new ManuallyLockedException(
					$path, null, $lock->getToken(), $lock->getOwner(), $lock->getETA()
				);

			default:
				return false;
		}
	}


	/**
	 * @param string $ownerId
	 * @param string $path
	 * @param string $viewerId
	 * @param FileLock $lock
	 *
	 * @return bool
	 */
	protected function isLocked(string $ownerId, string $path, string $viewerId, &$lock = null): bool {
		try {
			$file = $this->fileService->getFileFromPath($ownerId, $path);
		} catch (NotFoundException $e) {
			return false;
		}

		try {
			// FIXME: too hacky - might be an issue if we start locking folders.
			if ($file->getId() === null) {
				return false;
			}

			$lock = $this->lockService->getLockFromFileId($file->getId());

			if ($lock->getType() === ILock::TYPE_USER && $lock->getOwner() !== $viewerId) {
				return true;
			}

			if ($lock->getType() === ILock::TYPE_APP) {
				$lockScope = $this->lockManager->getLockInScope();
				if (!$lockScope || $lockScope->getType() !== $lock->getType() || $lockScope->getOwner() !== $lock->getOwner()) {
					return true;
				}
			}
		} catch (NoLockProviderException|LockNotFoundException|InvalidPathException|NotFoundException $e) {
		}

		return false;
	}


	public function rename($path1, $path2): bool {
		if (strpos($path1, $path2) === 0) {
			$part = substr($path1, strlen($path2));
			//This is a rename of the transfer file to the original file
			if (strpos($part, '.ocTransferId') === 0) {
				return $this->checkPermissions($path2, Constants::PERMISSION_CREATE)
					   && parent::rename($path1, $path2);
			}
		}
		$permissions =
			$this->file_exists($path2) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;
		$sourceParent = dirname($path1);
		if ($sourceParent === '.') {
			$sourceParent = '';
		}

		return $this->checkPermissions($sourceParent, Constants::PERMISSION_DELETE)
			   && $this->checkPermissions($path1, Constants::PERMISSION_UPDATE & Constants::PERMISSION_READ)
			   && $this->checkPermissions($path2, $permissions)
			   && parent::rename($path1, $path2);
	}

	public function copy($path1, $path2): bool {
		$permissions =
			$this->file_exists($path2) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path2, $permissions)
			   && $this->checkPermissions(
			   	$path1, Constants::PERMISSION_READ
			   )
			   && parent::copy($path1, $path2);
	}

	public function touch($path, $mtime = null): bool {
		$permissions =
			$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) && parent::touch($path, $mtime);
	}

	public function mkdir($path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_CREATE) && parent::mkdir($path);
	}

	public function rmdir($path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			   && parent::rmdir($path);
	}

	public function unlink($path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			   && parent::unlink($path);
	}

	public function file_put_contents($path, $data): int|float|false {
		$permissions =
			$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) ? parent::file_put_contents($path, $data) : false;
	}

	public function fopen($path, $mode) {
		if ($mode === 'r' or $mode === 'rb') {
			$permissions = Constants::PERMISSION_READ;
		} else {
			$permissions =
				$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;
		}

		return $this->checkPermissions($path, $permissions) ? parent::fopen($path, $mode) : false;
	}

	public function writeStream(string $path, $stream, ?int $size = null): int {
		$permissions =
			$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) ? parent::writeStream($path, $stream, $size) : 0;
	}

	public function file_get_contents($path): string|false {
		if (!$this->checkPermissions($path, Constants::PERMISSION_READ)) {
			return false;
		}

		return parent::file_get_contents($path);
	}
}
