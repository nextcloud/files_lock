<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use OCA\FilesLock\Tools\Db\ExtendedQueryBuilder;

/**
 *
 */
class CoreQueryBuilder extends ExtendedQueryBuilder {
	/**
	 * @param int $fileId
	 *
	 * @return CoreQueryBuilder
	 */
	public function limitToFileId(int $fileId): void {
		$this->limitInt('file_id', $fileId);
	}


	/**
	 * @param array $ids
	 */
	public function limitToIds(array $ids): void {
		$this->limitInArray('id', $ids);
	}

	public function limitToFileIds(array $ids): void {
		$this->limitInIntArray('file_id', $ids);
	}
}
