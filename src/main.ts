/**
 * SPDX-FileCopyrightText: Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import {
	FileAction,
	type Node,
	Permission,
	FileType,
	registerFileAction,
} from '@nextcloud/files'
import { getDialogBuilder, DialogSeverity } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import { lockFile, unlockFile } from './api'
import { LockType } from './types'
import {
	canLock, canUnlock,
	getInfoLabel,
	getLockStateFromAttributes,
} from './helper'
import { getCurrentUser } from '@nextcloud/auth'

import '@nextcloud/dialogs/style.css'
import './styles.css'

import LockSvg from '@mdi/svg/svg/lock.svg?raw'
import LockOpenSvg from '@mdi/svg/svg/lock-open-variant.svg?raw'
import LockEditSvg from '@mdi/svg/svg/pencil-lock.svg?raw'
import LockMonitorSvg from '@mdi/svg/svg/monitor-lock.svg?raw'
import LockAccountSvg from '@mdi/svg/svg/account-lock.svg?raw'

const switchLock = async (node: Node) => {
	try {
		const state = getLockStateFromAttributes(node)
		if (!state.isLocked) {
			const data = await lockFile(node)
			Vue.set(node.attributes, 'lock', '1')
			Vue.set(node.attributes, 'lock-owner', data.userId)
			Vue.set(node.attributes, 'lock-owner-displayname', data.displayName)
			Vue.set(node.attributes, 'lock-owner-type', data.type)
			Vue.set(node.attributes, 'lock-time', data.creation)
		} else {
			await unlockFile(node)
			Vue.set(node.attributes, 'lock', '')
			Vue.set(node.attributes, 'lock-owner', '')
			Vue.set(node.attributes, 'lock-owner-displayname', '')
			Vue.set(node.attributes, 'lock-owner-type', '')
			Vue.set(node.attributes, 'lock-time', '')
		}
		emit('files:node:updated', node)
		return true
	} catch (e) {
		console.error('Failed to switch lock', e)
		return false
	}
}

const inlineAction = new FileAction({
	id: 'lock_inline',
	title: (nodes: Node[]) => nodes.length === 1 ? getInfoLabel(nodes[0]) : '',
	inline: () => true,
	displayName: () => '',
	exec: async () => null,
	order: -10,

	iconSvgInline(nodes: Node[]) {
		const node = nodes[0]

		const state = getLockStateFromAttributes(node)

		if (!state.isLocked) {
			return ''
		}

		if (state.lockOwnerType === LockType.Token) {
			return LockMonitorSvg
		}

		if (state.lockOwnerType === LockType.App) {
			return LockEditSvg
		}

		if (state.lockOwner !== getCurrentUser()?.uid) {
			return LockAccountSvg
		}

		return LockSvg
	},

	enabled(nodes: Node[]) {
		// Only works on single node
		if (nodes.length !== 1) {
			return false
		}

		// FIXME: Currently enabled is not re-evaluated when emitting an updated node object through files:node:updated
		// Therefor we need to also have a unlocked state as the inline action is then always rendered
		// We currently hide them for the grid view via css
		return true

		const node = nodes[0]
		const state = getLockStateFromAttributes(node)

		return state.isLocked
	},
})

const menuAction = new FileAction({
	id: 'lock',
	title: (nodes: Node[]) => getInfoLabel(nodes[0]),
	order: 25,

	iconSvgInline(nodes: Node[]) {
		const node = nodes[0]
		const state = getLockStateFromAttributes(node)
		return state.isLocked ? LockOpenSvg : LockSvg
	},

	displayName(files) {
		if (files.length !== 1) {
			return ''
		}
		const node = files[0]
		return getLockStateFromAttributes(node).isLocked ? t('files_lock', 'Unlock file') : t('files_lock', 'Lock file')
	},

	enabled(nodes: Node[]) {
		// Only works on single node
		if (nodes.length !== 1) {
			return false
		}

		const canToggleLock = canLock(nodes[0]) || canUnlock(nodes[0])
		const isLocked = getLockStateFromAttributes(nodes[0]).isLocked
		const isUpdatable = (nodes[0].permissions & Permission.UPDATE) !== 0

		return nodes[0].type === FileType.File && canToggleLock && (isUpdatable || isLocked)
	},

	async exec(node: Node) {
		const lock = getLockStateFromAttributes(node)

		if (lock?.lockOwnerType === LockType.Token) {
			const dialog = getDialogBuilder('Unlock file manually')
				.setText(t('files_lock', 'This file has been locked automatically by a client. Removing the lock may lead to a conflict saving the file.'))
				.setSeverity(DialogSeverity.Warning)
				.addButton({
					label: t('files_lock', 'Keep lock'),
					callback: () => {
						dialog.hide()
					},
				})
				.addButton({
					label: t('files_lock', 'Force unlock'),
					callback: () => {
						switchLock(node)
					},
					type: 'warning',
				})
				.build()
			dialog.show()
			return
		}

		return await switchLock(node)
	},

})

registerFileAction(inlineAction)
registerFileAction(menuAction)
