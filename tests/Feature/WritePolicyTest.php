<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\Files\Filesystem;
use OC\Files\Storage\Temporary;
use OCP\Files\File;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\NotPermittedException;
use OCP\Lock\ManuallyLockedException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Invariants C and F for writes: the same policy applies to every lock type on
 * every storage the file can be reached through (home, share, non-home mount).
 *
 * Group folders are not part of the CI environment; a Temporary storage mounted
 * for two users exercises the same non-home code path (storage-local paths that
 * do not start with "files/").
 */
#[Group(name: 'DB')]
class WritePolicyTest extends LockTestCase {
	private const string TOKEN = 'files_lock/test-token';

	private function actAs(string $userId): void {
		if ($userId === '') {
			\OCP\Server::get(\OCP\IUserSession::class)->setUser(null);
		} else {
			\OC_User::setUserId($userId);
		}
		$this->lockService()->clearCache();
	}

	private function assertBlocked(File $file): void {
		try {
			$file->putContent('blocked');
			self::fail('write should have been blocked by the lock');
		} catch (ManuallyLockedException) {
		}
	}

	private function assertWritable(File $file, string $content): void {
		$file->putContent($content);
		self::assertSame($content, $file->getContent());
	}

	/**
	 * @return array{File, File, File} the file as seen by USER1 (owner), USER2 (writer) and USER3 (read-only)
	 */
	private function homeFile(string $name): array {
		$owner = $this->sharedFile($name, 19, 1);
		\OC_Util::setupFS(self::USER2);
		\OC_Util::setupFS(self::USER3);
		$writer = $this->rootFolder->getUserFolder(self::USER2)->get($name);
		$reader = $this->rootFolder->getUserFolder(self::USER3)->get($name);
		return [$owner, $writer, $reader];
	}

	/**
	 * @return array{File, File} the file as seen by USER1 and USER2 on a shared non-home mount
	 */
	private function mountedFile(string $name): array {
		$storage = new Temporary([]);
		$this->loginAndGetUserFolder(self::USER1);
		\OC_Util::setupFS(self::USER2);
		Filesystem::mount($storage, [], '/' . self::USER1 . '/files/ext/');
		Filesystem::mount($storage, [], '/' . self::USER2 . '/files/ext/');
		$file = $this->rootFolder->getUserFolder(self::USER1)->get('ext')->newFile($name, 'AAA');
		$other = $this->rootFolder->getUserFolder(self::USER2)->get('ext/' . $name);
		self::assertSame($file->getId(), $other->getId());
		return [$file, $other];
	}

	public function testUserLockOnHomeFile(): void {
		[$owner, $writer, $reader] = $this->homeFile('user-home.txt');
		$this->lockManager->lock(new LockContext($owner, ILock::TYPE_USER, self::USER1));

		$this->actAs(self::USER1);
		$this->assertWritable($owner, 'owner');
		$this->actAs(self::USER2);
		$this->assertBlocked($writer);
		$this->actAs(self::USER3);
		$this->expectException(NotPermittedException::class);
		$reader->putContent('reader');
	}

	public function testUserLockOnNonHomeMount(): void {
		[$file, $other] = $this->mountedFile('user-ext.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->actAs(self::USER2);
		self::assertCount(1, $this->lockManager->getLocks($other->getId()));
		$this->assertBlocked($other);
		$this->actAs(self::USER1);
		$this->assertWritable($file, 'owner');
	}

	public function testAppLockOnHomeAndMount(): void {
		[$owner, $writer] = $this->homeFile('app-home.txt');
		$scope = new LockContext($owner, ILock::TYPE_APP, 'text');
		$this->lockManager->lock($scope);

		$this->actAs(self::USER1);
		$this->assertBlocked($owner);
		$this->actAs(self::USER2);
		$this->assertBlocked($writer);
		$this->lockManager->runInScope($scope, fn () => $this->assertWritable($writer, 'in scope'));
		$this->lockManager->runInScope(new LockContext($owner, ILock::TYPE_APP, 'other'), fn () => $this->assertBlocked($writer));

		[$file, $other] = $this->mountedFile('app-ext.txt');
		$scope = new LockContext($file, ILock::TYPE_APP, 'text');
		$this->lockManager->lock($scope);
		$this->actAs(self::USER2);
		$this->assertBlocked($other);
		$this->lockManager->runInScope($scope, fn () => $this->assertWritable($other, 'in scope'));
	}

	public function testTokenLockRequiresTokenAndPrincipal(): void {
		[$owner, $writer, $reader] = $this->homeFile('token-home.txt');
		$this->actAs(self::USER1);
		$lock = $this->lockService()->acquire(new LockContext($owner, ILock::TYPE_TOKEN, self::USER1), null, self::TOKEN);
		$tokenScope = new LockContext($owner, ILock::TYPE_TOKEN, $lock->getToken());

		// creator without the token
		$this->assertBlocked($owner);
		// creator presenting the token through the lock scope
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertWritable($owner, 'creator with token'));
		// creator presenting the token the way the WebDAV adapter does
		$this->lockService()->presentToken($lock->getToken());
		$this->assertWritable($owner, 'creator via presented token');

		// another writer, with and without the token
		$this->actAs(self::USER2);
		$this->assertBlocked($writer);
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertBlocked($writer));

		// file owner is the creator here; a read-only user is stopped by permissions
		$this->actAs(self::USER3);
		try {
			$reader->putContent('reader');
			self::fail('read-only user must not write');
		} catch (NotPermittedException) {
		}

		// a sessionless trusted caller presenting the token acts for the creator
		$this->actAs('');
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertWritable($owner, 'internal with token'));
		$this->assertBlocked($owner);
	}

	public function testTokenLockCreatedByRecipientBlocksTheFileOwner(): void {
		[$owner, $writer] = $this->homeFile('token-recipient.txt');
		$this->actAs(self::USER2);
		$lock = $this->lockService()->acquire(new LockContext($writer, ILock::TYPE_TOKEN, self::USER2), null, self::TOKEN);
		$tokenScope = new LockContext($writer, ILock::TYPE_TOKEN, $lock->getToken());

		$this->actAs(self::USER1);
		$this->assertBlocked($owner);
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertBlocked($owner));

		$this->actAs(self::USER2);
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertWritable($writer, 'recipient with token'));
	}

	public function testTokenLockOnNonHomeMount(): void {
		[$file, $other] = $this->mountedFile('token-ext.txt');
		$this->actAs(self::USER1);
		$lock = $this->lockService()->acquire(new LockContext($file, ILock::TYPE_TOKEN, self::USER1), null, self::TOKEN);
		$tokenScope = new LockContext($file, ILock::TYPE_TOKEN, $lock->getToken());

		$this->actAs(self::USER2);
		$this->assertBlocked($other);
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertBlocked($other));
		$this->actAs(self::USER1);
		$this->assertBlocked($file);
		$this->lockManager->runInScope($tokenScope, fn () => $this->assertWritable($file, 'creator with token'));
	}
}
