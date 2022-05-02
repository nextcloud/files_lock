<?php

declare(strict_types=1);


/**
 * FilesLock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FilesLock\AppInfo;

use Closure;
use OC\Files\Filesystem;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesLock\Capability;
use OCA\FilesLock\Listeners\LoadAdditionalScripts;
use OCA\FilesLock\LockProvider;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Storage\LockWrapper;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Lock\ILockManager;
use OCP\IServerContainer;
use OCP\IUserSession;
use OCP\Util;
use Throwable;

/**
 * Class Application
 *
 * @package OCA\FilesLock\AppInfo
 */
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


	/** @var IUserSession */
	private $userSession;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	private ILockManager $lockManager;


	/**
	 * @param array $params
	 */
	public function __construct(array $params = array()) {
		parent::__construct(self::APP_ID, $params);
	}


	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capability::class);
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			LoadAdditionalScripts::class
		);
	}


	/**
	 * @param IBootContext $context
	 *
	 * @throws Throwable
	 */
	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerHooks']));

		$context->injectFn(function (ILockManager $lockManager) use ($context) {
			$lockManager->registerLockProvider($context->getAppContainer()->get(LockProvider::class));
		});
	}


	/**
	 * @param IServerContainer $container
	 */
	public function registerHooks(IServerContainer $container) {
		$eventDispatcher = \OC::$server->getEventDispatcher();

		$this->userSession = $container->get(IUserSession::class);
		$this->fileService = $container->get(FileService::class);
		$this->lockService = $container->get(LockService::class);
		$this->lockManager = $container->get(ILockManager::class);

		Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');
	}

	/**
	 * @internal
	 */
	public function addStorageWrapper() {
		Filesystem::addStorageWrapper(
			'files_lock', function ($mountPoint, $storage) {
				return new LockWrapper(
				[
					'storage' => $storage,
					'lock_manager' => $this->lockManager,
					'user_session' => $this->userSession,
					'file_service' => $this->fileService,
					'lock_service' => $this->lockService
				]
			);
			}, 10
		);
	}
}
