<?php
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

define('PHPUNIT_RUN', 1);

$configDir = getenv('CONFIG_DIR');
if ($configDir) {
	define('PHPUNIT_CONFIG_DIR', $configDir);
}

require_once __DIR__ . '/../../../lib/base.php';

\OC::$composerAutoloader->addPsr4('Test\\', OC::$SERVERROOT . '/tests/lib/', true);
\OC::$composerAutoloader->addPsr4('Tests\\', OC::$SERVERROOT . '/tests/', true);

// load all enabled apps
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApps();
\OCP\Server::get(\OCP\App\IAppManager::class)->enableApp('files_lock', true);
\OC_App::updateApp('files_lock');

set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/share/php');
