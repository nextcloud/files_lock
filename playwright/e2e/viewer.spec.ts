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
	await page.getByRole('menuitem', { name: 'Manually locked by ' + user.userId }).click()
	await expect(lockIndicator).not.toBeVisible()
})