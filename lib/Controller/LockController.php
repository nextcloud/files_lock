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


namespace OCA\FilesLock\Controller;

use Exception;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Tools\Traits\TLogger;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class LockController
 *
 * @package OCA\FilesLock\Controller
 */
class LockController extends OCSController {
	use TLogger;

	private int $ocsVersion;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private FileService $fileService,
		private LockService $lockService,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userSession = $userSession;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->l10n = $l10n;

		// We need to overload some implementation from the OCSController here
		// to be able to push a custom message and data when returning other
		// HTTP status codes than 200 OK
		$this->registerResponder('json', function ($data) {
			return $this->buildOCSResponse('json', $data);
		});

		$this->registerResponder('xml', function ($data) {
			return $this->buildOCSResponse('xml', $data);
		});
	}


	/**
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $fileId
	 *
	 * @return DataResponse
	 */
	public function locking(string $fileId, int $lockType = ILock::TYPE_USER): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$file = $this->fileService->getFileFromId($user->getUID(), (int)$fileId);

			$lock = $this->lockService->lock(new LockContext(
				$file, $lockType, $user->getUID()
			));

			return new DataResponse($lock, Http::STATUS_OK);
		} catch (OwnerLockedException $e) {
			return new DataResponse($e->getLock(), Http::STATUS_LOCKED);
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
	public function unlocking(string $fileId, int $lockType = ILock::TYPE_USER): DataResponse {
		try {
			$user = $this->userSession->getUser();
			$this->lockService->enableUserOverride();
			$this->lockService->unlockFile((int)$fileId, $user->getUID());

			return new DataResponse();
		} catch (LockNotFoundException $e) {
			$response = new DataResponse();
			$response->setStatus(Http::STATUS_PRECONDITION_FAILED);
			return $response;
		} catch (UnauthorizedUnlockException $e) {
			$lock = $this->lockService->getLockFromFileId((int)$fileId);
			$response = new DataResponse();
			$response->setStatus(Http::STATUS_LOCKED);
			$response->setData($lock->jsonSerialize());
			return $response;
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	public function setOCSVersion($version) {
		$this->ocsVersion = $version;
	}

	private function buildOCSResponse($format, DataResponse $data) {
		$message = null;
		if ($data->getStatus() === Http::STATUS_LOCKED) {
			/** @var FileLock $lock */
			$lock = $data->getData();
			$message = $this->l10n->t('File is currently locked by %s', [$lock->getOwner()]);
		}
		if ($data->getStatus() === Http::STATUS_PRECONDITION_FAILED) {
			/** @var FileLock $lock */
			$lock = $data->getData();
			$message = $this->l10n->t('File is not locked');
		}

		$containedData = $data->getData();
		if ($containedData instanceof FileLock) {
			$data->setData($data->getData()->jsonSerialize());
		}

		if ($this->ocsVersion === 1) {
			return new \OC\AppFramework\OCS\V1Response($data, $format, $message);
		}
		return new \OC\AppFramework\OCS\V2Response($data, $format, $message);
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
