<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCA\FilesLock\Cron\Unlock;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Model\FileLock;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Lock\ManuallyLockedException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Invariant D: expires_at is the only expiry model (ETA, refresh, cron, validity).
 */
#[Group(name: 'DB')]
class ExpirationTest extends LockTestCase {
	private function runCron(): void {
		$job = \OCP\Server::get(Unlock::class);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}

	public function testFiniteLockHasAbsoluteExpiry(): void {
		$this->setLockTimeoutMinutes(15);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('finite.txt', 'AAA');
		$lock = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		self::assertSame($this->time + 15 * 60, $lock->getExpiresAt());
		self::assertSame(15 * 60, $lock->getTimeout());
		self::assertSame(15 * 60, $lock->getETA());
		self::assertSame($this->time + 15 * 60, $this->storedLock($file->getId())?->getExpiresAt());
	}

	public function testInfiniteLock(): void {
		$this->setLockTimeoutMinutes(-1);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('infinite.txt', 'AAA');
		$lock = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		self::assertNull($lock->getExpiresAt());
		self::assertSame(FileLock::ETA_INFINITE, $lock->getETA());
		self::assertSame(FileLock::ETA_INFINITE, $lock->getTimeout());
		$this->toTheFuture(365 * 24 * 3600);
		self::assertCount(1, $this->lockManager->getLocks($file->getId()));
		self::assertSame([], $this->lockService()->getExpiredLocks());
	}

	public function testRefreshMovesExpiry(): void {
		$this->setLockTimeoutMinutes(30);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('refresh.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->toTheFuture(20 * 60);
		$refreshed = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertSame($this->time + 30 * 60, $refreshed->getExpiresAt());
		self::assertSame(30 * 60, $refreshed->getETA());
		self::assertSame(50 * 60, $refreshed->getTimeout(), 'lifetime counted from creation');
		self::assertSame($this->time + 30 * 60, $this->storedLock($file->getId())?->getExpiresAt());
	}

	public function testCronKeepsRefreshedLockAndRemovesExpiredOne(): void {
		$this->setLockTimeoutMinutes(30);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('cron.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->toTheFuture(20 * 60);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->toTheFuture(11 * 60);
		self::assertSame([], $this->lockService()->getExpiredLocks(), 'a refreshed lock is not expired 31 minutes after creation');
		$this->runCron();
		self::assertSame(1, $this->lockRowCount($file->getId()));

		$this->toTheFuture(20 * 60);
		self::assertCount(1, $this->lockService()->getExpiredLocks());
		$this->runCron();
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testExpiredLockNoLongerBlocksAndIsRemovedOnRead(): void {
		$this->setLockTimeoutMinutes(30);
		$file = $this->sharedFile('expire-write.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$shared = $this->loginAndGetUserFolder(self::USER2)->get('expire-write.txt');
		try {
			$shared->putContent('BBB');
			self::fail('lock should block before expiry');
		} catch (ManuallyLockedException) {
		}

		$this->toTheFuture(31 * 60);
		self::assertSame([], $this->lockManager->getLocks($file->getId()));
		$shared->putContent('CCC');
		self::assertSame('CCC', $shared->getContent());
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testRefreshAfterExpiryCreatesANewLock(): void {
		$this->setLockTimeoutMinutes(10);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('late.txt', 'AAA');
		$first = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$this->toTheFuture(11 * 60);
		$second = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertNotSame($first->getId(), $second->getId());
		self::assertSame($this->time + 10 * 60, $second->getExpiresAt());
	}

	public function testRefreshRacingCronSurvives(): void {
		$this->setLockTimeoutMinutes(30);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('race-cron.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		// cron selects its batch at t+29, the client refreshes at the same moment, cron deletes afterwards
		$this->toTheFuture(29 * 60);
		$batch = $this->lockService()->getExpiredLocks(1000);
		self::assertSame([], $batch);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$this->lockService()->removeLocks($batch);
		self::assertSame(1, $this->lockRowCount($file->getId()));

		// a refresh that lands after cron selected an expired batch replaces the expired row,
		// and cron's deletion by id cannot remove the replacement
		$this->toTheFuture(31 * 60);
		$batch = $this->lockService()->getExpiredLocks(1000);
		self::assertCount(1, $batch);
		$fresh = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$this->lockService()->removeLocks($batch);
		self::assertSame(1, $this->lockRowCount($file->getId()));
		self::assertSame($fresh->getId(), $this->storedLock($file->getId())?->getId());
	}

	/**
	 * The cleanup reads a batch of expired locks and deletes it afterwards. A
	 * request whose clock was read before the expiry can refresh one of them in
	 * between, which reuses the same row; deleting that row by id would drop a
	 * lock that is valid again.
	 */
	public function testLockRefreshedAfterTheCleanupBatchWasReadSurvives(): void {
		$this->setLockTimeoutMinutes(30);
		$this->toTheFuture(0);
		$t0 = $this->time;
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$refreshedFile = $folder->newFile('cron-race.txt', 'AAA');
		$staleFile = $folder->newFile('cron-stale.txt', 'AAA');
		$lock = $this->lockManager->lock(new LockContext($refreshedFile, ILock::TYPE_USER, self::USER1));
		$this->lockManager->lock(new LockContext($staleFile, ILock::TYPE_USER, self::USER1));

		// the background job wakes up after the expiry and reads its batch
		$this->atTime($t0 + 1801);
		$batch = $this->lockService()->getExpiredLocks(1000);
		self::assertCount(2, $batch);

		// a request that read its clock a moment before the expiry refreshes one
		// of them, updating the very row the job is about to delete
		$this->atTime($t0 + 1799);
		$refreshed = $this->lockManager->lock(new LockContext($refreshedFile, ILock::TYPE_USER, self::USER1));
		self::assertSame($lock->getId(), $refreshed->getId(), 'the refresh updated the same row');
		self::assertSame($t0 + 1799 + 1800, $refreshed->getExpiresAt());

		// the job now deletes the batch it read earlier
		$this->atTime($t0 + 1802);
		$this->lockService()->removeLocksIfExpired($batch);

		self::assertSame(1, $this->lockRowCount($refreshedFile->getId()), 'the refreshed lock survives its own cleanup batch');
		self::assertSame(0, $this->lockRowCount($staleFile->getId()), 'a genuinely expired lock is still removed');
	}

	/**
	 * expires_at is the last second the lock is invalid, not the last second it
	 * is valid, and every layer has to agree on that.
	 */
	public function testTheExpirySecondItselfCountsAsExpired(): void {
		$this->setLockTimeoutMinutes(10);
		$this->toTheFuture(0);
		$t0 = $this->time;
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$file = $folder->newFile('boundary.txt', 'AAA');
		$other = $folder->newFile('boundary-2.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$this->lockManager->lock(new LockContext($other, ILock::TYPE_USER, self::USER1));

		// one second earlier nothing is expired anywhere
		$this->atTime($t0 + 599);
		self::assertSame([], $this->lockService()->getExpiredLocks(), 'not expired one second early');
		self::assertCount(1, $this->lockManager->getLocks($file->getId()));

		// on the second itself the query, the targeted delete and the model agree
		$this->atTime($t0 + 600);
		self::assertCount(2, $this->lockService()->getExpiredLocks(), 'the cleanup query includes the boundary second');
		self::assertTrue(
			\OCP\Server::get(LocksRequest::class)->removeExpired($other->getId(), $t0 + 600),
			'the targeted delete includes the boundary second'
		);
		self::assertSame([], $this->lockManager->getLocks($file->getId()), 'the model treats the boundary second as expired');
		self::assertSame(0, $this->lockRowCount($file->getId()), 'and reading it away removed the row');
	}

	public function testAcquireWithExplicitTimeout(): void {
		$this->setLockTimeoutMinutes(-1);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('explicit.txt', 'AAA');
		$lock = $this->lockService()->acquire(new LockContext($file, ILock::TYPE_TOKEN, self::USER1), 600, 'native-token');
		self::assertSame($this->time + 600, $lock->getExpiresAt());
		self::assertSame('native-token', $lock->getToken());

		$refreshed = $this->lockService()->acquire(new LockContext($file, ILock::TYPE_TOKEN, 'native-token'), FileLock::ETA_INFINITE);
		self::assertSame($lock->getId(), $refreshed->getId());
		self::assertNull($refreshed->getExpiresAt());
	}
}
