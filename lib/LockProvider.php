<?php

namespace OCA\FilesLock;

use OCA\FilesLock\Service\LockService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockProvider;
use OCP\Files\Lock\LockScope;
use OCP\Files\Lock\OwnerLockedException;
use OCP\PreConditionNotMetException;

class LockProvider implements ILockProvider {

	private LockService $lockService;

	public function __construct(LockService $lockService) {
		$this->lockService = $lockService;
	}

	public function getLocks(int $fileId): array {
		$lock = $this->lockService->getLockFromFileId($fileId);
		if ($lock) {
			return [$lock];
		}
		return [];
	}

	/**
	 * @inheritdoc
	 */
	public function lock(LockScope $lockInfo): ILock {
		return $this->lockService->lock($lockInfo);
	}

	/**
	 * @inheritdoc
	 */
	public function unlock(LockScope $lockInfo): void {
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
