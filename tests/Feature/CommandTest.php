<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\Console\CommandAdapter;
use OCA\FilesLock\Command\Lock;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ files:lock: status, locking, and forced unlock as an administrative operation.
 */
#[Group(name: 'DB')]
class CommandTest extends LockTestCase {
	private function tester(): CommandTester {
		return new CommandTester(
			new CommandAdapter(Lock::class, null, \OCP\Server::get(ContainerInterface::class))
		);
	}

	public function testStatusAndLock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('cli.txt', 'AAA');
		$id = $file->getId();
		$tester = $this->tester();

		$tester->execute(['file_id' => (string)$id, '--status' => true]);
		self::assertStringContainsString('not locked', $tester->getDisplay());

		self::assertSame(0, $tester->execute(['file_id' => (string)$id, 'user_id' => self::USER1]));
		self::assertSame(1, $this->lockRowCount($id));

		$tester->execute(['file_id' => (string)$id, '--status' => true]);
		self::assertStringContainsString('locked by ' . self::USER1, $tester->getDisplay());
	}

	public function testLockingAnAlreadyLockedFileFailsCleanly(): void {
		$file = $this->sharedFile('cli-conflict.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$tester = $this->tester();
		self::assertSame(1, $tester->execute(['file_id' => (string)$file->getId(), 'user_id' => self::USER2]));
		self::assertStringContainsString('already locked by ' . self::USER1, $tester->getDisplay());
		self::assertSame(1, $this->lockRowCount($file->getId()), 'the existing lock is untouched');
	}

	public function testLockingAFolderFailsCleanly(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1)->newFolder('cli-folder');

		$tester = $this->tester();
		self::assertSame(1, $tester->execute(['file_id' => (string)$folder->getId(), 'user_id' => self::USER1]));
		self::assertStringContainsString('not a file', $tester->getDisplay());
		self::assertSame(0, $this->lockRowCount($folder->getId()));
	}

	public function testForcedUnlockAfterOwnerLostAccess(): void {
		$file = $this->sharedFile('cli-force.txt');
		$id = $file->getId();
		$shared = $this->loginAndGetUserFolder(self::USER2)->get('cli-force.txt');
		$this->lockManager->lock(new LockContext($shared, ILock::TYPE_USER, self::USER2));

		$shareManager = \OCP\Server::get(IShareManager::class);
		foreach ($shareManager->getSharesBy(self::USER1, IShare::TYPE_USER, $file) as $share) {
			$shareManager->deleteShare($share);
		}
		$this->logout();
		$tester = $this->tester();

		self::assertSame(0, $tester->execute(['file_id' => (string)$id, '--unlock' => true]));
		self::assertStringContainsString('Unlocked file #' . $id, $tester->getDisplay());
		self::assertSame(0, $this->lockRowCount($id));

		self::assertSame(0, $tester->execute(['file_id' => (string)$id, '--unlock' => true]));
		self::assertStringContainsString('already unlocked', $tester->getDisplay());
	}

	public function testForcedUnlockOfAppAndTokenLocks(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('cli-app.txt', 'AAA');
		$id = $file->getId();
		$tester = $this->tester();

		$this->lockManager->lock(new LockContext($file, ILock::TYPE_APP, 'text'));
		$this->logout();
		self::assertSame(0, $tester->execute(['file_id' => (string)$id, '--unlock' => true]));
		self::assertSame(0, $this->lockRowCount($id));

		$this->loginAndGetUserFolder(self::USER1);
		$this->lockService()->acquire(new LockContext($file, ILock::TYPE_TOKEN, self::USER1), null, 'cli-token');
		$this->logout();
		self::assertSame(0, $tester->execute(['file_id' => (string)$id, '--unlock' => true]));
		self::assertSame(0, $this->lockRowCount($id));
	}
}
