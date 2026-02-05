<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesLock;

use OCA\FilesLock\Service\LockService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockProvider;
use OCP\Files\Lock\LockContext;
use OCP\PreConditionNotMetException;

class LockProvider implements ILockProvider {
	private LockService $lockService;

	public function __construct(LockService $lockService) {
		$this->lockService = $lockService;
	}

	public function getLocks(int $fileId): array {
		$lock = $this->lockService->getLockForNodeId($fileId);
		if (!$lock) {
			return [];
		}

		$this->lockService->injectMetadata($lock);
		return [$lock];
	}

	/**
	 * @inheritdoc
	 */
	public function lock(LockContext $lockInfo): ILock {
		return $this->lockService->lock($lockInfo);
	}

	/**
	 * @inheritdoc
	 */
	public function unlock(LockContext $lockInfo): void {
		try {
			$this->lockService->getLockFromFileId($lockInfo->getNode()->getId());
		} catch (Exceptions\LockNotFoundException $e) {
			throw new PreConditionNotMetException('No lock found');
		}

		try {
			$this->lockService->unlock($lockInfo);
		} catch (Exceptions\LockNotFoundException $e) {
			throw new PreConditionNotMetException('No lock found for scope');
		} catch (Exceptions\UnauthorizedUnlockException $e) {
			throw new PreConditionNotMetException('Unauth unlock');
		}
	}
}
