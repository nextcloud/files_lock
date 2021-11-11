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


use Exception;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Tools\Traits\TLogger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;


/**
 * Class LockController
 *
 * @package OCA\FilesLock\Controller
 */
class LockController extends Controller {


	use TLogger;


	/** @var IUserSession */
	private $userSession;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;


	/**
	 * LockController constructor.
	 *
	 * @param IRequest $request
	 * @param IUserSession $userSession
	 * @param FileService $fileService
	 * @param LockService $lockService
	 */
	public function __construct(
		IRequest $request, IUserSession $userSession, FileService $fileService, LockService $lockService
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userSession = $userSession;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
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

			return new DataResponse($lock, Http::STATUS_OK);
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

			return new DataResponse();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @param Exception $e
	 * @param array $more
	 * @param int $status
	 *
	 * @param bool $log
	 *
	 * @return DataResponse
	 */
	protected function fail(
		Exception $e,
		array $more = [],
		int $status = Http::STATUS_INTERNAL_SERVER_ERROR,
		bool $log = true
	): DataResponse {
		$data = array_merge(
			$more,
			[
				'status' => -1,
				'exception' => get_class($e),
				'message' => $e->getMessage()
			]
		);

		if ($log) {
			$this->log(2, $status . ' - ' . json_encode($data));
		}

		return new DataResponse($data, $status);
	}
}
