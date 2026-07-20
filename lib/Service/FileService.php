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
use OCP\Files\NotPermittedException;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * Class FileService
 *
 * @package OCA\FilesLock\Service
 */
class FileService {
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IRootFolder $rootFolder,
	) {
	}

	/**
	 *
	 * @throws NotFoundException
	 */
	public function getFileFromId(string $userId, int $fileId): Node {
		$files = $this->rootFolder->getUserFolder($userId)
			->getById($fileId);

		if (sizeof($files) === 0) {
			throw new NotFoundException();
		}

		return array_shift($files);
	}

	/**
	 *
	 * @throws NotFoundException
	 */
	public function getFileFromPath(string $userId, string $path): Node {
		if (!str_starts_with($path, 'files/')) {
			throw new NotFoundException();
		}

		$path = '/' . substr($path, 6);

		return $this->rootFolder->getUserFolder($userId)
			->get($path);
	}

	/**
	 * @throws NotFoundException
	 */
	public function getFileFromUri(string $uri): Node {
		$user = $this->userSession->getUser();
		if (is_null($user)) {
			throw new SessionNotAvailableException();
		}

		$userId = $user->getUID();

		$path = '/' . $uri;

		return $this->rootFolder->getUserFolder($userId)
			->get($path);
	}

	/**
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getFileFromAbsoluteUri(string $uri): Node {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new SessionNotAvailableException();
		}

		$userId = $user->getUID();

		[$root, , $path] = explode('/', trim($uri, '/') . '/', 3);
		if ($root !== 'files') {
			throw new NotFoundException();
		}
		$path = '/' . $path;

		return $this->rootFolder->getUserFolder($userId)
			->get($path);
	}
}
