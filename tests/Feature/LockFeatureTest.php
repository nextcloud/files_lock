<?php
/**
 * @copyright Copyright (c) 2022 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Lock\ManuallyLockedException;
use OCP\Share\IShare;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * @group DB
 */
class LockFeatureTest extends TestCase {

	public const TEST_USER1 = "test-user1";
	public const TEST_USER2 = "test-user2";

	private LockService $lockService;
	private IRootFolder $rootFolder;
	private ?int $time = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$backend = new Dummy();
		OC_User::useBackend($backend);
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		$backend->createUser(self::TEST_USER2, self::TEST_USER2);
	}

	public function setUp(): void {
		parent::setUp();
		$this->time = null;
		$this->lockService = \OC::$server->get(LockService::class);
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
		$this->overwriteService(ITimeFactory::class, $this->timeFactory);
	}

	public function testLockUser() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockService->lockFileAsUser($file, \OC::$server->getUserManager()->get(self::TEST_USER1));
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

		$this->lockService->unlockFile($file->getId(), self::TEST_USER1);

		$file = $this->loginAndGetUserFolder(self::TEST_USER2)
			->get('testfile');
		$file->putContent('EEE');
		self::assertEquals('EEE', $file->getContent());
	}
	public function testLockUserExpire() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockService->lockFileAsUser($file, \OC::$server->getUserManager()->get(self::TEST_USER1));
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

		$this->toTheFuture(3600);
		$file->putContent('CCC');
		self::assertEquals('CCC', $file->getContent());

	}

	public function testLockApp() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile2', 'AAA');
		$this->shareFileWithUser($file, self::TEST_USER1, self::TEST_USER2);
		$this->lockService->lockFileAsApp($file, 'collaborative_app');
		try {
			$file->putContent('BBB');
			$this->fail('Expected to throw a ManuallyLockedException');
		} catch (ManuallyLockedException $e) {
			self::assertInstanceOf(ManuallyLockedException::class, $e);
			self::assertEquals('AAA', $file->getContent());
		}

		$this->lockService->executeInAppScope('collaborative_app', function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockService->getAppInScope());
			$file->putContent('EEE');
			self::assertEquals('EEE', $file->getContent());
		});

		$this->loginAndGetUserFolder(self::TEST_USER2);
		$this->lockService->executeInAppScope('collaborative_app', function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockService->getAppInScope());
			$file->putContent('FFF');
			self::assertEquals('FFF', $file->getContent());
		});
	}

	public function testLockDifferentApps() {
		$file = $this->loginAndGetUserFolder(self::TEST_USER1)
			->newFile('testfile3', 'AAA');
		$this->lockService->lockFileAsApp($file, 'collaborative_app');

		$this->lockService->executeInAppScope('collaborative_app', function () use ($file) {
			self::assertEquals('collaborative_app', $this->lockService->getAppInScope());
			$file->putContent('EEE');
			self::assertEquals('EEE', $file->getContent());
		});

		$this->lockService->executeInAppScope('other_app', function () use ($file) {
			self::assertEquals('other_app', $this->lockService->getAppInScope());
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
		$folder->delete('testfile2');
	}
}
