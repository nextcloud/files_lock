<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Controller;

use Exception;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class LockController
 *
 * @package OCA\FilesLock\Controller
 */
class LockController extends OCSController {

	private int $ocsVersion;
	private LoggerInterface $logger;

	public function __construct(
		IRequest $request,
		LoggerInterface $logger,
		private IUserSession $userSession,
		private FileService $fileService,
		private LockService $lockService,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);

		// We need to overload some implementation from the OCSController here
		// to be able to push a custom message and data when returning other
		// HTTP status codes than 200 OK
		$this->registerResponder('json', function ($data) {
			return $this->buildOCSResponse('json', $data);
		});

		$this->registerResponder('xml', function ($data) {
			return $this->buildOCSResponse('xml', $data);
		});
		$this->logger = $logger;
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
			$lock = new FileLock();
			$lock->import($data->getData());
			$this->lockService->injectMetadata($lock);
			$message = $this->l10n->t('File is currently locked by %s', [$lock->getDisplayName()]);
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
		bool $log = true,
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
			$this->logger->warning('[warning] ' . $status . ' - ' . json_encode($data));
		}

		return new DataResponse($data, $status);
	}
}
