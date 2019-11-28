<?php
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
 *
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

namespace OCA\FilesLock\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OC\User\NoUserException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\LockService;
use OCP\Constants;
use OCP\Files\InvalidPathException;
use OCP\IUserSession;
use OCP\Lock\LockedException;

class LockWrapper extends Wrapper {
	/** @var LockService */
	private $lockService;
	/** @var IUserSession */
	private $userSession;

	public function __construct($arguments) {
		parent::__construct($arguments);

		$this->lockService = $arguments['lock_service'];
		$this->userSession = $arguments['user_session'];
	}


	/**
	 * @param $path
	 * @param $permissions
	 *
	 * @return bool
	 * @throws LockedException
	 */
	protected function checkPermissions($path, $permissions): bool {
		try {
			$userId = $this->getViewer($path);
		} catch (NoUserException $e) {
			return true;
		}

		/** @var FileLock $lock */
		if (!$this->isLocked($path, $userId, $lock)) {
			return true;
		}

		switch ($permissions) {
			case Constants::PERMISSION_DELETE:
			case Constants::PERMISSION_UPDATE:
				// NC18 - TemporaryLockedException() - switch to $lock->getETA()
				// throw new LockedException($path, null, $lock->getToken(), $lock->getOwner(), $lock->getTimeout());
				throw new LockedException($path);

			default:
				return false;
		}

	}


	/**
	 * @param string $path
	 *
	 * @return string
	 * @throws NoUserException
	 */
	protected function getViewer(string $path): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		} else {
			$userId = $this->getOwner($path);
		}

		if ($userId === false || $userId === '') {
			throw new NoUserException();
		}

		return $userId;
	}


	/**
	 * @param $path
	 * @param FileLock $lock
	 *
	 * @return bool
	 */
	protected function isLocked(string $path, string $userId, &$lock = null): bool {
		try {
			return $this->lockService->isPathLocked($path, $userId, $lock);
		} catch (InvalidPathException $e) {
		}

		return false;
	}


	public function rename($path1, $path2) {
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

	public function copy($path1, $path2) {
		$permissions =
			$this->file_exists($path2) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path2, $permissions)
			   && $this->checkPermissions(
				$path1, Constants::PERMISSION_READ
			)
			   && parent::copy($path1, $path2);
	}

	public function touch($path, $mtime = null) {
		$permissions =
			$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) && parent::touch($path, $mtime);
	}

	public function mkdir($path) {
		return $this->checkPermissions($path, Constants::PERMISSION_CREATE) && parent::mkdir($path);
	}

	public function rmdir($path) {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			   && parent::rmdir($path);
	}

	public function unlink($path) {
		return $this->checkPermissions($path, Constants::PERMISSION_DELETE)
			   && parent::unlink($path);
	}

	public function file_put_contents($path, $data) {
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

	public function writeStream(string $path, $stream, int $size = null): int {
		$permissions =
			$this->file_exists($path) ? Constants::PERMISSION_UPDATE : Constants::PERMISSION_CREATE;

		return $this->checkPermissions($path, $permissions) ? parent::writeStream($path, $stream, $size) : 0;
	}

	public function file_get_contents($path) {
		if (!$this->checkPermissions($path, Constants::PERMISSION_READ)) {
			return false;
		}

		return parent::file_get_contents($path);
	}

}
