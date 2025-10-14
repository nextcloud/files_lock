<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

		$qb->executeStatement();
	}

	/**
	 *
	 */
	public function removeFromJobs() {
		$qb = $this->getQueryBuilder();
		$qb->delete('jobs');
		$qb->where($qb->exprLimitToDBField('class', 'OCA\FilesLock\Cron\Unlock', true, true));
		$qb->executeStatement();
	}
}
