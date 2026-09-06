<?php

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\LockService;
use OCP\Constants;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Storage\IStorage;
use OCP\Lock\LockedException;
use OCP\Lock\ManuallyLockedException;

/**
 * Enforces file locks for every write that reaches a storage, whatever mount the
 * storage is attached to. Files are identified through the wrapped storage's own
 * cache, so the check does not depend on the shape of the storage path.
 */
class LockWrapper extends Wrapper {
	private readonly ILockManager $lockManager;

	/** @var LockService */
	private $lockService;

	/**
	 * LockWrapper constructor.
	 *
	 * @param $arguments
	 */
	public function __construct(array $arguments) {
		parent::__construct($arguments);

		$this->lockManager = $arguments['lock_manager'];
		$this->lockService = $arguments['lock_service'];
	}

	/**
	 * @param $path
	 * @param $permissions
	 *
	 * @throws LockedException
	 */
	protected function checkPermissions($path, $permissions): bool {
		if ($permissions === Constants::PERMISSION_READ) {
			return true;
		}

		$fileId = $this->getCache()->getId($path);
		if ($fileId === -1) {
			return true;
		}

		$lock = $this->lockService->getActiveLock($fileId);
		if ($lock === null || $this->lockService->canWrite($lock, $this->lockManager->getLockInScope())) {
			return true;
		}

		throw new ManuallyLockedException(
			$path, null, $lock->getToken(), $lock->getOwner(), $lock->getETA()
		);
	}

	/**
	 * Refuse an operation on a directory that would delete or relocate a locked
	 * descendant the current user may not write.
	 *
	 * @throws LockedException
	 */
	protected function checkDescendants(IStorage $storage, string $path): void {
		if (!$storage->is_dir($path)) {
			return;
		}
		$folderId = $storage->getCache()->getId($path);
		if ($folderId === -1) {
			return;
		}

		$blocking = $this->lockService->getBlockingLocksBelow($folderId, $this->lockManager->getLockInScope());
		if ($blocking === []) {
			return;
		}
		/** @var FileLock $lock */
		$lock = $blocking[0]['lock'];
		throw new ManuallyLockedException(
			rtrim($path, '/') . '/' . $blocking[0]['path'], null, $lock->getToken(), $lock->getOwner(), $lock->getETA()
		);
	}

	#[\Override]
	public function rename(string $source, string $target): bool {
		if (str_starts_with($source, $target)) {
			$part = substr($source, strlen($target));
			//This is a rename of the transfer file to the original file
			if (str_starts_with($part, '.ocTransferId')) {
				return $this->checkPermissions($target, Constants::PERMISSION_UPDATE)
					&& parent::rename($source, $target);
			}
		}
		$permissions
			= $this->file_exists($target) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		$this->checkDescendants($this, $source);

		return $this->checkPermissions($source, Constants::PERMISSION_UPDATE)
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
		$fileId = $sourceStorage->getCache()->getId($sourceInternalPath);
		if ($fileId > 0) {
			$lock = $this->lockService->getActiveLock($fileId);
			if ($lock !== null && !$this->lockService->canWrite($lock, $this->lockManager->getLockInScope())) {
				throw new ManuallyLockedException($sourceInternalPath, null, $lock->getToken(), $lock->getOwner(), $lock->getETA());
			}
		}

		return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
	}

	#[\Override]
	public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
		$this->checkDescendants($sourceStorage, $sourceInternalPath);
		$fileId = $sourceStorage->getCache()->getId($sourceInternalPath);
		if ($fileId > 0) {
			$lock = $this->lockService->getActiveLock($fileId);
			if ($lock !== null && !$this->lockService->canWrite($lock, $this->lockManager->getLockInScope())) {
				throw new ManuallyLockedException($sourceInternalPath, null, $lock->getToken(), $lock->getOwner(), $lock->getETA());
			}
		}
		$permissions = $this->file_exists($targetInternalPath) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($targetInternalPath, $permissions)
			&& parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
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
		$this->checkDescendants($this, $path);
		$this->checkPermissions($path, Constants::PERMISSION_DELETE);

		$folderId = $this->getCache()->getId($path);
		$lockedIds = $folderId > 0
			? array_map(fn (array $entry): int => $entry['lock']->getFileId(), $this->lockService->getLocksBelow($folderId))
			: [];

		$result = parent::rmdir($path);
		if ($result && $lockedIds !== []) {
			$this->lockService->removeLocksForFileIds($lockedIds);
		}
		return $result;
	}

	#[\Override]
	public function unlink(string $path): bool {
		$this->checkPermissions($path, Constants::PERMISSION_DELETE);
		$fileId = $this->getCache()->getId($path);

		$result = parent::unlink($path);
		if ($result && $fileId > 0) {
			$this->lockService->removeLocksForFileIds([$fileId]);
		}
		return $result;
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
