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


use daita\MySmallPhpTools\Traits\Nextcloud\nc20\TNC20Logger;
use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use OCA\DAV\Connector\Sabre\Node as SabreNode;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\AlreadyLockedException;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;


/**
 * Class LockService
 *
 * @package OCA\FilesLock\Service
 */
class LockService {


	const PREFIX = 'files_lock';


	use TStringTools;
	use TNC20Logger;


	/** @var string */
	private $userId;

	/** @var IUserManager */
	private $userManager;

	/** @var LocksRequest */
	private $locksRequest;

	/** @var FileService */
	private $fileService;

	/** @var ConfigService */
	private $configService;


	/** @var array */
	private $locks = [];

	/** @var bool */
	private $lockRetrieved = false;

	/** @var array */
	private $lockCache = [];


	public function __construct(
		$userId, IUserManager $userManager, LocksRequest $locksRequest, FileService $fileService,
		ConfigService $configService
	) {
		$this->userId = $userId;
		$this->userManager = $userManager;
		$this->locksRequest = $locksRequest;
		$this->fileService = $fileService;
		$this->configService = $configService;

		$this->setup('app', 'files_lock');
	}

	/**
	 * @param int $nodeId
	 *
	 * @return FileLock|bool
	 */
	private function getLockForNodeId(int $nodeId) {
		if (array_key_exists($nodeId, $this->lockCache) && $this->lockCache[$nodeId] !== null) {
			return $this->lockCache[$nodeId];
		}

		try {
			$this->lockCache[$nodeId] = $this->getLockFromCache($nodeId);
		} catch (LockNotFoundException $e) {
			$this->lockCache[$nodeId] = false;
		}

		return $this->lockCache[$nodeId];
	}

	/**
	 * @param PropFind $propFind
	 * @param INode $node
	 *
	 * @return void
	 */
	public function propFind(PropFind $propFind, INode $node) {
		if (!$node instanceof SabreNode) {
			return;
		}
		$nodeId = $node->getId();

		$propFind->handle(
			Application::DAV_PROPERTY_LOCK, function() use ($nodeId) {
			$lock = $this->getLockForNodeId($nodeId);

			if ($lock === false) {
				return false;
			}

			return true;
		}
		);

		$propFind->handle(
			Application::DAV_PROPERTY_LOCK_OWNER, function() use ($nodeId) {
			$lock = $this->getLockForNodeId($nodeId);

			if ($lock !== false) {
				return $lock->getUserId();
			}

			return null;
		}
		);

		$propFind->handle(
			Application::DAV_PROPERTY_LOCK_TIME, function() use ($nodeId) {
			$lock = $this->getLockForNodeId($nodeId);

			if ($lock !== false) {
				return $lock->getCreation();
			}

			return 0;
		}
		);

		$propFind->handle(
			Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME, function() use ($nodeId) {
			$lock = $this->getLockForNodeId($nodeId);

			if ($lock !== false) {
				$user = $this->userManager->get($lock->getUserId());
				if ($user !== null) {
					return $user->getDisplayName();
				}
			}

			return null;
		}
		);
	}


	/**
	 * @param FileLock $lock
	 *
	 * @throws AlreadyLockedException
	 */
	public function lock(FileLock $lock) {
		$this->generateToken($lock);
		$this->notice('locking file', false, ['fileLock' => $lock]);

		try {
			$known = $this->getLockFromFileId($lock->getFileId());

			throw new AlreadyLockedException('File is already locked by ' . $known->getUserId());
		} catch (LockNotFoundException $e) {
			$this->locksRequest->save($lock);
		}
	}


	/**
	 * @param Node $file
	 * @param IUser $user
	 *
	 * @return FileLock
	 * @throws AlreadyLockedException
	 * @throws InvalidPathException
	 * @throws NotFileException
	 * @throws NotFoundException
	 */
	public function lockFile(Node $file, IUser $user): FileLock {
		if ($file->getType() !== Node::TYPE_FILE) {
			throw new NotFileException('Must be a file, seems to be a folder.');
		}

		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->setUserId($user->getUID());
		$lock->setFileId($file->getId());

		$this->lock($lock);

		return $lock;
	}


	/**
	 * @param FileLock $lock
	 * @param bool $force
	 *
	 * @throws LockNotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlock(FileLock $lock, bool $force = false) {
		$this->notice('unlocking file', false, ['fileLock' => $lock]);

		$known = $this->getLockFromFileId($lock->getFileId());
		if (!$force && $lock->getUserId() !== $known->getUserId()) {
			throw new UnauthorizedUnlockException('File can only be unlocked by the owner of the lock');
		}

		$this->locksRequest->delete($known);
	}


	/**
	 * @param int $fileId
	 * @param string $userId
	 *
	 * @param bool $force
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlockFile(int $fileId, string $userId, bool $force = false): FileLock {
		$lock = new FileLock();
		$lock->setUserId($userId);
		$lock->setFileId($fileId);

		$this->unlock($lock, $force);

		return $lock;
	}


	/**
	 * @return FileLock[]
	 */
	public function getDeprecatedLocks(): array {
		$timeout = (int)$this->configService->getAppValue(ConfigService::LOCK_TIMEOUT);
		if ($timeout === 0) {
			$this->notice(
				'ConfigService::LOCK_TIMEOUT is not numerical, using default', true, ['current' => $timeout]
			);
			$timeout = $this->configService->defaults[ConfigService::LOCK_TIMEOUT];
		}

		try {
			$locks = $this->locksRequest->getLocksOlderThan($timeout);
		} catch (Exception $e) {
			return [];
		}

		return $locks;
	}


	/**
	 * @param int $fileId
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	public function getLockFromFileId(int $fileId): FileLock {
		$lock = $this->locksRequest->getFromFileId($fileId);
		if ($lock->getETA() === 0) {
			$this->locksRequest->delete($lock);
			throw new LockNotFoundException('lock is ignored and deleted as being too old.');
		}

		return $lock;
	}


	/**
	 * @param FileLock $lock
	 */
	public function generateToken(FileLock $lock) {
		if ($lock->getToken() !== '') {
			return;
		}

		$lock->setToken(self::PREFIX . '/' . $this->uuid());
	}


	/**
	 * @param FileLock[] $locks
	 */
	public function removeLocks(array $locks) {
		if (empty($locks)) {
			return;
		}
		
		$ids = array_map(
			function(FileLock $lock) {
				return $lock->getId();
			}, $locks
		);

		$this->notice('removing locks', false, ['ids' => $ids]);

		$this->locksRequest->removeIds($ids);
	}


	/**
	 * @param $nodeId
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	private function getLockFromCache(int $nodeId): FileLock {
		if (!$this->lockRetrieved) {
			$this->locks = $this->locksRequest->getAll();
			$this->lockRetrieved = true;
		}

		foreach ($this->locks as $lock) {
			if ($lock->getFileId() === $nodeId) {
				return $lock;
			}
		}

		throw new LockNotFoundException();
	}


}

