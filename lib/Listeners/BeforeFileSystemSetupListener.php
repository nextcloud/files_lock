<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH
 * SPDX-FileContributor: Carl Schwan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Listeners;

use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Storage\LockWrapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Storage\IStorage;
use OCP\IUserSession;
use Override;

/**
 * @template-implements IEventListener<BeforeFileSystemSetupEvent>
 */
class BeforeFileSystemSetupListener implements IEventListener {
	public function __construct(
		private readonly ILockManager $lockManager,
		private readonly IUserSession $userSession,
		private readonly FileService $fileService,
		private readonly LockService $lockService,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeFileSystemSetupEvent) {
			return;
		}

		$event->addStorageWrapper(
			LockWrapper::class, fn (string $mountPoint, IStorage $storage): LockWrapper => new LockWrapper(
				[
					'storage' => $storage,
					'lock_manager' => $this->lockManager,
					'user_session' => $this->userSession,
					'file_service' => $this->fileService,
					'lock_service' => $this->lockService,
				]
			), 10);
	}
}
