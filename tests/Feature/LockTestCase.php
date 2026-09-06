<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\Files\Lock\LockManager;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\ConfigLexicon;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILockManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * Shared fixtures: two dummy users, a controllable clock, share helper and a
 * clean lock table before every test.
 */
abstract class LockTestCase extends TestCase {
	public const USER1 = 'lock-user1';
	public const USER2 = 'lock-user2';
	public const USER3 = 'lock-user3';

	protected LockManager $lockManager;
	protected IRootFolder $rootFolder;
	protected ControllableTimeFactory $timeFactory;
	protected ?int $time = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		foreach ([self::USER1, self::USER2, self::USER3] as $user) {
			$backend->createUser($user, $user);
		}
		\OCP\Server::get(IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->time = null;
		$this->lockManager = \OCP\Server::get(ILockManager::class);
		$this->rootFolder = \OCP\Server::get(IRootFolder::class);
		$this->timeFactory = new ControllableTimeFactory();
		$this->overwriteService(ITimeFactory::class, $this->timeFactory);
		$this->clearLocks();
		$this->setLockTimeoutMinutes(-1);
		\OC_Hook::$thrownExceptions = [];
	}

	protected function tearDown(): void {
		$this->clearLocks();
		foreach ([self::USER1, self::USER2, self::USER3] as $user) {
			try {
				$this->loginAsUser($user);
				foreach ($this->rootFolder->getUserFolder($user)->getDirectoryListing() as $node) {
					try {
						$node->delete();
					} catch (\Throwable) {
					}
				}
				if (class_exists(\OCA\Files_Trashbin\Trashbin::class)) {
					\OCA\Files_Trashbin\Trashbin::deleteAll();
				}
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	protected function lockService(): LockService {
		return \OCP\Server::get(LockService::class);
	}

	protected function clearLocks(): void {
		\OCP\Server::get(IDBConnection::class)->executeStatement('DELETE FROM `*PREFIX*files_lock`');
		$this->lockService()->clearCache();
	}

	protected function setLockTimeoutMinutes(int $minutes): void {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigLexicon::LOCK_TIMEOUT, (string)$minutes);
	}

	protected function loginAndGetUserFolder(string $userId): Folder {
		$this->loginAsUser($userId);
		$this->lockService()->clearCache();
		return $this->rootFolder->getUserFolder($userId);
	}

	protected function shareWith(\OCP\Files\Node $node, string $owner, string $user, int $permissions = 19): IShare {
		$shareManager = \OCP\Server::get(IShareManager::class);
		$share = $shareManager->newShare();
		$share->setNode($node)
			->setSharedBy($owner)
			->setSharedWith($user)
			->setShareType(IShare::TYPE_USER)
			->setPermissions($permissions);
		$share = $shareManager->createShare($share);
		$share->setStatus(IShare::STATUS_ACCEPTED);
		$shareManager->updateShare($share);
		return $share;
	}

	/**
	 * Move the clock to an absolute moment, which lets a test model two processes
	 * whose clock reads happen in a different order than their database writes.
	 */
	protected function atTime(int $timestamp): void {
		$this->time = $timestamp;
		$this->timeFactory->time = $timestamp;
		$this->lockService()->clearCache();
	}

	protected function toTheFuture(int $seconds): void {
		if ($this->time === null) {
			$this->time = time();
		}
		$this->time += $seconds;
		$this->timeFactory->time = $this->time;
		$this->lockService()->clearCache();
	}

	protected function lockRowCount(int $fileId): int {
		return (int)\OCP\Server::get(IDBConnection::class)->executeQuery(
			'SELECT COUNT(*) FROM `*PREFIX*files_lock` WHERE `file_id` = ?', [$fileId]
		)->fetchOne();
	}

	protected function storedLock(int $fileId): ?FileLock {
		$locks = \OCP\Server::get(LocksRequest::class)->getFromFileIds([$fileId]);
		return $locks[0] ?? null;
	}

	/**
	 * Create a file as USER1 and share it with USER2 (and optionally USER3).
	 */
	protected function sharedFile(string $name, int $permissions = 19, ?int $permissionsUser3 = null): File {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile($name, 'AAA');
		$this->shareWith($file, self::USER1, self::USER2, $permissions);
		if ($permissionsUser3 !== null) {
			$this->shareWith($file, self::USER1, self::USER3, $permissionsUser3);
		}
		return $file;
	}
}
