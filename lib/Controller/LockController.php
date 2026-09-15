<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Controller;

use Exception;
use OC\AppFramework\OCS\V1Response;
use OC\AppFramework\OCS\V2Response;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoSubAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
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

	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly FileService $fileService,
		private readonly LockService $lockService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);

		// We need to overload some implementation from the OCSController here
		// to be able to push a custom message and data when returning other
		// HTTP status codes than 200 OK
		$this->registerResponder('json', fn (DataResponse $data): V1Response|V2Response
			=> $this->buildOCSResponse('json', $data));

		$this->registerResponder('xml', fn (DataResponse $data): V1Response|V2Response
			=> $this->buildOCSResponse('xml', $data));
	}

	/**
	 * @param ILock::TYPE_* $lockType
	 */
	#[NoAdminRequired]
	#[NoSubAdminRequired]
	public function locking(string $fileId, int $lockType = ILock::TYPE_USER): DataResponse {
		if (!in_array($lockType, Application::SUPPORTED_LOCK_TYPES, true)) {
			return $this->fail(new \InvalidArgumentException('Unsupported lock type'), [], Http::STATUS_BAD_REQUEST, false);
		}
		if (!is_numeric($fileId)) {
			return $this->fail(new \InvalidArgumentException('Invalid file id'), [], Http::STATUS_BAD_REQUEST, false);
		}

		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new \LogicException('User not logged in');
			}

			$file = $this->fileService->getFileFromId($user->getUID(), (int)$fileId);
			$lock = $this->lockService->acquire(new LockContext(
				$file, $lockType, $user->getUID()
			));

			return new DataResponse($lock, Http::STATUS_OK);
		} catch (OwnerLockedException $e) {
			return new DataResponse($e->getLock(), Http::STATUS_LOCKED);
		} catch (NotFoundException $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		} catch (NotFileException $e) {
			return $this->fail($e, [], Http::STATUS_BAD_REQUEST, false);
		} catch (UnauthorizedUnlockException|NotPermittedException $e) {
			return $this->fail($e, [], Http::STATUS_FORBIDDEN, false);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[NoAdminRequired]
	#[NoSubAdminRequired]
	public function unlocking(string $fileId, int $lockType = ILock::TYPE_USER): DataResponse {
		if (!in_array($lockType, Application::SUPPORTED_LOCK_TYPES, true)) {
			return $this->fail(new \InvalidArgumentException('Unsupported lock type'), [], Http::STATUS_BAD_REQUEST, false);
		}
		if (!is_numeric($fileId)) {
			return $this->fail(new \InvalidArgumentException('Invalid file id'), [], Http::STATUS_BAD_REQUEST, false);
		}

		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new \LogicException('User not logged in');
			}

			$this->lockService->unlockFile((int)$fileId, $user->getUID(), false, $lockType);

			return new DataResponse();
		} catch (LockNotFoundException) {
			$response = new DataResponse();
			$response->setStatus(Http::STATUS_PRECONDITION_FAILED);
			return $response;
		} catch (UnauthorizedUnlockException) {
			$lock = $this->lockService->getActiveLock((int)$fileId);
			if ($lock === null) {
				$response = new DataResponse();
				$response->setStatus(Http::STATUS_PRECONDITION_FAILED);
				return $response;
			}
			return new DataResponse($lock, Http::STATUS_LOCKED);
		} catch (NotFoundException $e) {
			return $this->fail($e, [], Http::STATUS_NOT_FOUND, false);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	#[\Override]
	public function setOCSVersion($version): void {
		$this->ocsVersion = $version;
	}

	private function buildOCSResponse(string $format, DataResponse $data): V1Response|V2Response {
		$message = null;
		$containedData = $data->getData();
		if ($data->getStatus() === Http::STATUS_LOCKED && $containedData instanceof FileLock) {
			$this->lockService->injectMetadata($containedData);
			$message = $this->l10n->t('File is currently locked by %s', [$containedData->getDisplayName() ?? $containedData->getOwner()]);
		}
		if ($data->getStatus() === Http::STATUS_PRECONDITION_FAILED) {
			$message = $this->l10n->t('File is not locked');
		}

		if ($containedData instanceof FileLock) {
			$payload = $containedData->jsonSerialize();
			if ($data->getStatus() === Http::STATUS_LOCKED) {
				// the token is the credential of a token lock and OCS never accepts
				// one, so the caller that just lost the conflict has no use for it
				unset($payload['token']);
			}
			$data->setData($payload);
		}

		if ($this->ocsVersion === 1) {
			return new V1Response($data, $format, $message);
		}
		return new V2Response($data, $format, $message);
	}

	/**
	 * @param HTTP::STATUS_* $status
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
				'exception' => $e::class,
				'message' => $e->getMessage()
			]
		);

		if ($log) {
			$this->logger->warning('[warning] ' . $status . ' - ' . json_encode($data, JSON_THROW_ON_ERROR));
		}

		return new DataResponse($data, $status);
	}
}
