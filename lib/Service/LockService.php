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


use Exception;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Tools\Traits\TLogger;
use OCA\FilesLock\Tools\Traits\TStringTools;
use OCP\App\IAppManager;
use OCP\DirectEditing\IManager;
use OCP\DirectEditing\RegisterDirectEditorEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\FileInfo;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockScope;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\PreConditionNotMetException;


/**
 * Class LockService
 *
 * @package OCA\FilesLock\Service
 */
class LockService {


	const PREFIX = 'files_lock';


	use TStringTools;
	use TLogger;


	private ?string $userId;
	private IUserManager $userManager;
	private IL10N $l10n;
	private LocksRequest $locksRequest;
	private FileService $fileService;
	private ConfigService $configService;
	private IAppManager $appManager;
	private IManager $directEditingManager;
	private IEventDispatcher $eventDispatcher;


	private array $locks = [];
	private bool $lockRetrieved = false;
	private array $lockCache = [];
	private ?array $directEditors = null;


	public function __construct(
		$userId,
		IL10N $l10n,
		IUserManager $userManager,
		LocksRequest $locksRequest,
		FileService $fileService,
		ConfigService $configService,
		IAppManager $appManager,
		IManager $directEditingManager,
		IEventDispatcher $eventDispatcher
	) {
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->locksRequest = $locksRequest;
		$this->fileService = $fileService;
		$this->configService = $configService;
		$this->appManager = $appManager;
		$this->directEditingManager = $directEditingManager;
		$this->eventDispatcher = $eventDispatcher;

		$this->setup('app', 'files_lock');
	}

	/**
	 * @param int $nodeId
	 *
	 * @return FileLock|bool
	 */
	public function getLockForNodeId(int $nodeId) {
		if (array_key_exists($nodeId, $this->lockCache) && $this->lockCache[$nodeId] !== null) {
			return $this->lockCache[$nodeId];
		}

		try {
			$this->lockCache[$nodeId] = $this->getLockFromFileId($nodeId);
		} catch (LockNotFoundException $e) {
			$this->lockCache[$nodeId] = false;
		}

		return $this->lockCache[$nodeId];
	}


	/**
	 * @throws OwnerLockedException
	 */
	public function lock(FileLock $lock): FileLock {
		try {
			$known = $this->getLockFromFileId($lock->getFileId());
			if ($known->getLockType() === $lock->getLockType() && $known->getOwner() === $lock->getOwner()) {
				$known->setTimeout(
					$known->getTimeout() - $known->getETA() + $this->configService->getTimeoutSeconds()
				);
				$this->notice('extending existing lock', false, ['fileLock' => $known]);
				$this->locksRequest->update($known);
				return $known;
			}
			throw new OwnerLockedException($known);
		} catch (LockNotFoundException $e) {
			$this->generateToken($lock);
			$lock->setCreation(time());
			$this->notice('locking file', false, ['fileLock' => $lock]);
			$this->locksRequest->save($lock);
		}
		return $lock;
	}


	/**
	 * @param Node $file
	 * @param IUser $user
	 *
	 * @return FileLock
	 * @throws InvalidPathException
	 * @throws NotFileException
	 * @throws NotFoundException
	 * @throws OwnerLockedException
	 */
	public function lockFileAsUser(Node $file, IUser $user): FileLock {
		if ($file->getType() !== FileInfo::TYPE_FILE) {
			throw new NotFileException('Must be a file, seems to be a folder.');
		}

		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->setUserId($user->getUID());
		$lock->setFileId($file->getId());

		return $this->lock($lock);
	}

	/**
	 * @throws InvalidPathException
	 * @throws NotFileException
	 * @throws NotFoundException
	 * @throws PreConditionNotMetException
	 * @throws OwnerLockedException
	 */
	public function lockFileByUserId(Node $file, string $userId): FileLock {
		$user = $this->userManager->get($userId);
		if ($user) {
			return $this->lockFileAsUser($file, $user);
		}

		throw new PreConditionNotMetException('No user found' . $userId);
	}


	/**
	 * @throws InvalidPathException
	 * @throws NotFileException
	 * @throws NotFoundException
	 * @throws OwnerLockedException
	 */
	public function lockFileAsApp(Node $file, string $appId): FileLock {
		if ($file->getType() !== FileInfo::TYPE_FILE) {
			throw new NotFileException('Must be a file, seems to be a folder.');
		}

		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->setLockType(ILock::TYPE_APP);
		$lock->setUserId($appId);
		$lock->setFileId($file->getId());

		$this->lock($lock);

		return $lock;
	}

	public function getAppName(string $appId): ?string {
		$appInfo = $this->appManager->getAppInfo($appId);
		return $appInfo['name'] ?? null;
	}

	public function getDirectEditorForAppId(string $appId): ?string {
		if (!$this->directEditors) {
			$this->eventDispatcher->dispatchTyped(new RegisterDirectEditorEvent($this->directEditingManager));
			$this->directEditors = $this->directEditingManager->getEditors();
		}
		$editor = current(array_filter($this->directEditors, function ($editor) use ($appId) {
			return $editor->getId() === $appId;
		}));
		return $editor ? $editor->getId() : null;
	}


	/**
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlock(LockScope $lock, bool $force = false): FileLock {
		$this->notice('unlocking file', false, ['fileLock' => $lock]);

		$known = $this->getLockFromFileId($lock->getNode()->getId());
		if (!$force && ($lock->getOwner() !== $known->getOwner() || $lock->getType() !== $known->getLockType())) {
			throw new UnauthorizedUnlockException(
				$this->l10n->t('File can only be unlocked by the owner of the lock')
			);
		}

		$this->locksRequest->delete($known);

		return $known;
	}


	/**
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlockFile(int $fileId, string $userId, bool $force = false): FileLock {
		$node = $this->fileService->getFileFromId($userId, $fileId);
		$lock = new LockScope(
			$node,
			ILock::TYPE_USER,
			$userId,
		);
		return $this->unlock($lock, $force);
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
			function (FileLock $lock) {
				return $lock->getId();
			}, $locks
		);

		$this->notice('removing locks', false, ['ids' => $ids]);

		$this->locksRequest->removeIds($ids);
	}
}

