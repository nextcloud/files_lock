/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'
import type { LockState } from './types.ts'

import { getCurrentUser } from '@nextcloud/auth'
import { Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { LockType } from './types.ts'

/**
 *
 * @param node Node
 */
export function getLockStateFromAttributes(node: Node): LockState {
	return {
		isLocked: !!node.attributes.lock,
		lockOwner: node.attributes['lock-owner'],
		lockOwnerDisplayName: node.attributes['lock-owner-displayname'],
		lockOwnerType: parseInt(node.attributes['lock-owner-type']),
		lockOwnerEditor: node.attributes['lock-owner-editor'],
		lockTime: parseInt(node.attributes['lock-time']),
	}
}

/**
 *
 * @param node Node
 */
export function canLock(node: Node): boolean {
	const state = getLockStateFromAttributes(node)

	if (!state.isLocked && isUpdatable(node)) {
		return true
	}

	return false
}

/**
 *
 * @param node Node
 */
export function canUnlock(node: Node): boolean {
	const state = getLockStateFromAttributes(node)

	if (!state.isLocked) {
		return false
	}

	if (!isUpdatable(node)) {
		return false
	}

	if (state.lockOwnerType === LockType.User && state.lockOwner === getCurrentUser()?.uid) {
		return true
	}

	if (state.lockOwnerType === LockType.Token && state.lockOwner === getCurrentUser()?.uid) {
		return true
	}

	if (node.owner === getCurrentUser()?.uid) {
		return true
	}

	return false
}

/**
 *
 * @param userId string
 */
export function generateAvatarSvg(userId: string) {
	const avatarUrl = generateUrl('/avatar/{userId}/32', { userId })
	return `<svg width="32" height="32" viewBox="0 0 32 32"
		xmlns="http://www.w3.org/2000/svg" class="sharing-status__avatar">
		<image href="${avatarUrl}" height="32" width="32" />
	</svg>`
}

/**
 *
 * @param node Node
 */
export function getInfoLabel(node: Node): string {
	const state = getLockStateFromAttributes(node)

	if (state.lockOwnerType === LockType.User) {
		return state.isLocked
			? t('files_lock', 'Manually locked by {user}', { user: state.lockOwnerDisplayName })
			: ''
	} else if (state.lockOwnerType === LockType.App) {
		return state.isLocked
			? t('files_lock', 'Locked by editing online in {app}', { app: state.lockOwnerDisplayName })
			: ''
	} else {
		return state.isLocked
			? t('files_lock', 'Automatically locked by {user}', { user: state.lockOwnerDisplayName })
			: ''
	}

	return ''
}

/**
 *
 * @param node Node
 */
export function isUpdatable(node: Node): boolean {
	return (node.permissions & Permission.UPDATE) !== 0 && (node.attributes['share-permissions'] & Permission.UPDATE) !== 0
}
