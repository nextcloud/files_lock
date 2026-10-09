<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Locks of a share recipient are released when the share is removed and the
 * locked file is no longer accessible to them.
 */
#[Group(name: 'DB')]
class ShareDeletedListenerTest extends LockTestCase {
	private IShareManager $shareManager;

	protected function setUp(): void {
		parent::setUp();
		$this->shareManager = \OCP\Server::get(IShareManager::class);
	}

	public function testLockIsRemovedWhenShareIsDeleted(): void {
		$sharedFile = $this->loginAndGetUserFolder(self::USER1)->newFile('shared.txt', 'AAA');
		$share = $this->shareWith($sharedFile, self::USER1, self::USER2);
		$recipientFolder = $this->loginAndGetUserFolder(self::USER2);
		$receivedFile = $recipientFolder->get('shared.txt');
		$ownFile = $recipientFolder->newFile('own.txt', 'AAA');
		$this->lockManager->lock(new LockContext($receivedFile, ILock::TYPE_USER, self::USER2));
		$this->lockManager->lock(new LockContext($ownFile, ILock::TYPE_USER, self::USER2));

		$this->loginAndGetUserFolder(self::USER1);
		$this->shareManager->deleteShare($share);

		self::assertSame(0, $this->lockRowCount($sharedFile->getId()));
		self::assertSame(1, $this->lockRowCount($ownFile->getId()));
	}

	public function testLockIsKeptWhileFileIsStillShared(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1)->newFolder('shared');
		$file = $folder->newFile('file.txt', 'AAA');
		$folderShare = $this->shareWith($folder, self::USER1, self::USER2, 31);
		$fileShare = $this->shareWith($file, self::USER1, self::USER2);
		$receivedFile = $this->loginAndGetUserFolder(self::USER2)->get('shared/file.txt');
		$this->lockManager->lock(new LockContext($receivedFile, ILock::TYPE_USER, self::USER2));

		$this->loginAndGetUserFolder(self::USER1);
		$this->shareManager->deleteShare($fileShare);

		self::assertSame(1, $this->lockRowCount($file->getId()));

		$this->shareManager->deleteShare($folderShare);

		self::assertSame(0, $this->lockRowCount($file->getId()));
	}
}
