<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\DAV;

use Closure;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\LockService;
use OCP\Files\Folder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\Locked;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Locks\Backend\BackendInterface;
use Sabre\DAV\Locks\LockInfo;

/**
 * Thin adapter between Sabre's lock backend contract and the canonical lock
 * service. Every lock stored by the app is presented to Sabre as an exclusive
 * write lock, so Sabre's token validation applies to all lock types.
 */
class LockBackend implements BackendInterface {
	/**
	 * @param Closure(string): Node $nodeResolver resolves a request uri to a node, throws Sabre NotFound
	 */
	public function __construct(
		private readonly LockService $lockService,
		private readonly Closure $nodeResolver,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * @param bool $returnChildLocks
	 * @return LockInfo[]
	 */
	#[\Override]
	public function getLocks($uri, $returnChildLocks): array {
		return array_map(
			fn (FileLock $lock): LockInfo => $lock->toLockInfo(),
			$this->getFileLocks($uri, (bool)$returnChildLocks)
		);
	}

	/**
	 * Active locks of the resource at $uri, optionally including locks on files below it.
	 *
	 * @return list<FileLock>
	 */
	public function getFileLocks(string $uri, bool $returnChildLocks): array {
		try {
			$node = $this->resolve($uri);
		} catch (NotFound) {
			return [];
		}

		$locks = [];
		$lock = $this->lockService->getActiveLock($node->getId());
		if ($lock !== null) {
			$lock->setUri($uri);
			$locks[] = $lock;
		}

		if ($returnChildLocks && $node instanceof Folder) {
			foreach ($this->lockService->getLocksBelow($node->getId()) as $entry) {
				$entry['lock']->setUri(rtrim($uri, '/') . '/' . $entry['path']);
				$locks[] = $entry['lock'];
			}
		}

		return $locks;
	}

	/**
	 * Create or refresh a token lock. The complete lock is built before it is
	 * persisted, so a refused request never leaves a partial row behind.
	 *
	 * @throws Locked when another lock is held on the resource
	 * @throws Forbidden when the caller may not lock the resource
	 */
	#[\Override]
	public function lock($uri, LockInfo $lockInfo): bool {
		$node = $this->resolve($uri);
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Forbidden('Locking requires an authenticated user');
		}

		$timeout = null;
		if ($lockInfo->timeout !== null) {
			$timeout = $lockInfo->timeout === LockInfo::TIMEOUT_INFINITE ? FileLock::ETA_INFINITE : max(0, (int)$lockInfo->timeout);
			if ($timeout === 0) {
				$timeout = FileLock::ETA_INFINITE;
			}
		}

		try {
			$lock = $this->lockService->acquire(
				new LockContext($node, ILock::TYPE_TOKEN, $user->getUID()),
				$timeout,
				$lockInfo->token,
				null,
				false
			);
		} catch (OwnerLockedException $e) {
			/** @var FileLock $existing */
			$existing = $e->getLock();
			$existing->setUri($uri);
			throw new Locked($existing->toLockInfo());
		} catch (NotFileException|UnauthorizedUnlockException $e) {
			throw new Forbidden($e->getMessage());
		}

		$lockInfo->token = $lock->getToken();
		$lockInfo->owner = $lock->getDisplayName();
		$lockInfo->created = $lock->getCreatedAt();
		$lockInfo->timeout = $lock->isInfinite() ? LockInfo::TIMEOUT_INFINITE : $lock->getETA();
		$lockInfo->depth = 0;
		$lockInfo->uri = $uri;
		return true;
	}

	/**
	 * Removes a lock from a uri
	 *
	 * @throws Forbidden when the presented token or the caller may not release the lock
	 */
	#[\Override]
	public function unlock($uri, LockInfo $lockInfo): bool {
		try {
			$node = $this->resolve($uri);
		} catch (NotFound) {
			return true;
		}
		$owner = $this->userSession->getUser()?->getUID() ?? $lockInfo->token;
		try {
			$this->lockService->unlock(new LockContext($node, ILock::TYPE_TOKEN, $owner), false, $lockInfo->token);
		} catch (LockNotFoundException) {
			return true;
		} catch (UnauthorizedUnlockException $e) {
			throw new Forbidden($e->getMessage());
		}
		return true;
	}

	/**
	 * @throws NotFound
	 */
	private function resolve(string $uri): Node {
		return ($this->nodeResolver)($uri);
	}
}
