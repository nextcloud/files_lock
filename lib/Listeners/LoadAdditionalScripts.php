<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Listeners;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesLock\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<Event|LoadAdditionalScriptsEvent> */
class LoadAdditionalScripts implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		Util::addInitScript(Application::APP_ID, 'files_lock-init');
		Util::addScript(Application::APP_ID, 'files_lock-main');
		Util::addStyle(Application::APP_ID, 'files_lock-main');
	}
}
