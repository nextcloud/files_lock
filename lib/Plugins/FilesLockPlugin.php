<?php declare(strict_types=1);


/**
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\FilesLock\Plugins;


use Exception;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\IUserSession;
use Sabre\DAV\Locks\Backend\BackendInterface;
use Sabre\DAV\Locks\LockInfo;


/**
 * Class AppLockPlugin
 *
 * @package OCA\DAV\Files
 */
class FilesLockPlugin implements BackendInterface {


	/** @var IUserSession */
	private $userSession;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var bool */
	private $absolute = false;


	/**
	 * FilesLockPlugin constructor.
	 *
	 * @param IUserSession $userSession
	 * @param FileService $fileService
	 * @param LockService $lockService
	 * @param bool $absolute
	 */
	public function __construct(
		IUserSession $userSession, FileService $fileService, LockService $lockService, bool $absolute
	) {
		$this->userSession = $userSession;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->absolute = $absolute;
	}


	/**
	 * @param string $uri
	 * @param bool $returnChildLocks
	 *
	 * @return LockInfo[]
	 */
	function getLocks($uri, $returnChildLocks): array {
		$locks = [];
		try {
			// TODO: check parent
			if ($this->absolute) {
				$file = $this->fileService->getFileFromAbsoluteUri($uri);
			} else {
				$file = $this->fileService->getFileFromUri($uri);
			}

			$lock = $this->lockService->getLockFromFileId($file->getId());

			$user = $this->userSession->getUser();
			if ($user !== null && $lock->getUserId() === $user->getUID()) {
				return [];
			}

			return [$lock->toLockInfo()];
		} catch (Exception $e) {
			return $locks;
		}
	}


	/**
	 * Locks a uri
	 *
	 * @param string $uri
	 * @param LockInfo $lockInfo
	 *
	 * @return bool
	 */
	function lock($uri, LockInfo $lockInfo): bool {
		return true;
	}


	/**
	 * Removes a lock from a uri
	 *
	 * @param string $uri
	 * @param LockInfo $lockInfo
	 *
	 * @return bool
	 */
	function unlock($uri, LockInfo $lockInfo): bool {
		return true;
	}


}








