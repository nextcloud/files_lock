<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Child process of AcquisitionTest::testConcurrentAcquisitionYieldsOneLock.
 *
 * Usage: php concurrent-lock.php <user id> <file id> <start microtime>
 * Prints LOCKED, CONFLICT or ERROR <details> after racing ILockManager::lock().
 */

use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;

define('OC_CONSOLE', 1);
require_once __DIR__ . '/../../../../../lib/base.php';
while (ob_get_level() > 0) {
	ob_end_clean();
}

[, $userId, $fileId, $startAt] = $argv;

try {
	\OC_User::setUserId($userId);
	\OC_Util::setupFS($userId);
	$node = \OCP\Server::get(IRootFolder::class)->getUserFolder($userId)->getFirstNodeById((int)$fileId);
	if ($node === null) {
		fwrite(STDOUT, "ERROR file not found\n");
		exit(1);
	}
	$lockManager = \OCP\Server::get(ILockManager::class);
	while (microtime(true) < (float)$startAt) {
		usleep(100);
	}
	$lockManager->lock(new LockContext($node, ILock::TYPE_USER, $userId));
	fwrite(STDOUT, "LOCKED\n");
} catch (OwnerLockedException) {
	fwrite(STDOUT, "CONFLICT\n");
} catch (\Throwable $e) {
	fwrite(STDOUT, 'ERROR ' . $e::class . ': ' . $e->getMessage() . "\n");
	exit(1);
}
