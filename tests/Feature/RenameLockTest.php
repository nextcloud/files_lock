<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Lock\ManuallyLockedException;
use PHPUnit\Framework\Attributes\Group;

#[Group(name: 'DB')]
class RenameLockTest extends LockTestCase {

	public function testRenameLockedFileInSharedFolderThrows(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$shared = $folder->newFolder('shared');
		$file = $shared->newFile('file.txt', 'AAA');
		$this->shareWith($shared, self::USER1, self::USER2, 31);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$recipientFolder = $this->loginAndGetUserFolder(self::USER2);
		try {
			$recipientFolder->get('shared/file.txt')
				->move($recipientFolder->getPath() . '/shared/moved.txt');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException) {
			self::assertNotNull($recipientFolder->get('shared/file.txt'));
			self::assertFalse($recipientFolder->nodeExists('shared/moved.txt'));
		}

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$recipientFolder->get('shared/file.txt')
			->move($recipientFolder->getPath() . '/shared/moved.txt');
		self::assertNotNull($recipientFolder->get('shared/moved.txt'));
		self::assertFalse($recipientFolder->nodeExists('shared/file.txt'));
	}

	public function testRenameAppLockedFileThrows(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$file = $folder->newFile('file.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_APP, 'files_text_editing'));

		try {
			$folder->get('file.txt')->move($folder->getPath() . '/moved.txt');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException) {
			self::assertNotNull($folder->get('file.txt'));
			self::assertFalse($folder->nodeExists('moved.txt'));
		}

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_APP, 'files_text_editing'));
		$folder->get('file.txt')->move($folder->getPath() . '/moved.txt');
		self::assertNotNull($folder->get('moved.txt'));
	}

	public function testRenameUnlockedFileWorks(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$shared = $folder->newFolder('shared');
		$file = $shared->newFile('file.txt', 'AAA');
		$this->shareWith($shared, self::USER1, self::USER2, 31);
		self::assertSame(0, $this->lockRowCount($file->getId()));

		$recipientFolder = $this->loginAndGetUserFolder(self::USER2);
		$recipientFolder->get('shared/file.txt')
			->move($recipientFolder->getPath() . '/shared/moved.txt');
		self::assertNotNull($recipientFolder->get('shared/moved.txt'));
	}
}
