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
use OCP\Files\Folder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\Group;

/**
 * An operation on a directory must not delete or relocate a locked descendant
 * that the acting user may not write.
 */
#[Group(name: 'DB')]
class AncestorProtectionTest extends LockTestCase {
	private int $trashBefore = 0;

	private function actAs(string $userId): void {
		\OC_User::setUserId($userId);
		$this->lockService()->clearCache();
	}

	/**
	 * USER1 owns dir/sub/inner/locked.txt (locked by USER1) and shares dir with USER2.
	 *
	 * @return array{File, Folder} the locked file (USER1 view) and dir (USER2 view)
	 */
	private function sharedTree(): array {
		$root = $this->loginAndGetUserFolder(self::USER1);
		$dir = $root->newFolder('dir');
		$inner = $dir->newFolder('sub')->newFolder('inner');
		$locked = $inner->newFile('locked.txt', 'important');
		$this->shareWith($dir, self::USER1, self::USER2, 31);
		$this->lockManager->lock(new LockContext($locked, ILock::TYPE_USER, self::USER1));
		$this->trashBefore = $this->trashEntries();

		\OC_Util::setupFS(self::USER2);
		$this->actAs(self::USER2);
		return [$locked, $this->rootFolder->getUserFolder(self::USER2)->get('dir')];
	}

	private function trashEntries(): int {
		try {
			$trash = $this->rootFolder->getUserFolder(self::USER1)->getParent()->get('files_trashbin/files');
			return count($trash->getDirectoryListing());
		} catch (NotFoundException) {
			return 0;
		}
	}

	private function assertIntact(File $locked): void {
		$this->actAs(self::USER1);
		$this->lockService()->clearCache();
		$file = $this->rootFolder->getUserFolder(self::USER1)->get('dir/sub/inner/locked.txt');
		self::assertSame($locked->getId(), $file->getId());
		self::assertSame('important', $file->getContent());
		self::assertSame(1, $this->lockRowCount($locked->getId()));
		self::assertSame($this->trashBefore, $this->trashEntries(), 'nothing was moved to the trash bin');
		$this->actAs(self::USER2);
		$this->lockService()->clearCache();
	}

	private function assertLocked(callable $operation, File $locked): void {
		try {
			$operation();
			self::fail('operation on an ancestor of a locked file should be refused');
		} catch (LockedException) {
		}
		$this->assertIntact($locked);
	}

	public function testDirectOperationsOnLockedFileAreRefused(): void {
		[$locked, $dir] = $this->sharedTree();
		$file = $dir->get('sub/inner/locked.txt');
		$this->assertLocked(fn () => $file->delete(), $locked);
		$this->assertLocked(fn () => $file->move($dir->getPath() . '/moved.txt'), $locked);
	}

	public function testParentOperationsAreRefused(): void {
		[$locked, $dir] = $this->sharedTree();
		$inner = $dir->get('sub/inner');
		$this->assertLocked(fn () => $inner->delete(), $locked);
		$this->assertLocked(fn () => $inner->move($dir->getPath() . '/inner-moved'), $locked);
		$this->assertLocked(fn () => $inner->move($dir->getPath() . '/sub/renamed'), $locked);
	}

	public function testNestedParentOperationsAreRefused(): void {
		[$locked, $dir] = $this->sharedTree();
		$sub = $dir->get('sub');
		$this->assertLocked(fn () => $sub->delete(), $locked);
		$this->assertLocked(fn () => $sub->move($dir->getPath() . '/sub-moved'), $locked);
	}

	public function testStorageLevelOperationsAreRefused(): void {
		// equivalent of a deployment without the trash bin: the storage is hit directly
		[$locked, $dir] = $this->sharedTree();
		$storage = $dir->getStorage();
		$internal = $dir->getInternalPath();
		$subPath = ($internal === '' ? '' : $internal . '/') . 'sub';
		$this->assertLocked(fn () => $storage->rmdir($subPath), $locked);
		$this->assertLocked(fn () => $storage->rename($subPath, $subPath . '-moved'), $locked);
		self::assertTrue($storage->file_exists($subPath . '/inner/locked.txt'));
	}

	public function testLockOwnerMayOperateOnAncestors(): void {
		[$locked] = $this->sharedTree();
		$this->actAs(self::USER1);
		$root = $this->rootFolder->getUserFolder(self::USER1);
		$root->get('dir/sub')->move($root->getPath() . '/dir/sub-moved');
		self::assertSame('important', $root->get('dir/sub-moved/inner/locked.txt')->getContent());
		self::assertSame(1, $this->lockRowCount($locked->getId()), 'a move keeps the lock');
		$root->get('dir/sub-moved')->delete();
		self::assertSame(0, $this->lockRowCount($locked->getId()), 'deleting the file removes its lock');
	}

	public function testAncestorProtectionOnNonHomeMount(): void {
		$storage = new Temporary([]);
		$this->loginAndGetUserFolder(self::USER1);
		\OC_Util::setupFS(self::USER2);
		Filesystem::mount($storage, [], '/' . self::USER1 . '/files/ext/');
		Filesystem::mount($storage, [], '/' . self::USER2 . '/files/ext/');
		$locked = $this->rootFolder->getUserFolder(self::USER1)->get('ext')->newFolder('sub')->newFile('locked.txt', 'important');
		$this->lockManager->lock(new LockContext($locked, ILock::TYPE_USER, self::USER1));

		$this->actAs(self::USER2);
		$sub = $this->rootFolder->getUserFolder(self::USER2)->get('ext/sub');
		try {
			$sub->delete();
			self::fail('deleting the parent on a non-home mount must be refused');
		} catch (LockedException) {
		}
		try {
			$sub->move($this->rootFolder->getUserFolder(self::USER2)->getPath() . '/ext/sub2');
			self::fail('moving the parent on a non-home mount must be refused');
		} catch (LockedException) {
		}
		self::assertSame('important', $this->rootFolder->getUserFolder(self::USER2)->get('ext/sub/locked.txt')->getContent());
		self::assertSame(1, $this->lockRowCount($locked->getId()));
	}
}
