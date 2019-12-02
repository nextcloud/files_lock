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


namespace OCA\FilesLock\Migration;


use Closure;
use Exception;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0001Date20191105000001
 *
 * @package OCA\FilesLock\Migration
 */
class Version0001Date20191105000001 extends SimpleMigrationStep {


	/** @var IDBConnection */
	private $connection;


	/**
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_lock')) {
			return $schema;
		}

		$table = $schema->createTable('files_lock');

		$table->addColumn(
			'id', 'integer',
			[
				'autoincrement' => true,
				'unsigned' => true,
				'notnull' => true,
				'length'  => 11
			]
		);
		$table->addColumn(
			'user_id', 'string',
			[
				'notnull' => true,
				'length'  => 255,
			]
		);
		$table->addColumn(
			'file_id', 'integer',
			[
				'notnull' => true,
				'unsigned' => true,
				'length'  => 11,
			]
		);
		$table->addColumn(
			'token', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'creation', 'bigint',
			[
				'notnull' => true,
				'unsigned' => true,
				'length' => 14
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['file_id']);
		$table->addUniqueIndex(['token']);

		return $schema;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @throws Exception
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}

}

