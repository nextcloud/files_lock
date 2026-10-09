<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCA\FilesLock\Listeners\UserDeletedListener;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\Group;

/**
 * Locks of a user are released when the account is disabled or deleted.
 */
#[Group(name: 'DB')]
class UserListenersTest extends LockTestCase {
	private IUser $user;
	private int $fileLockedByUser;
	private int $fileLockedByOtherUser;
	private int $fileLockedByApp;

	protected function setUp(): void {
		parent::setUp();
		$this->user = \OCP\Server::get(IUserManager::class)->get(self::USER1);

		$otherUserFile = $this->loginAndGetUserFolder(self::USER2)->newFile('other-user.txt', 'AAA');
		$this->lockManager->lock(new LockContext($otherUserFile, ILock::TYPE_USER, self::USER2));
		$this->fileLockedByOtherUser = $otherUserFile->getId();

		$userFolder = $this->loginAndGetUserFolder(self::USER1);
		$userFile = $userFolder->newFile('user.txt', 'AAA');
		$this->lockManager->lock(new LockContext($userFile, ILock::TYPE_USER, self::USER1));
		$this->fileLockedByUser = $userFile->getId();

		$appFile = $userFolder->newFile('app.txt', 'AAA');
		$this->lockManager->lock(new LockContext($appFile, ILock::TYPE_APP, self::USER1));
		$this->fileLockedByApp = $appFile->getId();
	}

	protected function tearDown(): void {
		$this->user->setEnabled(true);
		parent::tearDown();
	}

	private function assertOnlyLocksOfUserAreRemoved(): void {
		self::assertSame(0, $this->lockRowCount($this->fileLockedByUser));
		self::assertSame(1, $this->lockRowCount($this->fileLockedByOtherUser));
		self::assertSame(1, $this->lockRowCount($this->fileLockedByApp));
	}

	public function testLocksAreRemovedWhenUserIsDisabled(): void {
		$this->user->setEnabled(false);

		$this->assertOnlyLocksOfUserAreRemoved();
	}

	public function testLocksAreRemovedWhenUserIsDeleted(): void {
		$event = new UserDeletedEvent($this->user);
		\OCP\Server::get(UserDeletedListener::class)->handle($event);

		$this->assertOnlyLocksOfUserAreRemoved();
	}
}
