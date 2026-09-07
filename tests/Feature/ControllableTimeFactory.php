<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\AppFramework\Utility\TimeFactory;

/**
 * A real clock that can be moved forward. Unlike a PHPUnit mock it keeps
 * working after the test that created it ended, which matters for singletons
 * (share manager, share provider) that keep a reference to it.
 */
class ControllableTimeFactory extends TimeFactory {
	public ?int $time = null;

	#[\Override]
	public function getTime(): int {
		return $this->time ?? time();
	}

	#[\Override]
	public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
		if ($time === 'now' && $this->time !== null) {
			return (new \DateTime('@' . $this->time))->setTimezone($timezone ?? new \DateTimeZone('UTC'));
		}
		return parent::getDateTime($time, $timezone);
	}

	#[\Override]
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable('@' . $this->getTime());
	}
}
