<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table as DBALTable;
use OCA\FilesLock\Migration\Version0001Date20191105000001;
use OCA\FilesLock\Migration\Version1000Date20220201111525;
use OCA\FilesLock\Migration\Version1000Date20220430180808;
use OCA\FilesLock\Migration\Version35000Date20260928000000;
use OC\DB\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version35000Date20260928000000Test extends TestCase {

	public function testMigrationPreservesSchemaAndCanRunAgain(): void {
		$table = new Table(new DBALTable('files_lock'));
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('files_lock')->willReturn(false);
		$schema->method('createTable')->with('files_lock')->willReturn($table);
		$schema->method('getTable')->with('files_lock')->willReturn($table);
		$output = $this->createMock(IOutput::class);
		$schemaClosure = static fn () => $schema;

		(new Version0001Date20191105000001($this->createMock(IDBConnection::class)))->changeSchema($output, $schemaClosure, []);
		(new Version1000Date20220201111525())->changeSchema($output, $schemaClosure, []);
		(new Version1000Date20220430180808())->changeSchema($output, $schemaClosure, []);
		$before = clone $table->getWrappedTable();

		$migration = new Version35000Date20260928000000();
		for ($run = 0; $run < 2; $run++) {
			self::assertSame($schema, $migration->changeSchema($output, $schemaClosure, []));
			foreach ($table->getWrappedTable()->getColumns() as $name => $column) {
				$expected = $before->getColumn($name)->toArray();
				if (in_array($name, ['id', 'file_id', 'ttl'], true)) {
					self::assertSame(Types::BIGINT, $column->getType()->getName());
					$expected['type'] = $column->getType();
					$expected['length'] = 20;
				}
				self::assertEquals($expected, $column->toArray(), $name);
			}
			self::assertEquals($before->getIndexes(), $table->getWrappedTable()->getIndexes());
		}
	}
}
