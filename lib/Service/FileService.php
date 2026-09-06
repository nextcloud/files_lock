<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * Class FileService
 *
 * @package OCA\FilesLock\Service
 */
class FileService {
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}

	/**
	 * Resolve a file id from the point of view of a user.
	 *
	 * @throws NotFoundException
	 */
	public function getFileFromId(string $userId, int $fileId): Node {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		if ($node === null) {
			throw new NotFoundException();
		}

		return $node;
	}
}
