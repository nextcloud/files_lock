<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Listeners;

use Exception;
use OCA\FilesLock\Service\LockService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<ShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener {
	public function __construct(
		private readonly LockService $lockService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof ShareDeletedEvent) {
			return;
		}

		$share = $event->getShare();
		$recipients = $this->getRecipients($share);
		if ($recipients === []) {
			return;
		}

		try {
			$node = $share->getNode();
		} catch (NotFoundException) {
			return;
		}

		foreach ($recipients as $userId) {
			try {
				$this->lockService->removeInaccessibleLocksOfUser($userId, $node);
			} catch (Exception $e) {
				$this->logger->error('Failed to remove inaccessible locks', ['userId' => $userId, 'exception' => $e]);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function getRecipients(IShare $share): array {
		$sharedWith = $share->getSharedWith();
		if ($share->getShareType() === IShare::TYPE_USER) {
			return [$sharedWith];
		}

		if ($share->getShareType() !== IShare::TYPE_GROUP) {
			return [];
		}

		$group = $this->groupManager->get($sharedWith);
		if ($group === null) {
			return [];
		}

		$userIds = [];
		foreach ($group->getUsers() as $user) {
			$userIds[] = $user->getUID();
		}

		return $userIds;
	}
}
