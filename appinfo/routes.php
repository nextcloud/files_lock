<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'ocs' => [
		['name' => 'Lock#locking', 'url' => '/lock/{fileId}', 'verb' => 'PUT'],
		['name' => 'Lock#unlocking', 'url' => '/lock/{fileId}', 'verb' => 'DELETE'],
	]
];
