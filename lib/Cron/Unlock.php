<?php declare(strict_types=1);


/**
 * Files_Lock - Temporary Files Lock
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


namespace OCA\FilesLock\Cron;


use OC\BackgroundJob\TimedJob;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Service\MiscService;
use OCP\AppFramework\QueryException;


/**
 * Class Unlock
 *
 * @package OCA\FilesLock\Cron
 */
class Unlock extends TimedJob {


	/** @var LockService */
	private $lockService;

	/** @var MiscService */
	private $miscService;


	/**
	 * Unlock constructor.
	 */
	public function __construct() {
//		$this->setInterval(12 * 60); // 12 minutes
		$this->setInterval(1);
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$app = new Application();
		$c = $app->getContainer();

		$this->lockService = $c->query(LockService::class);
		$this->miscService = $c->query(MiscService::class);

		$this->manageTimeoutLock();
	}


	/**
	 *
	 */
	private function manageTimeoutLock() {
		$this->lockService->removeLocks($this->lockService->getDeprecatedLocks());
	}

}

