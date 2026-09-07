<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\AppFramework\OCS\BaseResponse;
use OCA\FilesLock\Controller\LockController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use PHPUnit\Framework\Attributes\Group;

/**
 * OCS contract: structured responses for every outcome, in JSON and XML.
 */
#[Group(name: 'DB')]
class OcsControllerTest extends LockTestCase {
	private function controller(): LockController {
		$controller = \OCP\Server::get(LockController::class);
		$controller->setOCSVersion(2);
		return $controller;
	}

	/**
	 * @return array{int, array|string} rendered status and decoded body (array for json, string for xml)
	 */
	private function render(DataResponse $response, string $format = 'json'): array {
		$rendered = $this->controller()->buildResponse($response, $format);
		self::assertInstanceOf(BaseResponse::class, $rendered);
		$body = $rendered->render();
		if ($format === 'json') {
			return [$rendered->getStatus(), json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
		}
		return [$rendered->getStatus(), $body];
	}

	public function testLockUnlockRoundTrip(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('ocs.txt', 'AAA');
		[$status, $body] = $this->render($this->controller()->locking((string)$file->getId()));
		self::assertSame(Http::STATUS_OK, $status);
		self::assertSame(self::USER1, $body['ocs']['data']['userId']);
		self::assertSame(ILock::TYPE_USER, $body['ocs']['data']['type']);
		self::assertStringStartsWith('files_lock/', $body['ocs']['data']['token']);

		[$status] = $this->render($this->controller()->unlocking((string)$file->getId()));
		self::assertSame(Http::STATUS_OK, $status);
		self::assertSame(0, $this->lockRowCount($file->getId()));

		[$status, $body] = $this->render($this->controller()->unlocking((string)$file->getId()));
		self::assertSame(Http::STATUS_PRECONDITION_FAILED, $status);
		self::assertSame('File is not locked', $body['ocs']['meta']['message']);
	}

	public function testConflictIsAStructured423(): void {
		$file = $this->sharedFile('conflict.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$this->loginAndGetUserFolder(self::USER2);

		foreach (['json', 'xml'] as $format) {
			[$status, $body] = $this->render($this->controller()->locking((string)$file->getId()), $format);
			self::assertSame(Http::STATUS_LOCKED, $status, $format);
			if ($format === 'json') {
				self::assertSame(self::USER1, $body['ocs']['data']['userId']);
				self::assertSame($file->getId(), $body['ocs']['data']['fileId']);
				self::assertStringContainsString('locked by', $body['ocs']['meta']['message']);
			} else {
				self::assertStringContainsString('<userId>' . self::USER1 . '</userId>', $body);
				self::assertStringContainsString('<statuscode>423</statuscode>', $body);
			}
		}

		[$status, $body] = $this->render($this->controller()->unlocking((string)$file->getId()));
		self::assertSame(Http::STATUS_LOCKED, $status, 'a recipient may not release the owner lock');
		self::assertSame(self::USER1, $body['ocs']['data']['userId']);
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}
}
