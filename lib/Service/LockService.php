<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Service;

use Exception;
use OC\Files\Storage\DAV;
use OC\Files\Storage\Wrapper\Wrapper;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\ConfigLexicon;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockConflictException;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IHomeStorage;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

class LockService {
	public const PREFIX = 'files_lock';
	/** @var array<int, FileLock|false> */
	private array $lockCache = [];
	/** @var array<int, FileLock|false> */
	private array $remoteLockCache = [];
	private bool $allowUserOverride = false;

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IUserManager $userManager,
		private readonly LocksRequest $locksRequest,
		private readonly FileService $fileService,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		IEventDispatcher $eventDispatcher,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
	) {
	}

	/**
	 * Resolved lazily so that a clock replaced in the container after this
	 * service was built (tests) is honoured everywhere.
	 */
	private function now(): int {
		return Server::get(ITimeFactory::class)->getTime();
	}

	public function clearCache(): void {
		$this->lockCache = [];
		$this->remoteLockCache = [];
	}

	/**
	 * Active local lock of a file, using the per-request cache. Never consults remote storages.
	 */
	public function getActiveLock(int $fileId): ?FileLock {
		if (array_key_exists($fileId, $this->lockCache)) {
			$cached = $this->lockCache[$fileId];
			if ($cached instanceof FileLock && $cached->isExpired($this->now())) {
				$this->locksRequest->removeExpired($fileId, $this->now());
				$this->lockCache[$fileId] = false;
				return null;
			}
			return $cached ?: null;
		}

		try {
			return $this->getLockFromFileId($fileId);
		} catch (LockNotFoundException) {
			return null;
		}
	}

	public function getLockForNodeId(int $nodeId, ?Node $node = null): FileLock|false {
		$lock = $this->getActiveLock($nodeId);
		if ($lock !== null) {
			return $lock;
		}

		if (array_key_exists($nodeId, $this->remoteLockCache)) {
			return $this->remoteLockCache[$nodeId];
		}

		$remoteLock = $this->getRemoteLockFromDav($nodeId, $node);
		$this->remoteLockCache[$nodeId] = $remoteLock ?: false;
		return $this->remoteLockCache[$nodeId];
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
			$nodeId = (int)$nodeId;
			if (array_key_exists($nodeId, $this->lockCache) && $this->lockCache[$nodeId] instanceof FileLock) {
				$locks[$nodeId] = $this->lockCache[$nodeId];
			} elseif (array_key_exists($nodeId, $this->remoteLockCache)) {
				$locks[$nodeId] = $this->remoteLockCache[$nodeId];
			} else {
				$locksToRequest[] = $nodeId;
			}
		}
		if (count($locksToRequest) === 0) {
			return $locks;
		}

		// pre-fill the cache with negative hits for all requested ids
		// so if no lock is found for the file we store the negative hit
		foreach ($locksToRequest as $fileId) {
			$this->lockCache[$fileId] = false;
		}

		$newLocks = [];
		while ($fileIds = array_splice($locksToRequest, 0, 1000)) {
			$newLocks[] = $this->locksRequest->getFromFileIds($fileIds);
		}
		$newLocks = array_merge(...$newLocks);

		$now = $this->now();
		$expiredLocks = [];
		foreach ($newLocks as $lock) {
			if ($lock->isExpired($now)) {
				$expiredLocks[] = $lock->getId();
				$locks[$lock->getFileId()] = false;
				$this->lockCache[$lock->getFileId()] = false;
			} else {
				$locks[$lock->getFileId()] = $lock;
				$this->lockCache[$lock->getFileId()] = $lock;
			}
		}

		if (count($expiredLocks) > 0) {
			$this->locksRequest->removeExpiredIds($expiredLocks, $now);
		}

		return $locks;
	}

	/**
	 * Configured lock lifetime in seconds, ETA_INFINITE when locks never expire.
	 */
	public function getConfiguredTimeout(): int {
		$minutes = $this->appConfig->getAppValueInt(ConfigLexicon::LOCK_TIMEOUT);
		return $minutes > 0 ? $minutes * 60 : FileLock::ETA_INFINITE;
	}

	public function lock(LockContext $lockScope): FileLock {
		return $this->acquire($lockScope);
	}

	/**
	 * Acquire or refresh the lock described by $lockScope.
	 *
	 * The database enforces at most one lock per file; a lost race is reported as
	 * OwnerLockedException carrying the winning lock.
	 *
	 * @param int|null $timeout lifetime in seconds, <= 0 for no expiry, null for the configured value
	 * @param string|null $token token to record for a new lock (native WebDAV), generated when null
	 * @param string|null $displayName display name to record, resolved from the owner when null
	 *
	 * @throws OwnerLockedException
	 * @throws UnauthorizedUnlockException
	 */
	public function acquire(LockContext $lockScope, ?int $timeout = null, ?string $token = null, ?string $displayName = null): FileLock {
		$this->canLock($lockScope);
		$fileId = $lockScope->getNode()->getId();
		$timeout ??= $this->getConfiguredTimeout();
		$now = $this->now();

		$this->locksRequest->removeExpired($fileId, $now);
		unset($this->lockCache[$fileId]);

		try {
			$known = $this->locksRequest->getFromFileId($fileId);
			return $this->refreshOrConflict($known, $lockScope, $timeout, $now);
		} catch (LockNotFoundException) {
		}

		$lock = FileLock::fromLockScope($lockScope, 0);
		$lock->setCreation($now);
		$lock->setExpiresAt($timeout > 0 ? $now + $timeout : null);
		$lock->setToken($token ?? self::PREFIX . '/' . uuid_create(UUID_TYPE_RANDOM));
		if ($displayName !== null) {
			$lock->setDisplayName($displayName);
		} else {
			$this->injectMetadata($lock);
		}

		try {
			$this->locksRequest->save($lock);
		} catch (LockConflictException) {
			$known = null;
			try {
				$known = $this->locksRequest->getFromFileId($fileId);
			} catch (LockNotFoundException) {
				// no row for this file, so the unique index that fired was the one on
				// the token; take a fresh token and try once more
				$lock->setToken(self::PREFIX . '/' . uuid_create(UUID_TYPE_RANDOM));
				$this->locksRequest->save($lock);
			}
			if ($known !== null) {
				return $this->refreshOrConflict($known, $lockScope, $timeout, $now);
			}
		}

		$this->logger->notice('locking file', ['fileLock' => $lock]);
		$this->lockCache[$fileId] = $lock;
		$this->propagateEtag($lockScope->getNode());
		return $lock;
	}

	/**
	 * @throws OwnerLockedException
	 */
	private function refreshOrConflict(FileLock $known, LockContext $lockScope, int $timeout, int $now): FileLock {
		$this->injectMetadata($known);
		if (!($known->getType() === $lockScope->getType()
			&& ($known->getOwner() === $lockScope->getOwner() || $known->getToken() === $lockScope->getOwner()))) {
			$this->lockCache[$known->getFileId()] = $known;
			throw new OwnerLockedException($known);
		}

		$known->setExpiresAt($timeout > 0 ? $now + $timeout : null);
		$this->logger->notice('extending existing lock', ['fileLock' => $known]);
		$this->locksRequest->update($known);
		$this->lockCache[$known->getFileId()] = $known;
		return $known;
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
		$this->lockCache[$lock->getNode()->getId()] = false;
		$this->propagateEtag($lock->getNode());
		$this->injectMetadata($known);
		return $known;
	}

	public function enableUserOverride(): void {
		$this->allowUserOverride = true;
	}

	public function canUnlock(LockContext $request, FileLock $current): void {
		$isSameUser = $current->getOwner() === $this->userSession->getUser()?->getUID();
		$isSameToken = $request->getOwner() === $current->getToken();
		$isSameOwner = $request->getOwner() === $current->getOwner();
		$isSameType = $request->getType() === $current->getType();

		// we need to ignore some filesystem that return current user as file owner
		$ignoreFileOwnership = [
			'OCA\GroupFolders\Mount\MountProvider',
			'OCA\Files_External\Config\ConfigAdapter'
		];

		$isFileOwner = $request->getNode()->getOwner()->getUID() === $this->userSession->getUser()?->getUID()
			&& !in_array($request->getNode()->getMountPoint()->getMountProvider(), $ignoreFileOwnership);

		// Check the token for token based locks
		if ($current->getType() === ILock::TYPE_TOKEN) {
			// token holder can unlock
			if ($isSameToken) {
				return;
			}
			// file owner or lock owner can unlock
			if ($this->allowUserOverride && ($isSameUser || $isFileOwner)) {
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

		if ($request->getType() === ILock::TYPE_USER && $isFileOwner) {
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
	public function unlockFile(int $fileId, ?string $userId, bool $force = false, int $lockType = ILock::TYPE_USER): FileLock {
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
		$this->propagateEtag($lock->getNode());
		return $this->unlock($lock, $force);
	}

	public function update(FileLock $lock): void {
		$this->locksRequest->update($lock);
		$this->lockCache[$lock->getFileId()] = $lock;
	}

	public function getAppName(string $appId): ?string {
		/** @var array{name: null}|null $appInfo */
		$appInfo = $this->appManager->getAppInfo($appId);
		return $appInfo['name'] ?? null;
	}

	/**
	 * @throws UnauthorizedUnlockException when the node cannot be locked by the caller
	 * @throws NotFileException when the node is not a file
	 */
	public function canLock(LockContext $request, ?FileLock $current = null): void {
		if (($request->getNode()->getPermissions() & Constants::PERMISSION_UPDATE) === 0) {
			throw new UnauthorizedUnlockException(
				$this->l10n->t('File can only be locked with update permissions.')
			);
		}
	}

	/**
	 * Locks whose expiry has passed.
	 *
	 * @param int $limit how many locks to retrieve (0 for all, default)
	 *
	 * @return FileLock[]
	 */
	public function getExpiredLocks(int $limit = 0): array {
		try {
			return $this->locksRequest->getExpired($this->now(), $limit);
		} catch (Exception $e) {
			$this->logger->warning('Failed to get expired locks', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * @deprecated use getExpiredLocks()
	 * @return FileLock[]
	 */
	public function getDeprecatedLocks(int $limit = 0): array {
		return $this->getExpiredLocks($limit);
	}

	/**
	 * Active lock of a file, removing it first when it has expired.
	 *
	 * @throws LockNotFoundException
	 */
	public function getLockFromFileId(int $fileId): FileLock {
		try {
			$lock = $this->locksRequest->getFromFileId($fileId);
		} catch (LockNotFoundException $e) {
			$this->lockCache[$fileId] = false;
			throw $e;
		}
		if ($lock->isExpired($this->now())) {
			$this->locksRequest->delete($lock);
			$this->lockCache[$fileId] = false;
			throw new LockNotFoundException('lock is ignored and deleted as being too old.');
		}

		$this->lockCache[$fileId] = $lock;
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
			$displayName = $lock->getDisplayName();
			if ($displayName === null || $displayName === '') {
				$clientHint = $this->getClientHint();
				$displayName = trim(($this->userManager->getDisplayName($lock->getOwner()) ?? $lock->getOwner())
					. ($clientHint ? (' (' . $clientHint . ')') : ''));
			}
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

	public function generateToken(FileLock $lock): void {
		if ($lock->getToken() !== '') {
			return;
		}

		$lock->setToken(self::PREFIX . '/' . uuid_create(UUID_TYPE_RANDOM));
	}

	/**
	 * Remove the given locks, skipping any that are no longer expired because
	 * their owner refreshed them after the batch was read.
	 *
	 * @param FileLock[] $locks
	 */
	public function removeLocksIfExpired(array $locks): void {
		if (empty($locks)) {
			return;
		}

		$ids = array_map(fn (FileLock $lock): int => $lock->getId(), $locks);
		$removed = $this->locksRequest->removeExpiredIds($ids, $this->now());
		$this->logger->notice('removing expired locks', ['candidates' => count($ids), 'removed' => $removed]);

		foreach ($locks as $lock) {
			unset($this->lockCache[$lock->getFileId()]);
		}
	}

	/**
	 * @param FileLock[] $locks
	 */
	public function removeLocks(array $locks): void {
		if (empty($locks)) {
			return;
		}

		$ids = array_map(
			fn (FileLock $lock): int => $lock->getId(), $locks
		);

		$this->logger->notice('removing locks', ['ids' => $ids]);

		$this->locksRequest->removeIds($ids);
		foreach ($locks as $lock) {
			$this->lockCache[$lock->getFileId()] = false;
		}
	}

	public function getRemoteLockFromDav(int $nodeId, ?Node $node = null): ?FileLock {
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return null;
			}

			if (!$node) {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$node = $userFolder->getFirstNodeById($nodeId);
			}
			if (empty($node)) {
				return null;
			}

			$storage = $node->getStorage();

			while ($storage->instanceOfStorage(Wrapper::class)) {
				$storage = $storage->getWrapperStorage();
			}

			if (!$storage->instanceOfStorage(DAV::class)) {
				return null;
			}

			if (!method_exists($storage, 'getPropfindPropertyValue')) {
				return null;
			}

			$path = $node->getInternalPath();
			$storage->getMetaData($path);

			$isLocked = $storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK);
			if (!$isLocked) {
				return null;
			}

			$fileLock = new FileLock();
			$fileLock->import([
				'fileId' => $nodeId,
				'displayName' => (string)($storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME) ?? ''),
				'type' => (int)($storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK_OWNER_TYPE) ?? 0),
				'creation' => (int)($storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK_TIME) ?? 0),
				'ttl' => (int)($storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK_TIMEOUT) ?? 0),
				'token' => (string)($storage->getPropfindPropertyValue($path, Application::DAV_PROPERTY_LOCK_TOKEN) ?? ''),
			]);

			$remoteHost = parse_url($storage->getRemote(), PHP_URL_HOST);
			$fileLock->setDisplayName($fileLock->getDisplayName() . '@' . $remoteHost);

			return $fileLock;
		} catch (\Exception $e) {
			$this->logger->error('Failed to get remote lock from DAV: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	private function propagateEtag(Node $node): void {
		try {
			$node->getStorage()->getCache()->update($node->getId(), [
				'etag' => uniqid(),
			]);
			$node->getStorage()->getUpdater()->propagate($node->getInternalPath(), $node->getMTime());
		} catch (Exception $e) {
			$this->logger->debug('Failed to propagate etag after lock change: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
