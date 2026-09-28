<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Migration;

use OCP\Migration\BigIntMigration;

class Version35000Date20260928000000 extends BigIntMigration {

	#[\Override]
	protected function getColumnsByTable(): array {
		return [
			'files_lock' => ['id', 'file_id', 'ttl'],
		];
	}
}
