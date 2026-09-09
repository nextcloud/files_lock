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
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
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
	/** @var list<string> */
	private array $presentedTokens = [];

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IUserManager $userManager,
		private readonly LocksRequest $locksRequest,
		private readonly FileService $fileService,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
		private readonly LockPolicy $policy,
	) {
	}

	/**
	 * Resolved lazily so that a clock replaced in the container after this
	 * service was built (tests) is honoured everywhere.
	 */
	private function now(): int {
		return Server::get(ITimeFactory::class)->getTime();
	}

	/**
	 * Register a lock token presented by the current request (WebDAV If header).
	 */
	public function presentToken(string $token): void {
		if ($token !== '' && !in_array($token, $this->presentedTokens, true)) {
			$this->presentedTokens[] = $token;
		}
	}

	public function resetPresentedTokens(): void {
		$this->presentedTokens = [];
	}

	public function clearCache(): void {
		$this->lockCache = [];
		$this->remoteLockCache = [];
		$this->presentedTokens = [];
	}

	/**
	 * Active local lock of a file, using the per-request cache. Never consults remote storages.
	 */
	public function getActiveLock(int $fileId): ?FileLock {
		if (array_key_exists($fileId, $this->lockCache)) {
			$cached = $this->lockCache[$fileId];
			$now = $this->now();
			if ($cached instanceof FileLock && $cached->isExpired($now)) {
				$this->locksRequest->removeExpired($fileId, $now);
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

	/**
	 * Lock of a file, falling back to the lock a remote DAV storage reports.
	 */
	public function getLockForNodeId(int $nodeId, ?Node $node = null): ?FileLock {
		$lock = $this->getActiveLock($nodeId);
		if ($lock !== null) {
			return $lock;
		}

		if (!array_key_exists($nodeId, $this->remoteLockCache)) {
			$this->remoteLockCache[$nodeId] = $this->getRemoteLockFromDav($nodeId, $node) ?: false;
		}

		return $this->remoteLockCache[$nodeId] ?: null;
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

		// pre-fill with negative hits for all requested ids, so that a file with no
		// lock is reported as such instead of being left out of the result
		foreach ($locksToRequest as $fileId) {
			$locks[$fileId] = false;
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
	private function getConfiguredTimeout(): int {
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
	 * @param bool $filesOnly refuse folders; native WebDAV may lock a collection (RFC 4918)
	 *
	 * @throws OwnerLockedException
	 * @throws UnauthorizedUnlockException
	 * @throws NotFileException
	 */
	public function acquire(LockContext $lockScope, ?int $timeout = null, ?string $token = null, ?string $displayName = null, bool $filesOnly = true): FileLock {
		$this->canLock($lockScope, $filesOnly);
		$fileId = $lockScope->getNode()->getId();
		$timeout ??= $this->getConfiguredTimeout();
		$now = $this->now();

		unset($this->lockCache[$fileId]);

		try {
			$known = $this->locksRequest->getFromFileId($fileId);
			if (!$known->isExpired($now)) {
				return $this->refreshOrConflict($known, $lockScope, $timeout, $now);
			}
			// the delete keeps its own expiry condition, so a refresh that lands in
			// between is not dropped
			$this->locksRequest->removeExpired($fileId, $now);
		} catch (LockNotFoundException) {
		}

		$lock = FileLock::fromLockScope($lockScope);
		$lock->setCreation($now);
		$lock->setExpiresAt($timeout > 0 ? $now + $timeout : null);
		$lock->setToken($token ?? $this->newToken());
		if ($displayName !== null) {
			$lock->setDisplayName($displayName);
		} else {
			$this->injectMetadata($lock);
		}

		try {
			$this->locksRequest->save($lock);
		} catch (LockConflictException) {
			$known = $this->storeAfterConflict($lock);
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
	 * Recover from an insert the database refused.
	 *
	 * A row for this file means another request won the race. Otherwise the index
	 * that fired was the one on the token, and a fresh token gets one more
	 * attempt; losing that one as well means a competing insert landed in
	 * between, which is a conflict on the file like any other.
	 *
	 * @return FileLock|null the lock that won the file, or null once $lock is stored
	 */
	private function storeAfterConflict(FileLock $lock): ?FileLock {
		try {
			return $this->locksRequest->getFromFileId($lock->getFileId());
		} catch (LockNotFoundException) {
		}

		$lock->setToken($this->newToken());
		try {
			$this->locksRequest->save($lock);
		} catch (LockConflictException) {
			return $this->locksRequest->getFromFileId($lock->getFileId());
		}

		return null;
	}

	/**
	 * @throws OwnerLockedException
	 */
	private function refreshOrConflict(FileLock $known, LockContext $lockScope, int $timeout, int $now): FileLock {
		$this->injectMetadata($known);
		if (!$this->policy->isHolder($known, $lockScope)) {
			$this->lockCache[$known->getFileId()] = $known;
			throw new OwnerLockedException($known);
		}

		$known->setExpiresAt($timeout > 0 ? $now + $timeout : null);
		$this->logger->notice('extending existing lock', ['fileLock' => $known]);
		$this->locksRequest->update($known);
		$this->lockCache[$known->getFileId()] = $known;
		return $known;
	}

	private function newToken(): string {
		return self::PREFIX . '/' . uuid_create(UUID_TYPE_RANDOM);
	}

	public function getAppName(string $appId): ?string {
		/** @var array{name: null}|null $appInfo */
		$appInfo = $this->appManager->getAppInfo($appId);
		return $appInfo['name'] ?? null;
	}

	/**
	 * Release the lock on the node of $lock.
	 *
	 * @param string|null $token lock token presented with the request
	 *
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlock(LockContext $lock, bool $force = false, ?string $token = null): FileLock {
		$this->logger->notice('unlocking file', ['fileLock' => $lock]);

		$known = $this->getLockFromFileId($lock->getNode()->getId());
		if (!$this->policy->canUnlock($known, $lock, $token, $this->isFileOwner($lock->getNode()), $force, $this->canModify($lock->getNode()))) {
			$this->injectMetadata($known);
			throw new UnauthorizedUnlockException(
				$known->getType() === ILock::TYPE_TOKEN
					? $this->l10n->t('File can only be unlocked by providing a valid owner lock token')
					: $this->l10n->t('File can only be unlocked by the owner of the lock')
			);
		}

		$this->locksRequest->delete($known);
		$this->lockCache[$lock->getNode()->getId()] = false;
		$this->propagateEtag($lock->getNode());
		$this->injectMetadata($known);
		return $known;
	}

	/**
	 * @throws UnauthorizedUnlockException when the node cannot be locked by the caller
	 * @throws NotFileException when the node is not a file
	 */
	public function canLock(LockContext $request, bool $filesOnly = true): void {
		if ($filesOnly && !$request->getNode() instanceof File) {
			throw new NotFileException($this->l10n->t('Only files can be locked.'));
		}
		if (!$this->canModify($request->getNode())) {
			throw new UnauthorizedUnlockException(
				$this->l10n->t('File can only be locked with update permissions.')
			);
		}
	}

	/**
	 * Whether the current user may write $lock's file.
	 *
	 * @param LockContext|null $scope active ILockManager scope of the caller
	 */
	public function canWrite(FileLock $lock, ?LockContext $scope): bool {
		return $this->policy->canWrite($lock, $this->userSession->getUser()?->getUID(), $this->presentedTokens, $scope);
	}

	/**
	 * Whether the current caller may write the node at all, independently of any lock.
	 */
	public function canModify(Node $node): bool {
		try {
			return ($node->getPermissions() & Constants::PERMISSION_UPDATE) !== 0;
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * The file owner override applies only to files stored in a user's own home
	 * storage (directly or through a share of it). Group folders, external
	 * storages and other mounts report the current user as owner of every file,
	 * so they never grant the override.
	 */
	public function isFileOwner(Node $node): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		try {
			if (!$node->getStorage()->instanceOfStorage(IHomeStorage::class)) {
				return false;
			}
			return $node->getOwner()?->getUID() === $user->getUID();
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * Release the lock on a file. With $force the lock row is removed without
	 * resolving the file through anyone's file system.
	 *
	 * @throws InvalidPathException
	 * @throws LockNotFoundException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 */
	public function unlockFile(int $fileId, string $userId, bool $force = false, int $lockType = ILock::TYPE_USER): FileLock {
		if ($force) {
			return $this->forceUnlock($fileId);
		}

		$node = $this->fileService->getFileFromId($userId, $fileId);
		return $this->unlock(new LockContext($node, $lockType, $userId));
	}

	/**
	 * Administrative removal of a lock by file id.
	 *
	 * @throws LockNotFoundException
	 */
	public function forceUnlock(int $fileId): FileLock {
		$known = $this->getLockFromFileId($fileId);
		$this->logger->notice('force unlocking file', ['fileLock' => $known]);
		$this->locksRequest->delete($known);
		$this->lockCache[$fileId] = false;

		$node = null;
		try {
			if ($known->getType() !== ILock::TYPE_APP && $this->userManager->userExists($known->getOwner())) {
				$node = $this->rootFolder->getUserFolder($known->getOwner())->getFirstNodeById($fileId);
			}
			$node ??= $this->rootFolder->getFirstNodeById($fileId);
		} catch (Exception) {
		}
		if ($node !== null) {
			$this->propagateEtag($node);
		}

		$this->injectMetadata($known);
		return $known;
	}

	/**
	 * Remove every lock of the given files, regardless of ownership.
	 *
	 * @param list<int> $fileIds
	 */
	public function removeLocksForFileIds(array $fileIds): void {
		if (empty($fileIds)) {
			return;
		}
		$this->locksRequest->removeByFileIds($fileIds);
		foreach ($fileIds as $fileId) {
			$this->lockCache[$fileId] = false;
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

	/**
	 * /**
	 * Active locks on files below a folder (relative path included).
	 *
	 * @return list<array{lock: FileLock, path: string}>
	 */
	public function getLocksBelow(int $folderId): array {
		$now = $this->now();
		return array_values(array_filter(
			$this->locksRequest->getLocksBelow($folderId),
			fn (array $entry): bool => !$entry['lock']->isExpired($now)
		));
	}

	public function injectMetadata(FileLock $lock): FileLock {
		$displayName = null;
		if ($lock->getType() === ILock::TYPE_USER) {
			$displayName = $this->userManager->getDisplayName($lock->getOwner());
		}
		if ($lock->getType() === ILock::TYPE_APP) {
			$displayName = $this->getAppName($lock->getOwner());
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
	 * Remove the given locks unconditionally. The cleanup job wants
	 * removeLocksIfExpired() instead, which will not drop a lock that was
	 * refreshed after the batch was read.
	 *
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
			if ($node === null) {
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
