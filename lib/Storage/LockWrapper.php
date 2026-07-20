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
use OCP\Files\Storage\IStorage;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;

class LockWrapper extends Wrapper {
	private readonly ILockManager $lockManager;

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
	public function __construct(array $arguments) {
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
		if (!$this->isPathLocked($ownerId, $path, $viewerId, $lock)) {
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

	protected function isPathLocked(string $ownerId, string $path, string $viewerId, ?FileLock &$lock = null): bool {
		try {
			$file = $this->fileService->getFileFromPath($ownerId, $path);
		} catch (NotFoundException) {
			return false;
		}

		if ($file->getId() === null) {
			return false;
		}

		return $this->isFileLocked($file->getId(), $viewerId, $lock);
	}

	protected function isFileLocked(int $fileId, string $viewerId, ?FileLock &$lock = null): bool {
		try {
			$lock = $this->lockService->getLockFromFileId($fileId);
			if ($lock->getType() === ILock::TYPE_USER && $lock->getOwner() !== $viewerId) {
				return true;
			}

			if ($lock->getType() === ILock::TYPE_APP) {
				$lockScope = $this->lockManager->getLockInScope();
				if (!$lockScope || $lockScope->getType() !== $lock->getType() || $lockScope->getOwner() !== $lock->getOwner()) {
					return true;
				}
			}
		} catch (NoLockProviderException|LockNotFoundException|InvalidPathException|NotFoundException) {
		}

		return false;
	}

	#[\Override]
	public function rename(string $source, string $target): bool {
		if (str_starts_with($source, $target)) {
			$part = substr($source, strlen($target));
			//This is a rename of the transfer file to the original file
			if (str_starts_with($part, '.ocTransferId')) {
				return $this->checkPermissions($target, Constants::PERMISSION_CREATE)
					&& parent::rename($source, $target);
			}
		}
		$permissions
			= $this->file_exists($target) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;
		$sourceParent = dirname($source);
		if ($sourceParent === '.') {
			$sourceParent = '';
		}

		return $this->checkPermissions($sourceParent, Constants::PERMISSION_DELETE)
			&& $this->checkPermissions($source, Constants::PERMISSION_UPDATE & Constants::PERMISSION_READ)
			&& $this->checkPermissions($target, $permissions)
			&& parent::rename($source, $target);
	}

	#[\Override]
	public function copy(string $source, string $target): bool {
		$permissions = $this->file_exists($target) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($target, $permissions)
			&& $this->checkPermissions(
				$source, Constants::PERMISSION_READ
			)
			&& parent::copy($source, $target);
	}

	#[\Override]
	public function copyFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
		$cache = $sourceStorage->getCache();
		$fileId = $cache->getId($sourceInternalPath);

		$user = $this->userSession->getUser();
		if ($fileId > 0 && $this->isFileLocked($fileId, $user?->getUID() ?? '', $lock)) {
			throw new ManuallyLockedException($sourceInternalPath, null, $lock->getToken(), $lock->getOwner(), $lock->getETA());
		}

		return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
	}

	#[\Override]
	public function touch(string $path, ?int $mtime = null): bool {
		$permissions
			= $this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) && parent::touch($path, $mtime);
	}

	#[\Override]
	public function mkdir(string $path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_CREATE) && parent::mkdir($path);
	}

	#[\Override]
	public function rmdir(string $path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			&& parent::rmdir($path);
	}

	#[\Override]
	public function unlink(string $path): bool {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			&& parent::unlink($path);
	}

	#[\Override]
	public function file_put_contents(string $path, mixed $data): int|float|false {
		$permissions
			= $this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) ? parent::file_put_contents($path, $data) : false;
	}

	#[\Override]
	public function fopen(string $path, string $mode) {
		if ($mode === 'r' or $mode === 'rb') {
			$permissions = Constants::PERMISSION_READ;
		} else {
			$permissions
				= $this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;
		}

		return $this->checkPermissions($path, $permissions) ? parent::fopen($path, $mode) : false;
	}

	#[\Override]
	public function writeStream(string $path, $stream, ?int $size = null): int {
		$permissions
			= $this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) ? parent::writeStream($path, $stream, $size) : 0;
	}

	#[\Override]
	public function file_get_contents(string $path): string|false {
		if (!$this->checkPermissions($path, Constants::PERMISSION_READ)) {
			return false;
		}

		return parent::file_get_contents($path);
	}
}
