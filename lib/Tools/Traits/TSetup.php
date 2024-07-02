<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\FilesLock\Tools\Traits;

use OC;
use OCP\IConfig;

/**
 *
 */
trait TSetup {
	use TArrayTools;


	/** @var array */
	private $_setup = [];


	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @param string $default
	 *
	 * @return string
	 */
	public function setup(string $key, string $value = '', string $default = ''): string {
		if ($value !== '') {
			$this->_setup[$key] = $value;
		}

		return $this->get($key, $this->_setup, $default);
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @param string $default
	 *
	 * @return string
	 */
	public function setupArray(string $key, array $value = [], array $default = []): array {
		if ($value !== '') {
			$this->_setup[$key] = $value;
		}

		return $this->getArray($key, $this->_setup, $default);
	}


	/**
	 * @param string $key
	 *
	 * @return string
	 */
	public function appConfig(string $key): string {
		$app = $this->setup('app');
		if ($app === '') {
			return '';
		}

		/** @var IConfig $config */
		$config = OC::$server->get(IConfig::class);

		return $config->getAppValue($app, $key, '');
	}
}
