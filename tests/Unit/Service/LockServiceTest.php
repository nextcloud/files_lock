<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Unit\Service;

use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Service\ConfigService;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LockServiceTest extends TestCase {

	/**
	 * @dataProvider appInfoProvider
	 */
	public function testGetAppName(?array $appInfo, ?string $expected): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInfo')->with('richdocuments')->willReturn($appInfo);

		$service = new LockService(
			$this->createMock(IL10N::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LocksRequest::class),
			$this->createMock(FileService::class),
			$this->createMock(ConfigService::class),
			$appManager,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRootFolder::class),
		);

		$this->assertSame($expected, $service->getAppName('richdocuments'));
	}

	public static function appInfoProvider(): array {
		return [
			'name' => [['name' => 'Nextcloud Office'], 'Nextcloud Office'],
			'missing app' => [null, null],
			'no name' => [[], null],
			'untranslated name' => [['name' => [['@value' => 'Nextcloud Office']]], null],
		];
	}
}
