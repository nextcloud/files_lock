<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# AGENTS.md

This file provides guidance to AI coding agents working in this repository.

**Collaborative File Locking** (`files_lock`) lets users, apps, and clients temporarily lock files to prevent conflicting writes. `README.md` is the source of truth for lock types, access paths (Web UI, OCS, native WebDAV, `X-User-Lock`, CLI), WebDAV properties, capabilities, and API responses. Keep it in sync with any behavior change.

## Commands

```bash
composer install        # PHP deps, also installs vendor-bin/psalm and vendor-bin/rector
npm ci                  # JS deps
npm run dev             # Development build (npm run watch to rebuild on change)
npm run build           # Production build

composer cs:fix         # PHP code style (cs:check to verify)
composer psalm          # Static analysis, baseline in tests/psalm-baseline.xml
composer lint           # PHP syntax check
npm run lint            # ESLint (lint:fix to fix)
npm run stylelint       # Stylelint (stylelint:fix to fix)

composer test:unit      # PHPUnit: tests/Unit and tests/Feature
npx playwright test     # Playwright E2E
```

Single tests:

```bash
composer test:unit -- tests/Feature/LockFeatureTest.php
composer test:unit -- --filter testMethodName
npx playwright test playwright/e2e/sharing.spec.ts
```

Environment constraints:

- **PHPUnit needs a real Nextcloud instance.** `tests/bootstrap.php` requires `../../../lib/base.php`, so the repo must live at `<server-root>/<apps-dir>/files_lock` (e.g. `apps/` or `apps-extra/`) in an installed server with the app enabled. Feature tests use the real database, filesystem, and users; `LockTestCase` creates dummy users, swaps `ITimeFactory` for `ControllableTimeFactory`, and empties the `files_lock` table around each test.
- **Playwright starts its own server** in Docker on port 8089 via `@nextcloud/e2e-test-server`, using the `stable<max-version>` branch from `appinfo/info.xml`. Docker must be available.
- CI also runs the litmus WebDAV suite (`.github/workflows/litmus.yml`) and PHPUnit on SQLite, MySQL, MariaDB, PostgreSQL, and Oracle.

## Architecture

### Backend (`lib/`)

`LockService` holds all lock logic: creating, extending, and removing locks, the `canLock()`/`canUnlock()` authorization rules, expiry, the per-request lock cache, and ETag propagation. `LocksRequest` is a plain query-builder DB layer (no `QBMapper`/`Entity`) that maps rows to the `FileLock` model.

Every access path goes through `LockService`:

| Entry point | Path |
|---|---|
| Other apps (Text, Office, …) | `OCP\Files\Lock\ILockManager` → `LockProvider` (registered lazily in `Application::boot()`) |
| OCS `PUT`/`DELETE /lock/{fileId}` | `LockController`, which overrides the OCS responders to return lock data with `423`/`412` |
| WebDAV `LOCK`/`UNLOCK` with `X-User-Lock` | `DAV/LockPlugin` (extends Sabre's lock plugin, replaces the server's `FakeLockerPlugin`) |
| Native WebDAV token locks | `DAV/LockBackend`, created by `LockPlugin` |
| PROPFIND `nc:lock*` properties | `LockPlugin::customProperties()` |
| CLI | `Command/Lock` (`occ files:lock`) |
| Expiry | `Cron/Unlock` plus lazy cleanup when locks are read |

Enforcement is separate from creation: `BeforeFileSystemSetupListener` wraps every storage in `Storage/LockWrapper`, which throws `ManuallyLockedException` on writes, deletes, and renames of locked files. App-owned locks are only writable inside a matching `ILockManager::runInScope()` context. Locks on federated/remote DAV storages are read through `LockService::getRemoteLockFromDav()`.

The only config key is `lock_timeout` (minutes, `-1` = never), declared in `ConfigLexicon`. `FileLock` stores timeouts in seconds.

### Frontend (`src/`)

A small TypeScript bundle that plugs into the Files app. There is no Vue app and no store: lock state comes from the `nc:lock*` DAV properties on each file node (`src/init.ts` registers them, `src/helper.ts` reads them from `node.attributes`). `src/main.ts` registers the file actions. After a lock change it updates the node attributes and emits `files:node:updated`.

Vite (`@nextcloud/vite-config`) builds the two entries `init` and `main` into `js/files_lock-init.mjs`, `js/files_lock-main.mjs`, and `css/files_lock-main.css`. `Listeners/LoadAdditionalScripts` loads them on the Files page.

## Conventions

- **Compiled assets:** `js/` and `css/` are committed, but never in a pull request. `node-dist-unchanged.yml` fails any PR that touches them. After a merge to `main` or `stable3[4-9]`, `update-node-dist.yml` rebuilds them and commits `build(assets): recompile assets`. Build locally only to test.
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (e.g. `fix(dav): …`). The human contributor signs off with `git commit -s`, and the `Assisted-by` trailer is required (see the policy below). PRs target `main`. Backports use `/backport to stableXX` in a PR comment.
- **REUSE/SPDX:** every file needs an SPDX header, `AGPL-3.0-or-later` with `SPDX-FileCopyrightText: <year> Nextcloud GmbH and Nextcloud contributors`. Match the comment style of the file type (HTML comment for Markdown). Files that can't carry a header get an annotation in `REUSE.toml`.
- **Translations:** `l10n/` is synced from Transifex. Never edit it by hand.

## Nextcloud Contribution Policy

All contributions generated or assisted by this agent must fully comply with:

- **[AI Contribution Policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md)** - the primary reference for AI-specific rules, covering disclosure, author accountability, communication, security, licensing, code quality, and autonomous agent behavior.
- **[Contribution Guidelines](https://github.com/nextcloud/.github/blob/master/CONTRIBUTING.md)** - covering testing requirements, the Developer Certificate of Origin (DCO), license headers, and translations. These apply in full to all contributions regardless of how they were produced.

### What this agent must always do

- Add an `Assisted-by: AGENT_NAME:MODEL_VERSION` git trailer to every commit containing AI-assisted content.
- Ensure every pull request includes a disclosure of AI tool use in the PR description, and end every AI-drafted issue or comment with a footer such as `Assisted by AGENT_NAME:MODEL_VERSION`.
- Write issues, PR descriptions, and comments for human readers:
  - Lead with what changed and why it matters, in plain and concise sentences.
  - Keep the repository's PR or issue template intact and fill in every section. For a bug report, also state whether the bug was validated and reproduced on a live Nextcloud instance.
  - Put information meant only for agents (plans, prompts, tool logs) into a collapsed `<details>` block.
- Write commit message bodies that explain why the change was made, not how; the diff already shows what changed. Keep the repository's commit conventions and the required trailers.
- Produce focused, scoped pull requests that address exactly one concern. Do not touch unrelated files or introduce incidental refactors.
- Verify all dependencies against actual package registries before suggesting them. Do not use hallucinated or unverified package names.
- Write code comments that document the code, never the process that produced it:
  - Comments describe what the code does - method signatures, behavior, and constraints the code itself cannot express (e.g. a non-obvious invariant or workaround).
  - Never add comments that document progress, decisions, or changes (e.g. "changed X to Y", "as requested", "this fixes ...", "previously this did ..."). That belongs in the commit message or PR discussion; in the code it goes stale and becomes misleading.
  - Do not narrate self-explanatory code. If the code is readable without a comment, omit the comment.
  - Keep comments brief - short and simple, matching the comment density of the surrounding code.
- Reuse existing helper functions and utilities instead of re-implementing their logic inline. When fixing a flawed pattern, fix every occurrence of it across the changed code, not only the instance that was pointed out.
- Run permission and access-control checks before the operation they guard, never after it and never only in the UI layer.
- When adding or changing user-facing functionality, wire it up in every context where the affected component is used - the default authenticated view, public share pages, and embedded contexts such as the Smart Picker and reference widgets. When emitting new events, verify that every consumer of the component subscribes to and handles them.
- Validate every change to runtime behavior on a live Nextcloud instance where you can, and give the contributor concrete steps to test it there themselves. Your testing supports theirs; it does not replace it.
- Explicitly inform the contributor when any action they are about to take, or have taken, would violate the AI Contribution Policy or the Contribution Guidelines. Do not silently proceed. State which rule is at risk and what the contributor should do instead.
- Warn the contributor if a pull request is growing too large. A PR approaching several thousand lines of changed code is a signal that it should be split into smaller, focused PRs. Suggest a logical split before the PR is opened, not after.
- Recommend opening a ticket for discussion before starting implementation whenever a feature or change is sufficiently complex - for example when it touches multiple subsystems, requires architectural decisions, or the right approach is not yet clear. A ticket allows maintainers and the contributor to align on direction before code is written, avoiding wasted effort on a PR that may be rejected or require fundamental rework.

### What this agent must never do, and what to do instead

- Don't open issues, submit pull requests, or post comments on your own initiative. Instead, post only what the contributor asked for, on their behalf, and tell them what you posted.
- Don't write texts for machines: no file-by-file restatement of the diff, no filler, no raw tool output. Instead, write short, skimmable text for a human reviewer that explains what changed and why.
- Don't add `Signed-off-by` tags to commits. Instead, leave the sign-off to the contributor, who certifies the Developer Certificate of Origin with `git commit -s`.
- Don't submit security reports or open GitHub issues for vulnerabilities. Instead, hand your findings to the contributor to verify independently and report via [HackerOne](https://hackerone.com/nextcloud).
- Don't fully automate the resolution of issues labeled [`good first issue`](https://github.com/issues?q=org%3Anextcloud+label%3A%22good+first+issue%22) or similar beginner-friendly labels. Instead, explain the code and guide the contributor so they can solve the issue themselves.
- Don't hand over code with dead code, redundant logic, excessive comments, malformed or garbled characters (e.g. `�` replacement characters), or unrelated changes. Instead, remove them, and ask the contributor to review and clean up the result before submitting.
