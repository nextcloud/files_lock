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
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ files:lock: status, locking and unlocking.
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

	public function testUnlock(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('cli-unlock.txt', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		self::assertSame(0, $this->tester()->execute(['file_id' => (string)$file->getId(), '--unlock' => true]));
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}
}
