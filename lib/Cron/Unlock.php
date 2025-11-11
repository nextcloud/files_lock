<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Cron;

use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class Unlock extends TimedJob {
	private LockService $lockService;

	public function __construct(ITimeFactory $timeFactory, LockService $lockService) {
		parent::__construct($timeFactory);

		$this->lockService = $lockService;

		$this->setInterval(12 * 60);
	}

	protected function run($argument): void {
		$this->deleteExpiredLocks();
	}

	private function deleteExpiredLocks(): void {
		$this->lockService->removeLocks($this->lockService->getDeprecatedLocks(1000));
	}
}
