<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockConflictException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\Group;

/**
 * Invariant A: at most one active lock per file, enforced by the database.
 */
#[Group(name: 'DB')]
class AcquisitionTest extends LockTestCase {
	private const string RACE_USER1 = 'race-user1';
	private const string RACE_USER2 = 'race-user2';
	private const int RACE_ROUNDS = 10;

	public function testDatabaseRejectsSecondLockRow(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('dup.txt', 'AAA');
		$request = \OCP\Server::get(LocksRequest::class);

		$first = FileLock::fromLockScope(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$first->setToken('files_lock/dup-1');
		$request->save($first);
		self::assertGreaterThan(0, $first->getId());

		$second = FileLock::fromLockScope(new LockContext($file, ILock::TYPE_USER, self::USER2));
		$second->setToken('files_lock/dup-2');
		try {
			$request->save($second);
			self::fail('second row for the same file must be rejected');
		} catch (LockConflictException) {
		}
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}

	/**
	 * The bulk read reports every id it was asked about, so a caller can index the
	 * result by file id. A file with no lock is reported as false, not left out.
	 */
	public function testBulkReadReportsEveryRequestedId(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1);
		$locked = $folder->newFile('bulk-locked.txt', 'AAA');
		$free = $folder->newFile('bulk-free.txt', 'AAA');
		$this->lockManager->lock(new LockContext($locked, ILock::TYPE_USER, self::USER1));
		$this->lockService()->clearCache();

		$locks = $this->lockService()->getLockForNodeIds([$locked->getId(), $free->getId()]);

		self::assertArrayHasKey($free->getId(), $locks, 'a file with no lock must still be reported');
		self::assertFalse($locks[$free->getId()]);
		self::assertInstanceOf(FileLock::class, $locks[$locked->getId()]);
	}

	public function testConflictReportsWinningLock(): void {
		$file = $this->sharedFile('conflict.txt');
		$mine = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$shared = $this->loginAndGetUserFolder(self::USER2)->get('conflict.txt');
		try {
			$this->lockManager->lock(new LockContext($shared, ILock::TYPE_USER, self::USER2));
			self::fail('expected OwnerLockedException');
		} catch (OwnerLockedException $e) {
			self::assertSame($mine->getId(), $e->getLock()->getId());
			self::assertSame(self::USER1, $e->getLock()->getOwner());
		}
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}

	public function testRefreshKeepsTheSameRow(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('refresh.txt', 'AAA');
		$first = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$second = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		self::assertSame($first->getId(), $second->getId());
		self::assertSame($first->getToken(), $second->getToken());
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}

	public function testExpiredLockIsReplaced(): void {
		$this->setLockTimeoutMinutes(10);
		$file = $this->sharedFile('expired.txt');
		$old = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$this->toTheFuture(11 * 60);

		$shared = $this->loginAndGetUserFolder(self::USER2)->get('expired.txt');
		$new = $this->lockManager->lock(new LockContext($shared, ILock::TYPE_USER, self::USER2));
		self::assertNotSame($old->getId(), $new->getId());
		self::assertSame(self::USER2, $new->getOwner());
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}

	public function testFoldersCannotBeLocked(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1)->newFolder('a-folder');
		$this->expectException(NotFileException::class);
		$this->lockManager->lock(new LockContext($folder, ILock::TYPE_USER, self::USER1));
	}

	public function testLockingNeedsUpdatePermission(): void {
		$file = $this->sharedFile('readonly.txt', 1);
		$shared = $this->loginAndGetUserFolder(self::USER2)->get('readonly.txt');
		try {
			$this->lockManager->lock(new LockContext($shared, ILock::TYPE_USER, self::USER2));
			self::fail('expected UnauthorizedUnlockException');
		} catch (UnauthorizedUnlockException) {
		}
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	/**
	 * Two independent PHP processes race for the same file: exactly one wins,
	 * the other gets a conflict, and the table holds exactly one row.
	 */
	public function testConcurrentAcquisitionYieldsOneLock(): void {
		// the child processes boot a normal server, so the users must live in the database backend
		$userManager = \OCP\Server::get(IUserManager::class);
		$databaseBackend = new \OC\User\Database();
		$userManager->registerBackend($databaseBackend);
		foreach ([self::RACE_USER1, self::RACE_USER2] as $uid) {
			if (!$databaseBackend->userExists($uid)) {
				$databaseBackend->createUser($uid, 'race-password-' . $uid);
			}
		}

		try {
			$file = $this->loginAndGetUserFolder(self::RACE_USER1)->newFile('race.txt', 'AAA');
			$shareManager = \OCP\Server::get(IShareManager::class);
			$share = $shareManager->newShare();
			$share->setNode($file)->setSharedBy(self::RACE_USER1)->setSharedWith(self::RACE_USER2)
				->setShareType(IShare::TYPE_USER)->setPermissions(19);
			$share = $shareManager->createShare($share);
			$share->setStatus(IShare::STATUS_ACCEPTED);
			$shareManager->updateShare($share);

			$script = __DIR__ . '/fixtures/concurrent-lock.php';
			$locked = $conflicts = 0;
			for ($round = 0; $round < self::RACE_ROUNDS; $round++) {
				$this->clearLocks();
				$startAt = microtime(true) + 3;
				$outputs = $this->runConcurrently([
					[PHP_BINARY, $script, self::RACE_USER1, (string)$file->getId(), (string)$startAt],
					[PHP_BINARY, $script, self::RACE_USER2, (string)$file->getId(), (string)$startAt],
				]);
				$results = array_map(trim(...), $outputs);
				sort($results);
				self::assertSame(['CONFLICT', 'LOCKED'], $results, 'round ' . $round . ': ' . implode(' | ', $results));
				self::assertSame(1, $this->lockRowCount($file->getId()), 'round ' . $round);
				$locked += count(array_keys($results, 'LOCKED', true));
				$conflicts += count(array_keys($results, 'CONFLICT', true));
			}
			self::assertSame(self::RACE_ROUNDS, $locked);
			self::assertSame(self::RACE_ROUNDS, $conflicts);
		} finally {
			$this->clearLocks();
			foreach ([self::RACE_USER1, self::RACE_USER2] as $uid) {
				$userManager->get($uid)?->delete();
				$databaseBackend->deleteUser($uid);
			}
			$userManager->removeBackend($databaseBackend);
		}
	}

	/**
	 * @param list<list<string>> $commands
	 * @return list<string> stdout of each process
	 */
	private function runConcurrently(array $commands): array {
		$processes = [];
		$pipes = [];
		foreach ($commands as $i => $command) {
			$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
			$process = proc_open($command, $descriptors, $procPipes, \OC::$SERVERROOT);
			self::assertIsResource($process, 'failed to start child process');
			fclose($procPipes[0]);
			$processes[$i] = $process;
			$pipes[$i] = $procPipes;
		}

		$outputs = [];
		foreach ($processes as $i => $process) {
			$stdout = stream_get_contents($pipes[$i][1]);
			$stderr = stream_get_contents($pipes[$i][2]);
			fclose($pipes[$i][1]);
			fclose($pipes[$i][2]);
			$exitCode = proc_close($process);
			if ($exitCode !== 0) {
				self::fail('child process failed (' . $exitCode . '): ' . $stdout . ' ' . $stderr);
			}
			$outputs[] = $stdout;
		}

		return $outputs;
	}
}
