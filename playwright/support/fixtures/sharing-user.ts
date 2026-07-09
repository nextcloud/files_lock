/**
 * SPDX-FileCopyrightText: 2024 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createRandomUser, login } from '@nextcloud/e2e-test-server/playwright'
import { test as base } from '@playwright/test'

// The User type from e2e-test-server
interface User {
	userId: string
	password: string
	language: string
}

interface SharingUserFixture {
	owner: User
	recipient: User
	pageOwner: Page
	pageRecipient: Page
}

/**
 * This test fixture ensures two new random users are created:
 * - owner: The user who will be sharing files/folders
 * - recipient: The user who will receive the shares
 */
export const test = base.extend<SharingUserFixture>({
	owner: async (_, use) => {
		const user = await createRandomUser()
		await use(user)
	},
	recipient: async (_, use) => {
		const user = await createRandomUser()
		await use(user)
	},
	pageOwner: async ({ browser, baseURL, owner }, use) => {
		const page = await browser.newPage({
			storageState: undefined,
			baseURL,
		})

		await login(page.request, owner)

		await use(page)
		await page.close()
	},
	pageRecipient: async ({ browser, baseURL, recipient }, use) => {
		const page = await browser.newPage({
			storageState: undefined,
			baseURL,
		})

		await login(page.request, recipient)

		await use(page)
		await page.close()
	},
})
