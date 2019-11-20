<?php declare(strict_types=1);


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


namespace OCA\FilesLock\Controller;


use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use Exception;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;


/**
 * Class LockController
 *
 * @package OCA\FilesLock\Controller
 */
class LockController extends Controller {


	use TNCDataResponse;


	/** @var IUserSession */
	private $userSession;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var MiscService */
	private $miscService;


	/**
	 * LockController constructor.
	 *
	 * @param IRequest $request
	 * @param IUserSession $userSession
	 * @param FileService $fileService
	 * @param LockService $lockService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IRequest $request, IUserSession $userSession, FileService $fileService, LockService $lockService,
		MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);
		$this->userSession = $userSession;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->miscService = $miscService;
	}


	/**
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $fileId
	 *
	 * @return DataResponse
	 */
	public function locking(string $fileId): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$file = $this->fileService->getFileFromId($user->getUID(), (int)$fileId);

			$lock = $this->lockService->lockFile($file, $user);

			return $this->directSuccess($lock);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $fileId
	 *
	 * @return DataResponse
	 */
	public function unlocking(string $fileId): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$this->lockService->unlockFile((int)$fileId, $user->getUID());

			return $this->success();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

}

