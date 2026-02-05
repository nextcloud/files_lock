<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Listeners;

use OCA\FilesLock\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeRemotePropfindEvent;

/**
 * @template-implements IEventListener<BeforeRemotePropfindEvent>
 */
class PropfindPropertiesListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof BeforeRemotePropfindEvent)) {
			return;
		}

		$event->addProperties([
			Application::DAV_PROPERTY_LOCK,
			Application::DAV_PROPERTY_LOCK_OWNER,
			Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME,
			Application::DAV_PROPERTY_LOCK_OWNER_TYPE,
			Application::DAV_PROPERTY_LOCK_EDITOR,
			Application::DAV_PROPERTY_LOCK_TIME,
			Application::DAV_PROPERTY_LOCK_TIMEOUT,
			Application::DAV_PROPERTY_LOCK_TOKEN,
		]);
	}
}
