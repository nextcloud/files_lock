<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Service;

use OCA\FilesLock\Model\FileLock;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;

/**
 * The single authorization policy shared by every access path (OCS, X-User-Lock,
 * native WebDAV, PHP ILockManager, CLI, storage wrapper).
 *
 * Ownership model per lock type:
 *  - TYPE_USER:  owner = user id. The owner writes without a credential. Only the
 *                owner (or the file owner override, or a forced unlock) releases it.
 *  - TYPE_APP:   owner = app id. Writes are allowed only inside the owning app's
 *                lock scope (ILockManager::runInScope). Released by the app, the
 *                file owner override, or a forced unlock.
 *  - TYPE_TOKEN: owner = user id, credential = lock token. A write requires the
 *                token to be presented AND the acting principal to be the owner
 *                (RFC 4918 section 6.4); a trusted sessionless caller presenting
 *                the token through the lock scope is treated as the owner. The lock
 *                is released by whoever presents the token (native WebDAV, RFC 4918
 *                section 6.5), by the owner, by the file owner override, or by a
 *                forced unlock.
 */
final class LockPolicy {
	/**
	 * Whether the request context identifies the holder of the lock, which allows
	 * refreshing it instead of conflicting with it.
	 */
	public function isHolder(FileLock $lock, LockContext $context): bool {
		if ($context->getType() !== $lock->getType()) {
			return false;
		}
		if ($context->getOwner() === $lock->getOwner()) {
			return true;
		}
		return $lock->getType() === ILock::TYPE_TOKEN && $context->getOwner() === $lock->getToken();
	}

	/**
	 * @param string|null $viewerId user performing the write, null for a sessionless caller
	 * @param list<string> $presentedTokens lock tokens presented with the request
	 * @param LockContext|null $scope active ILockManager scope
	 */
	public function canWrite(FileLock $lock, ?string $viewerId, array $presentedTokens, ?LockContext $scope): bool {
		switch ($lock->getType()) {
			case ILock::TYPE_USER:
				return $viewerId !== null && $viewerId === $lock->getOwner();
			case ILock::TYPE_APP:
				return $scope !== null
					&& $scope->getType() === ILock::TYPE_APP
					&& $scope->getOwner() === $lock->getOwner();
			case ILock::TYPE_TOKEN:
				$tokenPresented = in_array($lock->getToken(), $presentedTokens, true)
					|| ($scope !== null && $scope->getType() === ILock::TYPE_TOKEN && $scope->getOwner() === $lock->getToken());
				if (!$tokenPresented) {
					return false;
				}
				return $viewerId === null || $viewerId === $lock->getOwner();
		}
		return false;
	}

	/**
	 * @param LockContext $context asserted identity of the caller (owner string is a
	 *                             user id, an app id, or a lock token)
	 * @param string|null $token lock token presented separately from the context
	 * @param bool $isFileOwner whether the caller owns the file (see LockService::isFileOwner)
	 * @param bool $canModify whether the caller may write the file
	 */
	public function canUnlock(FileLock $lock, LockContext $context, ?string $token, bool $isFileOwner, bool $force, bool $canModify = true): bool {
		if ($force || $isFileOwner) {
			return true;
		}
		// whoever the lock was recorded for releases it, even if their access to
		// the file was reduced while they held it
		if ($context->getOwner() === $lock->getOwner()) {
			return true;
		}
		if ($lock->getType() === ILock::TYPE_TOKEN) {
			// the token is a public credential (RFC 4918 section 6.5), so possession
			// alone cannot be the whole authorization: RFC 4918 section 6.4 requires
			// privileges to be enforced by the normal mechanisms rather than by the
			// obscurity of the token. Someone who may not write the file could never
			// have taken the lock, so they may not release it either.
			return $canModify && ($token === $lock->getToken() || $context->getOwner() === $lock->getToken());
		}
		return false;
	}
}
