<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Service;

use Exception;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Tools\Traits\TStringTools;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class LockService {
	public const PREFIX = 'files_lock';


	use TStringTools;

	private IUserManager $userManager;
	private IL10N $l10n;
	private LocksRequest $locksRequest;
	private FileService $fileService;
	private ConfigService $configService;
	private IAppManager $appManager;
	private IEventDispatcher $eventDispatcher;
	private IUserSession $userSession;
	private IRequest $request;
	private LoggerInterface $logger;


	private array $locks = [];
	private bool $lockRetrieved = false;
	private array $lockCache = [];
	private ?array $directEditors = null;
	private bool $allowUserOverride = false;


	public function __construct(
		IL10N $l10n,
		IUserManager $userManager,
		LocksRequest $locksRequest,
		FileService $fileService,
		ConfigService $configService,
		IAppManager $appManager,
		IEventDispatcher $eventDispatcher,
		IUserSession $userSession,
		IRequest $request,
		LoggerInterface $logger,
	) {
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->locksRequest = $locksRequest;
		$this->fileService = $fileService;
		$this->configService = $configService;
		$this->appManager = $appManager;
		$this->eventDispatcher = $eventDispatcher;
		$this->userSession = $userSession;
		$this->request = $request;
		$this->logger = $logger;
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
	 * @param list<int> $nodeIds
	 *
	 * @return array<int, FileLock|bool>
	 */
	public function getLockForNodeIds(array $nodeIds): array {
		$locks = [];
		$locksToRequest = [];
		foreach ($nodeIds as $nodeId) {
			if (array_key_exists($nodeId, $this->lockCache) && $this->lockCache[$nodeId] !== null) {
				$locks[$nodeId] = $this->lockCache[$nodeId];
			} else {
				$locksToRequest[] = $nodeId;
				$this->lockCache[$nodeId] = false;
			}
		}
		if (count($locksToRequest) === 0) {
			return $locks;
		}

		$newLocks = [];
		while ($fileIds = array_splice($locksToRequest, 0, 1000)) {
			$newLocks[] = $this->locksRequest->getFromFileIds($fileIds);
		}
		$newLocks = array_merge(...$newLocks);

		$expiredLocks = [];
		foreach ($newLocks as $lock) {
			if ($lock->getETA() === 0) {
				$expiredLocks[] = $lock->getId();
				$locks[$lock->getFileId()] = false;
				$this->lockCache[$lock->getFileId()] = false;
			} else {
				$locks[$lock->getFileId()] = $lock;
				$this->lockCache[$lock->getFileId()] = $lock;
			}
		}

		if (count($expiredLocks) > 0) {
			$this->locksRequest->removeIds($expiredLocks);
		}

		return $locks;
	}

	public function lock(LockContext $lockScope): FileLock {
		$this->canLock($lockScope);

		try {
			$known = $this->getLockFromFileId($lockScope->getNode()->getId());

			// Extend lock expiry if matching
			if (
				$known->getType() === $lockScope->getType() && ($known->getOwner() === $lockScope->getOwner() || $known->getToken() === $lockScope->getOwner())
			) {
				$known->setTimeout(
					$known->getETA() !== FileLock::ETA_INFINITE ? $known->getTimeout() - $known->getETA() + $this->configService->getTimeoutSeconds() : 0
				);
				$this->logger->notice('extending existing lock', ['fileLock' => $known]);
				$this->locksRequest->update($known);
				$this->injectMetadata($known);
				return $known;
			}

			$this->injectMetadata($known);
			throw new OwnerLockedException($known);
		} catch (LockNotFoundException $e) {
			$lock = FileLock::fromLockScope($lockScope, $this->configService->getTimeoutSeconds());
			$this->generateToken($lock);
			$lock->setCreation(time());
			$this->logger->notice('locking file', ['fileLock' => $lock]);
			$this->injectMetadata($lock);
			$this->locksRequest->save($lock);
			$this->propagateEtag($lockScope);
			return $lock;
		}
	}

	public function update(FileLock $lock) {
		$this->locksRequest->update($lock);
	}

	public function getAppName(string $appId): ?string {
		$appInfo = $this->appManager->getAppInfo($appId);
		return $appInfo['name'] ?? null;
	}

	/**
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlock(LockContext $lock, bool $force = false): FileLock {
		$this->logger->notice('unlocking file', ['fileLock' => $lock]);

		$known = $this->getLockFromFileId($lock->getNode()->getId());
		if (!$force) {
			$this->canUnlock($lock, $known);
		}

		$this->locksRequest->delete($known);
		$this->propagateEtag($lock);
		$this->injectMetadata($known);
		return $known;
	}

	public function enableUserOverride(): void {
		$this->allowUserOverride = true;
	}

	public function canLock(LockContext $request, ?FileLock $current = null): void {
		if (($request->getNode()->getPermissions() & Constants::PERMISSION_UPDATE) === 0) {
			throw new UnauthorizedUnlockException(
				$this->l10n->t('File can only be locked with update permissions.')
			);
		}
	}

	public function canUnlock(LockContext $request, FileLock $current): void {
		$isSameUser = $current->getOwner() === $this->userSession->getUser()?->getUID();
		$isSameToken = $request->getOwner() === $current->getToken();
		$isSameOwner = $request->getOwner() === $current->getOwner();
		$isSameType = $request->getType() === $current->getType();

		// Check the token for token based locks
		if ($current->getType() === ILock::TYPE_TOKEN) {
			if ($isSameToken || ($this->allowUserOverride && $isSameUser)) {
				return;
			}

			throw new UnauthorizedUnlockException(
				$this->l10n->t('File can only be unlocked by providing a valid owner lock token')
			);
		}

		// Otherwise, we check if the owner (user id OR app id) for a match
		if ($isSameOwner && $isSameType) {
			return;
		}

		// we need to ignore some filesystem that return current user as file owner
		$ignoreFileOwnership = [
			'OCA\GroupFolders\Mount\MountProvider',
			'OCA\Files_External\Config\ConfigAdapter'
		];
		if ($request->getType() === ILock::TYPE_USER
			&& $request->getNode()->getOwner()->getUID() === $this->userSession->getUser()?->getUID()
			&& !in_array($request->getNode()->getMountPoint()->getMountProvider(), $ignoreFileOwnership)
		) {
			return;
		}

		throw new UnauthorizedUnlockException(
			$this->l10n->t('File can only be unlocked by the owner of the lock')
		);
	}


	/**
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlockFile(int $fileId, string $userId, bool $force = false, int $lockType = ILock::TYPE_USER): FileLock {
		$lock = $this->getLockForNodeId($fileId);
		if (!$lock) {
			throw new LockNotFoundException();
		}

		if ($force) {
			$userId = in_array($lock->getType(), [ILock::TYPE_USER, ILock::TYPE_TOKEN]) ? $lock->getOwner() : $userId;
			$lockType = $lock->getType();
		}

		$node = $this->fileService->getFileFromId($userId, $fileId);
		$lock = new LockContext(
			$node,
			$lockType,
			$userId,
		);
		$this->propagateEtag($lock);
		return $this->unlock($lock, $force);
	}


	/**
	 * @param int $limit how many locks to retrieve (0 for all, default)
	 *
	 * @return FileLock[]
	 */
	public function getDeprecatedLocks(int $limit = 0): array {
		$timeout = (int)$this->configService->getAppValue(ConfigService::LOCK_TIMEOUT);
		if ($timeout === 0) {
			$this->logger->notice(
				'ConfigService::LOCK_TIMEOUT is not numerical, using default', ['current' => $timeout, 'exception' => new \Exception()]
			);
			$timeout = (int)$this->configService->defaults[ConfigService::LOCK_TIMEOUT];
		}

		try {
			$locks = $this->locksRequest->getLocksOlderThan($timeout, $limit);
		} catch (Exception $e) {
			$this->logger->warning('Failed to get locks older then timeout', ['exception' => $e]);
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

	public function injectMetadata(FileLock $lock): FileLock {
		$displayName = null;
		if ($lock->getType() === ILock::TYPE_USER) {
			$displayName = $this->userManager->getDisplayName($lock->getOwner());
		}
		if ($lock->getType() === ILock::TYPE_APP) {
			$displayName = $this->getAppName($lock->getOwner()) ?? null;
		}
		if ($lock->getType() === ILock::TYPE_TOKEN) {
			$clientHint = $this->getClientHint();
			$displayName = $lock->getDisplayName() ?: (
				$this->userManager->getDisplayName($lock->getOwner()) . ' ' .
				($clientHint ? ('(' . $clientHint . ')') : '')
			);
		}

		if ($displayName) {
			$lock->setDisplayName($displayName);
		}
		return $lock;
	}

	private function getClientHint(): ?string {
		if ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_DESKTOP])) {
			return $this->l10n->t('Desktop client');
		}

		if ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_IOS])) {
			return $this->l10n->t('iOS client');
		}

		if ($this->request->isUserAgent([IRequest::USER_AGENT_CLIENT_ANDROID])) {
			return $this->l10n->t('Android client');
		}

		return null;
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

		$this->logger->notice('removing locks', ['ids' => $ids]);

		$this->locksRequest->removeIds($ids);
	}

	private function propagateEtag(LockContext $lockContext): void {
		$node = $lockContext->getNode();
		$node->getStorage()->getCache()->update($node->getId(), [
			'etag' => uniqid(),
		]);
		$node->getStorage()->getUpdater()->propagate($node->getInternalPath(), $node->getMTime());
	}
}
