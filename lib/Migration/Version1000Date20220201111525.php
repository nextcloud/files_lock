<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesLock\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version1000Date20220201111525 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('files_lock');

		$hasSchemaChanges = false;
		if (!$table->hasColumn('type')) {
			$table->addColumn(
				'type', Types::SMALLINT,
				[
					'default' => 0,
					'unsigned' => true,
				]
			);
			$hasSchemaChanges = true;
		}

		if (!$table->hasColumn('scope')) {
			$table->addColumn(
				'scope', Types::SMALLINT,
				[
					'default' => 0,
					'unsigned' => true,
				]
			);
			$hasSchemaChanges = true;
		}

		if (!$table->hasColumn('ttl')) {
			$table->addColumn(
				'ttl', Types::INTEGER,
				[
					'default' => 0,
				]
			);
			$hasSchemaChanges = true;
		}
		return $hasSchemaChanges ? $schema : null;
	}
}
