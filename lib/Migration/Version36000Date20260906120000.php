<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Migration;

use Closure;
use OCA\FilesLock\Db\LocksRequest;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Introduce the absolute expiry column and enforce a single active lock per file.
 *
 * Existing databases may contain several rows for one file (acquisition used to
 * be check-then-insert without a constraint). Before the unique index is created
 * the most recently created row of each file wins and the others are removed.
 */
class Version36000Date20260906120000 extends SimpleMigrationStep {
	public const INDEX_FILE_ID = 'files_lock_file_id';
	public const INDEX_EXPIRES = 'files_lock_expires_at';

	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(LocksRequest::TABLE_LOCKS)) {
			return;
		}
		if ($schema->getTable(LocksRequest::TABLE_LOCKS)->hasIndex(self::INDEX_FILE_ID)) {
			return;
		}

		$removed = $this->removeDuplicateLocks();
		if ($removed > 0) {
			$output->info('Removed ' . $removed . ' superseded duplicate file lock rows');
		}
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable(LocksRequest::TABLE_LOCKS);
		$changed = false;

		if (!$table->hasColumn('expires_at')) {
			$table->addColumn('expires_at', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}

		// the unique index replacing it is created by the next migration step,
		// some databases refuse a second index on the same column list
		foreach ($table->getIndexes() as $index) {
			if ($index->isSimpleIndex() && $index->spansColumns(['file_id'])) {
				$table->dropIndex($index->getName());
				$changed = true;
			}
		}

		if (!$table->hasIndex(self::INDEX_EXPIRES)) {
			$table->addIndex(['expires_at'], self::INDEX_EXPIRES);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(LocksRequest::TABLE_LOCKS)
			->set('expires_at', $qb->func()->add('creation', 'ttl'))
			->where($qb->expr()->isNull('expires_at'))
			->andWhere($qb->expr()->gt('ttl', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Keep the row with the highest id for every file that has more than one lock.
	 *
	 * @return int number of rows removed
	 */
	public function removeDuplicateLocks(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('file_id')
			->selectAlias($qb->func()->max('id'), 'keep_id')
			->selectAlias($qb->func()->count('id'), 'lock_count')
			->from(LocksRequest::TABLE_LOCKS)
			->groupBy('file_id')
			->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$duplicates = [];
		while ($row = $result->fetch()) {
			$duplicates[(int)$row['file_id']] = (int)$row['keep_id'];
		}
		$result->closeCursor();

		$removed = 0;
		foreach ($duplicates as $fileId => $keepId) {
			$delete = $this->connection->getQueryBuilder();
			$delete->delete(LocksRequest::TABLE_LOCKS)
				->where($delete->expr()->eq('file_id', $delete->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($delete->expr()->neq('id', $delete->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
			$removed += $delete->executeStatement();
		}

		return $removed;
	}
}
