<?php

declare(strict_types=1);


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


namespace OCA\FilesLock\Db;

use OC;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;

/**
 * Class CoreRequestBuilder
 *
 * @package OCA\FilesLock\Db
 */
class CoreRequestBuilder {
	public const TABLE_LOCKS = 'files_lock';

	/** @var array */
	private static $tables = [
		self::TABLE_LOCKS,
	];


	/** @var string */
	protected $defaultSelectAlias;


	/**
	 * @return CoreQueryBuilder
	 */
	public function getQueryBuilder(): CoreQueryBuilder {
		return new CoreQueryBuilder();
	}


	/**
	 *
	 */
	public function uninstall() {
		$this->uninstallAppTables();
		$this->removeFromJobs();
		;
		$this->removeFromMigrations();
	}


	/**
	 *
	 */
	public function uninstallAppTables() {
		$dbConn = OC::$server->get(Connection::class);
		$schema = new SchemaWrapper($dbConn);

		foreach (array_keys(self::$tables) as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
			}
		}

		$schema->performDropTableCalls();
	}


	/**
	 *
	 */
	public function removeFromMigrations() {
		$qb = $this->getQueryBuilder();
		$qb->delete('migrations');
		$qb->where($qb->exprLimitToDBField('app', 'files_lock', true, true));

		$qb->execute();
	}

	/**
	 *
	 */
	public function removeFromJobs() {
		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($qb->exprLimitToDBField('class', 'OCA\FilesLock\Cron\Unlock', true, true));
		$qb->execute();
	}
}
