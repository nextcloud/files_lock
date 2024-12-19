/*
 * @copyright Copyright (c) 2023 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

import { type Node } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { type LockState, LockType } from './types'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'

export const getLockStateFromAttributes = (node: Node): LockState => {
	return {
		isLocked: !!node.attributes.lock,
		lockOwner: node.attributes['lock-owner'],
		lockOwnerDisplayName: node.attributes['lock-owner-displayname'],
		lockOwnerType: parseInt(node.attributes['lock-owner-type']),
		lockOwnerEditor: node.attributes['lock-owner-editor'],
		lockTime: parseInt(node.attributes['lock-time']),
	}
}

export const canLock = (node: Node): boolean => {
	const state = getLockStateFromAttributes(node)

	if (!state.isLocked) {
		return true
	}

	return false
}

export const canUnlock = (node: Node): boolean => {
	const state = getLockStateFromAttributes(node)

	if (!state.isLocked) {
		return false
	}

	if (state.lockOwnerType === LockType.User && state.lockOwner === getCurrentUser()?.uid) {
		return true
	}

	if (state.lockOwnerType === LockType.Token && state.lockOwner === getCurrentUser()?.uid) {
		return true
	}

	return false
}

export const generateAvatarSvg = (userId: string) => {
	const avatarUrl = generateUrl('/avatar/{userId}/32', { userId })
	return `<svg width="32" height="32" viewBox="0 0 32 32"
		xmlns="http://www.w3.org/2000/svg" class="sharing-status__avatar">
		<image href="${avatarUrl}" height="32" width="32" />
	</svg>`
}

export const getInfoLabel = (node: Node): string => {
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
