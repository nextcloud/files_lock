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
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;

/**
 * Drop lock rows when their file goes away: on user-visible deletion (the file
 * may still exist in the trash bin, but a restored file starts unlocked) and
 * when the file cache entry is finally removed.
 *
 * @template-implements IEventListener<NodeDeletedEvent|CacheEntryRemovedEvent>
 */
class NodeDeletedListener implements IEventListener {
	public function __construct(
		private readonly LockService $lockService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof NodeDeletedEvent) {
			$fileId = $event->getNode()->getId();
		} elseif ($event instanceof CacheEntryRemovedEvent) {
			$fileId = $event->getFileId();
		} else {
			return;
		}

		if ($fileId !== null && $fileId > 0) {
			$this->lockService->removeLocksForFileIds([(int)$fileId]);
		}
	}
}
