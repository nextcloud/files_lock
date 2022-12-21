<?php

declare(strict_types=1);


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
		$this->limitArray('id', $ids);
	}

	public function limitToFileIds(array $ids): void {
		$this->limitInIntArray('file_id', $ids);
	}
}
