<?php

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
