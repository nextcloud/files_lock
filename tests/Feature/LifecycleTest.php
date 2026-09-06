<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCA\Files_Trashbin\Helper;
use OCA\Files_Trashbin\Trashbin;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Model\FileLock;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use PHPUnit\Framework\Attributes\Group;

/**
 * Lock rows follow the life of their file: deletion removes them, a restored
 * file starts unlocked, and a purged file cache entry cannot keep a lock.
 */
#[Group(name: 'DB')]
class LifecycleTest extends LockTestCase {
	public function testDeletingTheFileRemovesTheLock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('deleted.txt', 'AAA');
		$id = $file->getId();
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertSame(1, $this->lockRowCount($id));

		$file->delete();
		self::assertSame(0, $this->lockRowCount($id));
	}

	/**
	 * Two mechanisms drop the lock of a deleted file: the storage wrapper and the
	 * event listener. Each has to work on its own, so that a change to one of them
	 * cannot leave a deleted file's lock behind.
	 */
	public function testStorageDeletionAloneRemovesTheLock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('storage-delete.txt', 'AAA');
		$id = $file->getId();
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertSame(1, $this->lockRowCount($id));

		// straight to the storage, so no node event is dispatched for this deletion
		self::assertTrue($file->getStorage()->unlink($file->getInternalPath()));
		self::assertSame(0, $this->lockRowCount($id), 'the storage wrapper drops the lock by itself');
	}

	public function testNodeDeletedEventAloneRemovesTheLock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('event-delete.txt', 'AAA');
		$id = $file->getId();
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertSame(1, $this->lockRowCount($id));

		// the file itself is left alone; only the event fires
		\OCP\Server::get(IEventDispatcher::class)->dispatchTyped(new NodeDeletedEvent($file));
		self::assertSame(0, $this->lockRowCount($id), 'the listener drops the lock by itself');
	}

	/**
	 * Oracle refuses more than 1000 expressions in one IN list, so bulk removal
	 * has to be chunked.
	 */
	public function testBulkRemovalHandlesMoreIdsThanOneStatementAllows(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('bulk-delete.txt', 'AAA');
		$id = $file->getId();
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->lockService()->removeLocksForFileIds(array_merge(range(900000, 901500), [$id]));
		self::assertSame(0, $this->lockRowCount($id));
	}

	public function testRestoredFileIsNotLocked(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('restored.txt', 'AAA');
		$id = $file->getId();
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$file->delete();

		$trashed = null;
		foreach (Helper::getTrashFiles('/', self::USER1) as $item) {
			if ($item->getName() === 'restored.txt') {
				$trashed = $item;
			}
		}
		if ($trashed === null) {
			self::markTestSkipped('trash bin did not keep the file');
		}
		self::assertTrue(Trashbin::restore('/restored.txt.d' . $trashed->getMtime(), 'restored.txt', $trashed->getMtime()));

		$this->lockService()->clearCache();
		$restored = $this->rootFolder->getUserFolder(self::USER1)->get('restored.txt');
		self::assertSame($id, $restored->getId());
		self::assertSame([], $this->lockManager->getLocks($id));
		self::assertSame(0, $this->lockRowCount($id));
	}

	public function testPurgingTheCacheEntryRemovesTheLock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('purged.txt', 'AAA');
		$id = $file->getId();
		$file->delete();

		// a lock that somehow survived the deletion (created before this version)
		$lock = new FileLock();
		$lock->setUserId(self::USER1)->setLockType(ILock::TYPE_USER)->setFileId($id)->setToken('files_lock/stale');
		\OCP\Server::get(LocksRequest::class)->save($lock);
		self::assertSame(1, $this->lockRowCount($id));

		$trashed = null;
		foreach (Helper::getTrashFiles('/', self::USER1) as $item) {
			if ($item->getName() === 'purged.txt') {
				$trashed = $item;
			}
		}
		if ($trashed === null) {
			self::markTestSkipped('trash bin did not keep the file');
		}
		Trashbin::delete('purged.txt', self::USER1, $trashed->getMtime());
		self::assertSame(0, $this->lockRowCount($id));
	}
}
