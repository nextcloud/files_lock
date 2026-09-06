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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Enforce at most one lock row per file. Duplicates were reconciled and the
 * previous non-unique index dropped by Version36000Date20260906120000.
 */
class Version36000Date20260906120100 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable(LocksRequest::TABLE_LOCKS);
		if ($table->hasIndex(Version36000Date20260906120000::INDEX_FILE_ID)) {
			return null;
		}

		$table->addUniqueIndex(['file_id'], Version36000Date20260906120000::INDEX_FILE_ID);
		return $schema;
	}
}
