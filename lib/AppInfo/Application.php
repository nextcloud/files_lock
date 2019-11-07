<?php declare(strict_types=1);


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


use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\FilesLock\Plugins\FilesLockPlugin;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\App;
use OCP\AppFramework\QueryException;
use OCP\SabrePluginEvent;
use Sabre\DAV\Locks\Plugin;


class Application extends App {


	const APP_NAME = 'files_lock';

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;


	/**
	 * @param array $params
	 */
	public function __construct(array $params = array()) {
		parent::__construct(self::APP_NAME, $params);
	}


	/**
	 *
	 */
	public function registerHooks() {
		$eventDispatcher = \OC::$server->getEventDispatcher();
		$c = $this->getContainer();
		try {
			$this->fileService = $c->query(FileService::class);
			$this->lockService = $c->query(LockService::class);
		} catch (QueryException $e) {
			return;
		}

		$eventDispatcher->addListener(
			'OCA\DAV\Connector\Sabre::addPlugin', function(SabrePluginEvent $e) {
			$server = $e->getServer();
			$absolute = false;
			switch (get_class($server->tree)) {
				case ObjectTree::class:
					$absolute = false;
					break;

				case CachingTree::class:
					$absolute = true;
					break;
			}

			$server->addPlugin(
				new Plugin(new FilesLockPlugin($this->fileService, $this->lockService, $absolute))
			);
		}
		);
	}

}

