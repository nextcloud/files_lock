<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesLock;

use OCP\Capabilities\ICapability;

class Capability implements ICapability {
	public function getCapabilities() {
		return [
			'files' => [
				'locking' => '1.0',
				'api-feature-lock-type' => true,
			]
		];
	}
}
