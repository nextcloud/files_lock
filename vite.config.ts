/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	init: 'src/init.ts',
	main: 'src/main.ts',
}, {
	config: {
		css: {
			modules: {
				localsConvention: 'camelCase',
			},
		},
	},
})
