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
		$file = $this->rootFolder->getUserFolder($userId)
								 ->get($path);

		return $file;
	}
}
