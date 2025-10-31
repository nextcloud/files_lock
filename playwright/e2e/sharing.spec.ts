/**
 * SPDX-FileCopyrightText: 2024 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect } from '@playwright/test'
import { test } from '../support/fixtures/sharing-user'

test('Share a file read only that cannot be locked by the recipient', async ({ pageOwner, pageRecipient, owner, recipient, request }) => {
	// Create a test file as owner
	const filename = 'test-share.txt'
	const response = await request.put(`/remote.php/dav/files/${owner.userId}/${filename}`, {
		headers: {
			Authorization: `Basic ${Buffer.from(`${owner.userId}:${owner.password}`).toString('base64')}`,
		},
		data: 'Test file content',
	})
	expect(response.ok()).toBeTruthy()

	// Share the file with recipient
	const shareResponse = await request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Authorization: `Basic ${Buffer.from(`${owner.userId}:${owner.password}`).toString('base64')}`,
		},
		data: {
			path: `/${filename}`,
			shareType: '0', // User share
			shareWith: recipient.userId,
			permissions: '1',
		},
	})
	expect(shareResponse.ok()).toBeTruthy()

	// Check recipient cannot lock
	await pageRecipient.goto('/apps/files')
	await pageRecipient.waitForURL(/apps\/files/)
	const rowRecipient = await pageRecipient.getByRole('row', { name: filename })
	await rowRecipient.getByRole('button', { name: 'Actions' }).click()
	await expect(pageRecipient.getByRole('menuitem', { name: 'Lock file' })).not.toBeVisible()

	// Check owner can still lock
	await pageOwner.goto('/apps/files')
	await pageOwner.waitForURL(/apps\/files/)
	const rowOwner = await pageOwner.getByRole('row', { name: filename })
	await rowOwner.getByRole('button', { name: 'Actions' }).click()
	await expect(pageOwner.getByRole('menuitem', { name: 'Lock file' })).toBeVisible()
})
