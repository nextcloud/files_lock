/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { logger } from './services/logger.ts'

/**
 *
 * @param node Node
 */
export async function lockFile(node: Node) {
	const result = await axios.put(generateOcsUrl(`/apps/files_lock/lock/${node.fileid}`))
	logger.debug('lock result', result)
	return result?.data?.ocs?.data
}

/**
 *
 * @param node Node
 */
export async function unlockFile(node: Node) {
	const result = await axios.delete(generateOcsUrl(`/apps/files_lock/lock/${node.fileid}`))
	logger.debug('lock result', result)
	return result?.data?.ocs?.data
}
