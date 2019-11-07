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


namespace OCA\FilesLock\Db;


use OC;
use OC\DB\SchemaWrapper;
use OCA\FilesLock\Service\MiscService;
use OCP\IDBConnection;
use OCP\ILogger;

/**
 * Class CoreRequestBuilder
 *
 * @package OCA\FilesLock\Db
 */
class CoreRequestBuilder {


	const TABLE_LOCKS = 'files_lock';

	/** @var array */
	private $tables = [
		self::TABLE_LOCKS,
	];

	/** @var ILogger */
	protected $logger;

	/** @var IDBConnection */
	protected $dbConnection;

	/** @var MiscService */
	protected $miscService;


	/** @var string */
	protected $defaultSelectAlias;


	/**
	 * CoreRequestBuilder constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ILogger $logger
	 * @param MiscService $miscService
	 */
	public function __construct(IDBConnection $connection, ILogger $logger, MiscService $miscService) {
		$this->dbConnection = $connection;
		$this->logger = $logger;
		$this->miscService = $miscService;
	}


	/**
	 * @return LocksQueryBuilder
	 */
	public function getQueryBuilder(): LocksQueryBuilder {
		$qb = new LocksQueryBuilder(
			$this->dbConnection,
			OC::$server->getSystemConfig(),
			$this->logger
		);

		return $qb;
	}


	/**
	 * @return IDBConnection
	 */
	public function getConnection(): IDBConnection {
		return $this->dbConnection;
	}


	/**
	 * this just empty all tables from the app.
	 */
	public function emptyAll() {
		foreach ($this->tables as $table) {
			$qb = $this->dbConnection->getQueryBuilder();
			$qb->delete($table);

			$qb->execute();
		}
	}


	/**
	 *
	 */
	public function uninstall() {
		$this->dropAll();
		$this->removeFromJobs();;
		$this->removeFromMigrations();
	}


	/**
	 * this just empty all tables from the app.
	 */
	public function dropAll() {
		$schema = new SchemaWrapper($this->dbConnection);
		foreach ($this->tables as $table) {
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

