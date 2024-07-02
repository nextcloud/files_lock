<?php
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OC\Files\Lock\LockManager;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Lock\ManuallyLockedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * @group DB
 */
class LockFeatureTest extends TestCase {
	public const TEST_USER1 = "test-user1";
	public const TEST_USER2 = "test-user2";

	private LockManager $lockManager;
	private IRootFolder $rootFolder;
	private ITimeFactory $timeFactory;
	private IShareManager $shareManager;
	private ?int $time = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		$backend->createUser(self::TEST_USER2, self::TEST_USER2);
		\OC::$server->getUserManager()->registerBackend($backend);
	}

	public function setUp(): void {
		parent::setUp();
		$this->time = null;
		$this->lockManager = \OC::$server->get(ILockManager::class);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
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
		$folder->delete('testfile');
		$folder->delete('testfile2');
		$folder->delete('testfile3');
		\OC_Hook::$thrownExceptions = [];
		$this->overwriteService(ITimeFactory::class, $this->timeFactory);
	}

	public function testLockUser() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile');
		try {
			$file->putContent('CCC');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}

		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->get('testfile');
		$file->putContent('DDD');
		self::assertEquals('DDD', $file->getContent());

		$this->lockManager->unlock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile');
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
		\OC::$server->getConfig()->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 30);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile-expire', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile-expire');
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

	public function testLockUserInfinite() {
		\OC::$server->getConfig()->setAppValue(Application::APP_ID, ConfigService::LOCK_TIMEOUT, 0);
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile-infinite', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::TEST_USER1));
		$file->putContent('BBB');

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile-infinite');
		try {
			$file->putContent('CCC');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('BBB', $file->getContent());
		}

		$this->toTheFuture(3600);
		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile-infinite');
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
			->newFile('testfile2', 'AAA');
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
			->newFile('testfile3', 'AAA');
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
			->newFile('testfile_public', 'AAA');
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

	private function loginAndGetUserFolder(string $userId) {
		$this->loginAsUser($userId);
		return $this->rootFolder->getUserFolder($userId);
	}

	private function shareFileWithUser(\OCP\Files\File $file, $owner, $user) {
		$this->shareManager = \OC::$server->getShareManager();
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
		$this->time = time() + $seconds;
	}

	public function tearDown(): void {
		parent::tearDown();
		$folder = $this->rootFolder->getUserFolder(self::TEST_USER1);
		$folder->delete('testfile');
		$folder->delete('etag_test');
		$folder->delete('testfile2');
		$folder->delete('testfile3');
		$folder->delete('testfile-infinite');
		$folder->delete('testfile_public');
	}
}
