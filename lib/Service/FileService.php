<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Service;

use OC\User\NoUserException;
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
	/** @var IUserSession */
	private $userSession;

	/** @var IRootFolder */
	private $rootFolder;


	public function __construct(IUserSession $userSession, IRootFolder $rootFolder) {
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
	}


	/**
	 * @param string $userId
	 * @param int $fileId
	 *
	 * @return Node
	 * @throws NotFoundException
	 */
	public function getFileFromId(string $userId, int $fileId): Node {
		$files = $this->rootFolder->getUserFolder($userId)
			->getById($fileId);

		if (sizeof($files) === 0) {
			throw new NotFoundException();
		}

		$file = array_shift($files);

		return $file;
	}


	/**
	 * @param string $path
	 * @param string $userId
	 *
	 * @return Node
	 * @throws NotFoundException
	 */
	public function getFileFromPath(string $userId, string $path): Node {
		if (substr($path, 0, 6) !== 'files/') {
			throw new NotFoundException();
		}

		$path = '/' . substr($path, 6);
		$file = $this->rootFolder->getUserFolder($userId)
			->get($path);

		return $file;
	}


	/**
	 * @param string $uri
	 *
	 * @return Node
	 * @throws NotFoundException
	 */
	public function getFileFromUri(string $uri): Node {
		$user = $this->userSession->getUser();
		if (is_null($user)) {
			throw new SessionNotAvailableException();
		}

		$userId = $user->getUID();

		$path = '/' . $uri;
		$file = $this->rootFolder->getUserFolder($userId)
			->get($path);

		return $file;
	}


	/**
	 * @param string $uri
	 *
	 * @return Node
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws NoUserException
	 */
	public function getFileFromAbsoluteUri(string $uri): Node {
		[$root, $userId, $path] = explode('/', trim($uri, '/') . '/', 3);
		if ($root !== 'files') {
			throw new NotFoundException();
		}
		$path = '/' . $path;
		$file = $this->rootFolder->getUserFolder($userId)
			->get($path);

		return $file;
	}
}
