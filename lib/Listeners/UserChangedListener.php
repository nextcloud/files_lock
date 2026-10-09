<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Listeners;

use OCA\FilesLock\Service\LockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use Override;

/**
 * @template-implements IEventListener<UserChangedEvent>
 */
class UserChangedListener implements IEventListener {
	public function __construct(
		private readonly LockService $lockService,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserChangedEvent) {
			return;
		}

		if ($event->getFeature() !== 'enabled' || $event->getValue() !== false) {
			return;
		}

		$userId = $event->getUser()->getUID();
		$this->lockService->removeLocksOfUser($userId);
	}
}
