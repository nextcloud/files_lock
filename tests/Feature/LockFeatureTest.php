<?php
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OC\Files\Lock\LockManager;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\ConfigService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ManuallyLockedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * @group DB
 */
class LockFeatureTest extends TestCase {
	public const TEST_USER1 = 'test-user1';
	public const TEST_USER2 = 'test-user2';

	protected LockManager $lockManager;
	protected IRootFolder $rootFolder;
	protected ITimeFactory&MockObject $timeFactory;
	protected IShareManager $shareManager;
	protected ?int $time = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		$backend->createUser(self::TEST_USER2, self::TEST_USER2);
		\OCP\Server::get(IUserManager::class)->registerBackend($backend);
	}

	public function setUp(): void {
		parent::setUp();
		$this->time = null;
		$this->lockManager = \OCP\Server::get(ILockManager::class);
		$this->rootFolder = \OCP\Server::get(IRootFolder::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->expects(self::any())
			->method('getTime')
			->willReturnCallback(function () {
				if ($this->time) {
					return $this->time;
				}
				return time();
			});
		$folder = $this->loginAndGetUserFolder(self::TEST_USER1);
		$folder->delete('test-file');
		$folder->delete('test-file2');
		$folder->delete('test-file3');
		\OC_Hook::$thrownExceptions = [];
		$this->overwriteService(ITimeFactory::class, $this->timeFactory);
		$this->toTheFuture(0);
	}

	public function testLockUser() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-file', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('test-file');
		try {
			$file->putContent('CCC');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}

		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('test-file');
		$file->putContent('DDD');
		self::assertEquals('DDD', $file->getContent());

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('test-file');
		$file->putContent('EEE');
		self::assertEquals('EEE', $file->getContent());
	}

	public function testLockEtag() {
		$this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('etag_test', 'etag_test');

		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('etag_test');
		$oldEtag = $file->getEtag();
		$oldRootEtag = $this->loginAndGetUserFolder(self::TEST_USER1)->getEtag();

		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('etag_test');
		$newRootEtag = $this->loginAndGetUserFolder(self::TEST_USER1)->getEtag();

		self::assertNotEquals($oldRootEtag, $newRootEtag);
		self::assertNotEquals($oldEtag, $file->getEtag());
	}

	public function testUnlockEtag() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('etag_test', 'etag_test');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('etag_test');
		$oldEtag = $file->getEtag();
		$oldRootEtag = $this->loginAndGetUserFolder(self::TEST_USER1)->getEtag();

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('etag_test');
		$newRootEtag = $this->loginAndGetUserFolder(self::TEST_USER1)->getEtag();

		self::assertNotEquals($oldRootEtag, $newRootEtag);
		self::assertNotEquals($oldEtag, $file->getEtag());
	}

	public function testLockEtagShare() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('etag_test', 'etag_test');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('etag_test');
		$oldEtag = $file->getEtag();
		$oldRootEtag = $this->loginAndGetUserFolder(self::TEST_USER2)->getEtag();

		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('etag_test');
		$newRootEtag = $this->loginAndGetUserFolder(self::TEST_USER2)->getEtag();

		self::assertNotEquals($oldRootEtag, $newRootEtag);
		self::assertNotEquals($oldEtag, $file->getEtag());
	}


	public function testUnlockEtagShare() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('etag_test', 'etag_test');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);

		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('etag_test');
		$oldEtag = $file->getEtag();
		$oldRootEtag = $this->loginAndGetUserFolder(self::TEST_USER2)->getEtag();

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('etag_test');
		$newRootEtag = $this->loginAndGetUserFolder(self::TEST_USER2)->getEtag();

		self::assertNotEquals($oldRootEtag, $newRootEtag);
		self::assertNotEquals($oldEtag, $file->getEtag());
	}

	public function testLockUserExpire() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 30);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-file-expire', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('test-file-expire');
		try {
			$file->putContent('CCC');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}

		$this->toTheFuture(3600);
		$file->putContent('CCC');
		self::assertEquals('CCC', $file->getContent());
	}

	public function testExpiredLocksAreDeprecated() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 30);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-expired-lock-is-deprecated', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$this->toTheFuture(3600);
		$deprecated = \OCP\Server::get(LockService::class)->getDeprecatedLocks();
		self::assertNotEmpty($deprecated);
	}

	public function testRemoveLocks(): void {
		$service = \OCP\Server::get(LockService::class);
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 30);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)->newFile('test-expired-lock-is-deprecated', 'AAA');
		$lock1 = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file2 = $this->loginAndGetUserFolder(self::TEST_USER1)->newFile('test-expired-lock-is-deprecated-2', 'AAA');
		$lock2 = $this->lockManager->lock(new LockContext($file2, ILock::TYPE_USER, self::TEST_USER1));
		$this->toTheFuture(3600);
		$mapToTokens = fn (ILock $lock) => $lock->getToken();
		$deprecated = array_map($mapToTokens, $service->getDeprecatedLocks());

		self::assertContains($lock1->getToken(), $deprecated);
		self::assertContains($lock2->getToken(), $deprecated);

		$service->removeLocks([$lock1, $lock2]);
		$deprecated = array_map($mapToTokens, $service->getDeprecatedLocks());

		self::assertNotContains($lock1->getToken(), $deprecated);
		self::assertNotContains($lock2->getToken(), $deprecated);
	}

	public function testLockUserInfinite() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 0);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-file-infinite', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('test-file-infinite');
		try {
			$file->putContent('CCC');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}

		$this->toTheFuture(3600);
		/** @var File */
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('test-file-infinite');
		try {
			$file->putContent('DDD');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}
	}

	public function testLockApp() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-file2', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$scope = new LockContext($file, ILock::TYPE_APP, 'collaborative_app');
		$this->lockManager->lock($scope);
		try {
			$file->putContent('BBB');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('AAA', $file->getContent());
		}

		$this->lockManager->runInScope($scope, function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockManager->getLockInScope()->getOwner());
			$file->putContent('EEE');
			self::assertEquals('EEE', $file->getContent());
		});

		$this->loginAndGetUserFolder(self::TEST_USER2);
		$this->lockManager->runInScope($scope, function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockManager->getLockInScope()->getOwner());
			$file->putContent('FFF');
			self::assertEquals('FFF', $file->getContent());
		});
	}

	public function testLockDifferentApps() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('test-file3', 'AAA');
		$scope = new LockContext($file, ILock::TYPE_APP, 'collaborative_app');
		$this->lockManager->lock($scope);

		$this->lockManager->runInScope($scope, function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockManager->getLockInScope()->getOwner());
			$file->putContent('EEE');
			self::assertEquals('EEE', $file->getContent());
		});

		$otherAppScope = new LockContext($file, ILock::TYPE_APP, 'other_app');
		$this->lockManager->runInScope($otherAppScope, function () use ($file) {
			self::assertEquals('other_app', $this->lockManager->getLockInScope()->getOwner());
			try {
				$file->putContent('BBB');
				$this->fail('Expected to throw a ManuallyLockedException');
			} catch (ManuallyLockedException $e) {
				self::assertInstanceOf(ManuallyLockedException::class, $e);
				self::assertEquals('EEE', $file->getContent());
			}
		});
	}

	public function testLockDifferentAppsPublic() {
		self::logout();
		$file = $this->rootFolder->getUserFolder(self::TEST_USER1)
			->newFile('test-file_public', 'AAA');
		$scope = new LockContext($file, ILock::TYPE_APP, 'collaborative_app');
		$this->lockManager->lock($scope);

		$this->lockManager->runInScope($scope, function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockManager->getLockInScope()->getOwner());
			$file->putContent('EEE');
			self::assertEquals('EEE', $file->getContent());
		});

		$otherAppScope = new LockContext($file, ILock::TYPE_APP, 'other_app');
		$this->lockManager->runInScope($otherAppScope, function () use ($file) {
			self::assertEquals('other_app', $this->lockManager->getLockInScope()->getOwner());
			try {
				$file->putContent('BBB');
				$this->fail('Expected to throw a ManuallyLockedException');
			} catch (ManuallyLockedException $e) {
				self::assertInstanceOf(ManuallyLockedException::class, $e);
				self::assertEquals('EEE', $file->getContent());
			}
		});
	}

	/**
	 * Ensure that a lock can be extended and the same lock is kept
	 */
	public function testExtendLock() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 15);

		// Create a file and lock it
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)->newFile('test-file', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$locks = $this->lockManager->getLocks($file->getId());

		// We should have one lock for that file with 15 minutes ETA
		$this->assertCount(1, $locks);
		$this->assertEqualsWithDelta(15 * 60, $locks[0]->getEta(), 2, 'Initial lock ETA should be approximately 15 minutes');

		// going to the future we see the ETA to be 5 minutes
		$this->toTheFuture(10 * 60);
		$locks = $this->lockManager->getLocks($file->getId());
		$this->assertCount(1, $locks);
		$this->assertEqualsWithDelta(5 * 60, $locks[0]->getEta(), 2, 'After 10 minutes, ETA should be approximately 5 minutes');
		$id = $locks[0]->getId();

		// Extend the lock (lock again)
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		// The lock should only be extended, so same ID but fresh ETA
		$locks = $this->lockManager->getLocks($file->getId());
		$this->assertCount(1, $locks);
		$this->assertEqualsWithDelta(15 * 60, $locks[0]->getEta(), 2, 'Extended lock ETA should be approximately 15 minutes');
		$this->assertEquals($id, $locks[0]->getId());
	}

	/**
	 * Regression test for https://github.com/nextcloud/files_lock/issues/130
	 */
	public function testExtendInfiniteLock() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, '0');

		// Create a file and lock it
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)->newFile('test-file', 'AAA');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$locks = $this->lockManager->getLocks($file->getId());

		// We should have one lock for that file with infinite ETA
		$this->assertCount(1, $locks);
		$this->assertEquals(FileLock::ETA_INFINITE, $locks[0]->getEta());
		$id = $locks[0]->getId();

		// Extend the lock (lock again)
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		// The lock should only be extended, and keep the infinite ETA
		$locks = $this->lockManager->getLocks($file->getId());
		$this->assertCount(1, $locks);
		$this->assertEquals(FileLock::ETA_INFINITE, $locks[0]->getEta());
		$this->assertEquals($id, $locks[0]->getId());
	}

	public function testUnlockStaleClientLock() {
		\OCP\Server::get(IConfig::class)->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, '0');

		// Create a file and lock it as the desktop client would
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)->newFile('test-file-client', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);

		$this->lockManager->lock(new LockContext($file, ILock::TYPE_TOKEN, self::TEST_USER1));
		$locks = $this->lockManager->getLocks($file->getId());
		$this->assertCount(1, $locks);

		// Other users cannot unlock
		try {
			$this->lockManager->unlock(new LockContext($file, ILock::TYPE_TOKEN, self::TEST_USER2));
			$locks = [];
		} catch (\OCP\PreConditionNotMetException $e) {
			$locks = $this->lockManager->getLocks($file->getId());
		}
		$this->assertCount(1, $locks);


		// The owner can stil force unlock it as done through the OCS controller
		\OCP\Server::get(\OCA\FilesLock\Service\LockService::class)->enableUserOverride();
		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));

		$locks = $this->lockManager->getLocks($file->getId());
		$this->assertCount(0, $locks);
	}

	private function loginAndGetUserFolder(string $userId) {
		$this->loginAsUser($userId);
		return $this->rootFolder->getUserFolder($userId);
	}

	private function shareFileWithUser(\OCP\Files\File $file, $owner, $user) {
		$this->shareManager = \OCP\Server::get(IShareManager::class);
		$share1 = $this->shareManager->newShare();
		$share1->setNode($file)
			->setSharedBy($owner)
			->setSharedWith($user)
			->setShareType(IShare::TYPE_USER)
			->setPermissions(19);
		$share1 = $this->shareManager->createShare($share1);
		$share1->setStatus(IShare::STATUS_ACCEPTED);
		$this->shareManager->updateShare($share1);
	}

	private function toTheFuture(int $seconds): void {
		if ($this->time === null) {
			$this->time = time();
		}
		$this->time += $seconds;
	}

	public function tearDown(): void {
		parent::tearDown();
		$folder = $this->rootFolder->getUserFolder(self::TEST_USER1);
		$folder->delete('test-file');
		$folder->delete('etag_test');
		$folder->delete('test-file2');
		$folder->delete('test-file3');
		$folder->delete('test-file-infinite');
		$folder->delete('test-file_public');
	}
}
