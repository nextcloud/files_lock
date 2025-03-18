/**
 * SPDX-FileCopyrightText: 2024 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect } from '@playwright/test'
import { test } from '../support/fixtures/random-user'

test.beforeEach(async ({ page }) => {
	await page.goto('apps/files')
	await page.waitForURL(/apps\/files/)
})

test('can manually lock a file', async ({ page, user }) => {
	await page.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Lock file' }).click()
	const lockIndicator = page.getByRole('button', { name: 'Manually locked by ' + user.userId })
	await expect(lockIndicator).toBeVisible()
	await page.getByRole('button', { name: 'Actions' }).click()
	const lockInfo = page.getByRole('menuitem', { name: 'Manually locked by ' + user.userId })
	await expect(lockInfo).toBeVisible()
	await page.getByRole('menuitem', { name: 'Unlock' }).click()
	await expect(lockIndicator).not.toBeVisible()
})

test('it shows the lock menu entry in grid view', async ({ page, user }) => {
	await page.getByRole('button', { name: 'Switch to grid view' }).click()
	await page.getByRole('button', { name: 'Actions' }).click()
	const lockButton = page.getByRole('menuitem', { name: 'Lock file' })
	await expect(lockButton).toBeVisible()
	await lockButton.click()
	await expect(lockButton).not.toBeVisible()

	await page.getByRole('button', { name: 'Actions' }).click()

	const lockIndicator = page.getByRole('menuitem', { name: 'Manually locked by ' + user.userId })
	await expect(lockIndicator).toBeVisible()

	// Ensure that the inline lock indicator is not visible
	const inlineLockIndicator = page.locator('.files-list__row-action-lock_inline')
	await expect(inlineLockIndicator).not.toBeVisible()

	const unlockButton = page.getByRole('menuitem', { name: 'Unlock' })
	await expect(unlockButton).toBeVisible()
	await unlockButton.click()
	await expect(unlockButton).not.toBeVisible()
})
