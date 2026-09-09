<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\DB\ConnectionAdapter;
use OC\DB\SchemaWrapper;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Migration\Version36000Date20260906120000;
use OCA\FilesLock\Migration\Version36000Date20260906120100;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\Group;

/**
 * The migration reconciles duplicate rows before enforcing uniqueness and
 * derives the absolute expiry from the previous creation + ttl model.
 */
#[Group(name: 'DB')]
class MigrationTest extends LockTestCase {
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = \OCP\Server::get(IDBConnection::class);
	}

	protected function tearDown(): void {
		$this->runMigration();
		parent::tearDown();
	}

	private function schemaWrapper(): SchemaWrapper {
		/** @var ConnectionAdapter $adapter */
		$adapter = $this->connection;
		return new SchemaWrapper($adapter->getInner());
	}

	private function runMigration(): void {
		$output = $this->createMock(IOutput::class);
		$schemaClosure = fn (): SchemaWrapper => $this->schemaWrapper();
		$step1 = new Version36000Date20260906120000($this->connection);
		$step1->preSchemaChange($output, $schemaClosure, []);
		$schema = $step1->changeSchema($output, $schemaClosure, []);
		if ($schema !== null) {
			$this->connection->migrateToSchema($schema->getWrappedSchema());
		}
		$step1->postSchemaChange($output, $schemaClosure, []);
		$step2 = new Version36000Date20260906120100();
		$schema = $step2->changeSchema($output, $schemaClosure, []);
		if ($schema !== null) {
			$this->connection->migrateToSchema($schema->getWrappedSchema());
		}
	}

	/**
	 * Bring the table back to the shape of the previous release.
	 */
	private function downgradeSchema(): void {
		$schema = $this->schemaWrapper();
		$table = $schema->getTable(LocksRequest::TABLE_LOCKS);
		foreach ([Version36000Date20260906120000::INDEX_FILE_ID, Version36000Date20260906120000::INDEX_EXPIRES] as $index) {
			if ($table->hasIndex($index)) {
				$table->dropIndex($index);
			}
		}
		if ($table->hasColumn('expires_at')) {
			$table->dropColumn('expires_at');
		}
		$table->addIndex(['file_id'], 'files_lock_old_file_id');
		$this->connection->migrateToSchema($schema->getWrappedSchema());
	}

	private function insertLegacyRow(int $fileId, string $user, string $token, int $creation, int $ttl): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(LocksRequest::TABLE_LOCKS)
			->setValue('user_id', $qb->createNamedParameter($user))
			->setValue('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->setValue('token', $qb->createNamedParameter($token))
			->setValue('creation', $qb->createNamedParameter($creation, IQueryBuilder::PARAM_INT))
			->setValue('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('ttl', $qb->createNamedParameter($ttl, IQueryBuilder::PARAM_INT))
			->setValue('owner', $qb->createNamedParameter($user));
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	public function testDuplicatesAreReconciledBeforeUniquenessIsEnforced(): void {
		$this->downgradeSchema();
		$table = $this->schemaWrapper()->getTable(LocksRequest::TABLE_LOCKS);
		self::assertFalse($table->hasColumn('expires_at'));
		self::assertFalse($table->hasIndex(Version36000Date20260906120000::INDEX_FILE_ID));

		$creation = 1700000000;
		$this->insertLegacyRow(4242, 'alice', 'files_lock/a', $creation, 1800);
		$this->insertLegacyRow(4242, 'bob', 'files_lock/b', $creation + 10, 1800);
		$keep = $this->insertLegacyRow(4242, 'carol', 'files_lock/c', $creation + 20, 0);
		$finite = $this->insertLegacyRow(4243, 'dave', 'files_lock/d', $creation, 600);
		$infinite = $this->insertLegacyRow(4244, 'erin', 'files_lock/e', $creation, -60);
		self::assertSame(3, $this->lockRowCount(4242));

		$this->runMigration();

		self::assertSame(1, $this->lockRowCount(4242));
		$request = \OCP\Server::get(LocksRequest::class);
		$survivor = $request->getFromFileId(4242);
		self::assertSame($keep, $survivor->getId(), 'the most recently created row wins');
		self::assertNull($survivor->getExpiresAt(), 'ttl 0 stays infinite');
		self::assertSame($creation + 600, $request->getFromFileId(4243)->getExpiresAt(), 'creation + ttl becomes the absolute expiry');
		self::assertNull($request->getFromFileId(4244)->getExpiresAt(), 'negative ttl stays infinite');
		self::assertSame($finite, $request->getFromFileId(4243)->getId());
		self::assertSame($infinite, $request->getFromFileId(4244)->getId());

		$table = $this->schemaWrapper()->getTable(LocksRequest::TABLE_LOCKS);
		self::assertTrue($table->hasColumn('expires_at'));
		self::assertTrue($table->hasIndex(Version36000Date20260906120000::INDEX_FILE_ID));
		self::assertTrue($table->getIndex(Version36000Date20260906120000::INDEX_FILE_ID)->isUnique());
		self::assertTrue($table->hasIndex(Version36000Date20260906120000::INDEX_EXPIRES));
		self::assertFalse($table->hasIndex('files_lock_old_file_id'), 'the old non-unique index is gone');

		// running it again is a no-op
		$this->runMigration();
		self::assertSame(1, $this->lockRowCount(4242));
	}
}
