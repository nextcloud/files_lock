<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesLock\Capability;
use OCA\FilesLock\ConfigLexicon;
use OCA\FilesLock\Listeners\BeforeFileSystemSetupListener;
use OCA\FilesLock\Listeners\LoadAdditionalScripts;
use OCA\FilesLock\Listeners\PropfindPropertiesListener;
use OCA\FilesLock\LockProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\Events\BeforeRemotePropfindEvent;
use OCP\Files\Lock\ILockManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_lock';

	public const DAV_PROPERTY_LOCK = '{http://nextcloud.org/ns}lock';
	public const DAV_PROPERTY_LOCK_OWNER_TYPE = '{http://nextcloud.org/ns}lock-owner-type';
	public const DAV_PROPERTY_LOCK_OWNER = '{http://nextcloud.org/ns}lock-owner';
	public const DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME = '{http://nextcloud.org/ns}lock-owner-displayname';
	public const DAV_PROPERTY_LOCK_EDITOR = '{http://nextcloud.org/ns}lock-owner-editor';
	public const DAV_PROPERTY_LOCK_TIME = '{http://nextcloud.org/ns}lock-time';
	public const DAV_PROPERTY_LOCK_TIMEOUT = '{http://nextcloud.org/ns}lock-timeout';
	public const DAV_PROPERTY_LOCK_TOKEN = '{http://nextcloud.org/ns}lock-token';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capability::class);
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			LoadAdditionalScripts::class
		);
		$context->registerEventListener(
			BeforeRemotePropfindEvent::class,
			PropfindPropertiesListener::class
		);
		$context->registerEventListener(
			BeforeFileSystemSetupEvent::class,
			BeforeFileSystemSetupListener::class
		);
		$context->registerConfigLexicon(ConfigLexicon::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		$context->injectFn(function (ILockManager $lockManager) use ($context): void {
			$lockManager->registerLazyLockProvider(LockProvider::class);
		});
	}
}
