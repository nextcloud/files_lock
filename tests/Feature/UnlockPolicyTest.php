<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\Files\Filesystem;
use OC\Files\Storage\Temporary;
use OC\Files\Storage\Wrapper\Wrapper;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCP\Files\File;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\PreConditionNotMetException;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Storage that reports the current user as owner of every file, the way group
 * folders, Collectives and external storages do.
 */
class OwnerIsViewerStorage extends Wrapper {
	#[\Override]
	public function getOwner(string $path): string|false {
		return \OC_User::getUser() ?: false;
	}
}

/**
 * Invariants B, E and F for releasing locks through the PHP API.
 */
#[Group(name: 'DB')]
class UnlockPolicyTest extends LockTestCase {
	private const string TOKEN = 'files_lock/unlock-token';

	private function actAs(string $userId): void {
		\OC_User::setUserId($userId);
		$this->lockService()->clearCache();
	}

	private function assertRefused(callable $unlock, int $fileId): void {
		try {
			$unlock();
			self::fail('unlock should have been refused');
		} catch (UnauthorizedUnlockException|PreConditionNotMetException) {
		}
		self::assertSame(1, $this->lockRowCount($fileId));
	}

	/**
	 * @return array{File, File, File} as USER1 (file owner), USER2 (writer), USER3 (writer)
	 */
	private function sharedViews(string $name): array {
		$owner = $this->sharedFile($name, 19, 19);
		\OC_Util::setupFS(self::USER2);
		\OC_Util::setupFS(self::USER3);
		return [
			$owner,
			$this->rootFolder->getUserFolder(self::USER2)->get($name),
			$this->rootFolder->getUserFolder(self::USER3)->get($name),
		];
	}

	public function testUserLockRelease(): void {
		[$owner, $creator, $other] = $this->sharedViews('user.txt');
		$this->actAs(self::USER2);
		$this->lockManager->lock(new LockContext($creator, ILock::TYPE_USER, self::USER2));
		$id = $owner->getId();

		$this->actAs(self::USER3);
		$this->assertRefused(fn () => $this->lockManager->unlock(new LockContext($other, ILock::TYPE_USER, self::USER3)), $id);

		$this->actAs(self::USER2);
		$this->lockManager->unlock(new LockContext($creator, ILock::TYPE_USER, self::USER2));
		self::assertSame(0, $this->lockRowCount($id));

		$this->lockManager->lock(new LockContext($creator, ILock::TYPE_USER, self::USER2));
		$this->actAs(self::USER1);
		$this->lockManager->unlock(new LockContext($owner, ILock::TYPE_USER, self::USER1));
		self::assertSame(0, $this->lockRowCount($id), 'the file owner overrides a user lock on a file of their home storage');
	}

	public function testTokenLockRelease(): void {
		[$owner, $creator, $other] = $this->sharedViews('token.txt');
		$this->actAs(self::USER2);
		$lock = $this->lockService()->acquire(new LockContext($creator, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);
		$id = $owner->getId();

		$this->actAs(self::USER3);
		$this->assertRefused(fn () => $this->lockManager->unlock(new LockContext($other, ILock::TYPE_TOKEN, self::USER3)), $id);

		// the recorded owner releases through the public API without the token (N-01)
		$this->actAs(self::USER2);
		$this->lockManager->unlock(new LockContext($creator, ILock::TYPE_TOKEN, self::USER2));
		self::assertSame(0, $this->lockRowCount($id));

		// whoever presents the token releases (RFC 4918 section 6.5 semantics of the native path)
		$this->lockService()->acquire(new LockContext($creator, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);
		$this->actAs(self::USER3);
		$this->lockManager->unlock(new LockContext($other, ILock::TYPE_TOKEN, $lock->getToken()));
		self::assertSame(0, $this->lockRowCount($id));

		$this->actAs(self::USER2);
		$this->lockService()->acquire(new LockContext($creator, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);
		$this->actAs(self::USER3);
		$this->lockService()->unlock(new LockContext($other, ILock::TYPE_TOKEN, self::USER3), false, $lock->getToken());
		self::assertSame(0, $this->lockRowCount($id));

		// the file owner overrides a stale client lock
		$this->actAs(self::USER2);
		$this->lockService()->acquire(new LockContext($creator, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);
		$this->actAs(self::USER1);
		$this->lockManager->unlock(new LockContext($owner, ILock::TYPE_USER, self::USER1));
		self::assertSame(0, $this->lockRowCount($id));
	}

	/**
	 * The token is publicly readable, so it cannot be the whole authorization:
	 * a user who may not write the file could never have taken the lock and may
	 * not release it either.
	 */
	public function testTokenLockNeedsWritePermissionToRelease(): void {
		$owner = $this->sharedFile('token-readonly.txt', 19, 1);
		\OC_Util::setupFS(self::USER2);
		\OC_Util::setupFS(self::USER3);
		$id = $owner->getId();

		$this->actAs(self::USER2);
		$writerView = $this->rootFolder->getUserFolder(self::USER2)->get('token-readonly.txt');
		$lock = $this->lockService()->acquire(new LockContext($writerView, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);

		// the read-only recipient can see the token but may not act on it
		$this->actAs(self::USER3);
		$readerView = $this->rootFolder->getUserFolder(self::USER3)->get('token-readonly.txt');
		$this->assertRefused(
			fn (): FileLock => $this->lockService()->unlock(new LockContext($readerView, ILock::TYPE_TOKEN, self::USER3), false, $lock->getToken()),
			$id
		);

		// someone who may write the file and presents the token still releases it
		$this->actAs(self::USER2);
		$this->lockService()->unlock(new LockContext($writerView, ILock::TYPE_TOKEN, self::USER3), false, $lock->getToken());
		self::assertSame(0, $this->lockRowCount($id));
	}

	public function testAppLockRelease(): void {
		[$owner, $writer] = $this->sharedViews('app.txt');
		$this->lockManager->lock(new LockContext($owner, ILock::TYPE_APP, 'text'));
		$id = $owner->getId();

		$this->actAs(self::USER2);
		$this->assertRefused(fn () => $this->lockManager->unlock(new LockContext($writer, ILock::TYPE_USER, self::USER2)), $id);
		$this->assertRefused(fn () => $this->lockManager->unlock(new LockContext($writer, ILock::TYPE_APP, 'other')), $id);

		$this->lockManager->unlock(new LockContext($writer, ILock::TYPE_APP, 'text'));
		self::assertSame(0, $this->lockRowCount($id));

		$this->lockManager->lock(new LockContext($owner, ILock::TYPE_APP, 'text'));
		$this->actAs(self::USER1);
		$this->lockManager->unlock(new LockContext($owner, ILock::TYPE_USER, self::USER1));
		self::assertSame(0, $this->lockRowCount($id), 'the file owner overrides an app lock');
	}

	public function testOwnerOverrideIsLimitedToHomeStorage(): void {
		$storage = new OwnerIsViewerStorage(['storage' => new Temporary([])]);
		$this->loginAndGetUserFolder(self::USER1);
		\OC_Util::setupFS(self::USER2);
		Filesystem::mount($storage, [], '/' . self::USER1 . '/files/shared-mount/');
		Filesystem::mount($storage, [], '/' . self::USER2 . '/files/shared-mount/');
		$file = $this->rootFolder->getUserFolder(self::USER2)->get('shared-mount')->newFile('doc.txt', 'AAA');

		$this->actAs(self::USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER2));

		$this->actAs(self::USER1);
		$asUser1 = $this->rootFolder->getUserFolder(self::USER1)->get('shared-mount/doc.txt');
		self::assertSame(self::USER1, $asUser1->getOwner()?->getUID(), 'the mount reports the viewer as owner');
		$this->assertRefused(fn () => $this->lockManager->unlock(new LockContext($asUser1, ILock::TYPE_USER, self::USER1)), $file->getId());

		$this->lockService()->forceUnlock($file->getId());
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testForcedUnlockDoesNotNeedOwnerAccess(): void {
		[$owner, $creator] = $this->sharedViews('force.txt');
		$this->actAs(self::USER2);
		$this->lockManager->lock(new LockContext($creator, ILock::TYPE_USER, self::USER2));

		$shareManager = \OCP\Server::get(IShareManager::class);
		foreach ($shareManager->getSharesBy(self::USER1, \OCP\Share\IShare::TYPE_USER, $owner) as $share) {
			$shareManager->deleteShare($share);
		}
		$this->actAs('');

		$removed = $this->lockService()->unlockFile($owner->getId(), '', true);
		self::assertSame(self::USER2, $removed->getOwner());
		self::assertSame(0, $this->lockRowCount($owner->getId()));

		$this->expectException(LockNotFoundException::class);
		$this->lockService()->forceUnlock($owner->getId());
	}

	public function testUnlockWithoutLockReportsNotFound(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('none.txt', 'AAA');
		$this->expectException(PreConditionNotMetException::class);
		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::USER1));
	}
}
