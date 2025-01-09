<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

## 31.0.0

### Added

- fix: Use icons instead of avatar for locking indication @juliusknorr [#471](https://github.com/nextcloud/files_lock/pull/471)

### Fixed

- Allow force unlock of automated client locks @juliusknorr [#391](https://github.com/nextcloud/files_lock/pull/391)
- fix: Use proper user when unlocking an app locked file with occ @juliusknorr [#452](https://github.com/nextcloud/files_lock/pull/452)

### Other

- feat(deps): Add Nextcloud 31 support on main @nickvergessen [#342](https://github.com/nextcloud/files_lock/pull/342)
- test: Add test for extending locks @susnux [#340](https://github.com/nextcloud/files_lock/pull/340)
- chore: Fix indention of composer.json @susnux [#339](https://github.com/nextcloud/files_lock/pull/339)
- Migrate reuse to toml format @AndyScherzinger [#353](https://github.com/nextcloud/files_lock/pull/353)
- fix(api): return types for Storage\LockWrapper @max-nextcloud [#368](https://github.com/nextcloud/files_lock/pull/368)
- fix(ci): litmus test with current upload-artifact @max-nextcloud [#367](https://github.com/nextcloud/files_lock/pull/367)
- chore(dev-deps): Bump nextcloud/ocp package @juliusknorr [#372](https://github.com/nextcloud/files_lock/pull/372)
- Revert "chore(deps): Bump vue from 2.7.15 to 3.5.13" @juliusknorr [#428](https://github.com/nextcloud/files_lock/pull/428)
- docs: Extend basic app explanation @juliusknorr [#468](https://github.com/nextcloud/files_lock/pull/468)

## 30.0.0

### Fixed

- fix: Show lock status for read only files and allow unlocking @juliushaertl [#306](https://github.com/nextcloud/files_lock/pull/306)

### Other

- refactor: remove unnecessary assignment @kesselb [#301](https://github.com/nextcloud/files_lock/pull/301)
- Fix some deprecation warnings @kesselb [#302](https://github.com/nextcloud/files_lock/pull/302)
- perf(boot): Initialize storage wrapper and lock manager more lazy @juliushaertl [#297](https://github.com/nextcloud/files_lock/pull/297)
- Add SPDX header @AndyScherzinger [#326](https://github.com/nextcloud/files_lock/pull/326)

## 29.0.0

### Added

- feat: Add API parameters to specify the lock type @juliushaertl [#199](https://github.com/nextcloud/files_lock/pull/199)
- feat: translate controller status messages @skjnldsv [#231](https://github.com/nextcloud/files_lock/pull/231)

### Fixed

- fix: Use lock owner display name on error response @juliushaertl [#251](https://github.com/nextcloud/files_lock/pull/251)
- fix: Allow to unlock based on the current lock not the requested one to allow lock owners to unlock in any case @juliushaertl [#252](https://github.com/nextcloud/files_lock/pull/252)
- Return proper lock type in webdav response @juliushaertl [#253](https://github.com/nextcloud/files_lock/pull/253)
- fix/error display name @juliushaertl [#278](https://github.com/nextcloud/files_lock/pull/278)
- fix: Only update lock timeout when it is not infinite @juliushaertl [#288](https://github.com/nextcloud/files_lock/pull/288)

### Other

- chore: upgrade phpunit workflows @skjnldsv [#232](https://github.com/nextcloud/files_lock/pull/232)
- ci(litmus): Bump php version to 8.1 @juliushaertl [#289](https://github.com/nextcloud/files_lock/pull/289)

## 29.0.0-beta.2

### Fixed

- fix: Use lock owner display name on error response @juliushaertl [#251](https://github.com/nextcloud/files_lock/pull/251)
- Return proper lock type in webdav response @juliushaertl [#253](https://github.com/nextcloud/files_lock/pull/253)
- fix: Allow to unlock based on the current lock not the requested one to allow lock owners to unlock in any case @juliushaertl [#252](https://github.com/nextcloud/files_lock/pull/252)

## 29.0.0-beta.1

### Added

- Compatibility with Nextcloud 29
- feat: Add API parameters to specify the lock type @juliushaertl [#199](https://github.com/nextcloud/files_lock/pull/199)
- feat: translate controller status messages @skjnldsv [#231](https://github.com/nextcloud/files_lock/pull/231)

### Other

- chore: upgrade phpunit workflows @skjnldsv [#232](https://github.com/nextcloud/files_lock/pull/232)

## 28.0.1

### Fixed

- perf: Avoid re-query of already fetched lock info @juliushaertl [#196](https://github.com/nextcloud/files_lock/pull/196)

## 28.0.0

### Added

- Nextcloud 28 compatibility
  - Migrate to new files API @juliushaertl [#177](https://github.com/nextcloud/files_lock/pull/177)
  - Use different icon to indicate automatic collaborative app lock (e.g. with Text or Nextcloud Office)

### Fixed

- lock-timeout can have a special value of 0 for infinite timeout @mgallien [#175](https://github.com/nextcloud/files_lock/pull/175)

## 27.0.2

### Fixed

- fix: ignore file-owner condition in groupfolders and external storage [#171]
- fix: response's data is FileLock [#173]

## 27.0.1

### Added

- feat(dav): Expose etag property after user LOCK/UNLOCK @juliushaertl [#163](https://github.com/nextcloud/files_lock/pull/163)

### Fixed

- fix: Do not load unused event dispatcher @juliushaertl [#160](https://github.com/nextcloud/files_lock/pull/160)
- Dependency updates


## 27.0.0

### Added

- Nextcloud 27 compatibility

### Fixed

- Allow the file owner to always unlock [#140](https://github.com/nextcloud/files_lock/pull/140)
- Ingore error if unlocking an already unlocked file [#139](https://github.com/nextcloud/files_lock/pull/139)
- Show proper error message when trying to unlock as someone else [#133](https://github.com/nextcloud/files_lock/pull/133)
- Avoid getting the user folder for non-files dav paths [#124](https://github.com/nextcloud/files_lock/pull/124)

### Changed

- Update translations
- Upgrade dependencies

## 26.0.0

### Added

- Nextcloud 26 compatibility

### Fixed

- fix(webdav): allow the lock owner to overrule the webdav lock @juliushaertl [#109](https://github.com/nextcloud/files_lock/pull/109)
- Use user display name cache @juliushaertl [#87](https://github.com/nextcloud/files_lock/pull/87)
- Improve locks PROPFIND @CarlSchwan [#86](https://github.com/nextcloud/files_lock/pull/86)
- Ensure that we stay backward compatible when getting the display name @juliushaertl [#94](https://github.com/nextcloud/files_lock/pull/94)
- Fix type of param for creation column @tcitworld [#99](https://github.com/nextcloud/files_lock/pull/99)
- Lock.php: fix creation date fetch @meonkeys [#118](https://github.com/nextcloud/files_lock/pull/118)

## 24.0.1

### Fixed

- Avoid checking viewer id if not relevant @juliushaertl [#82](https://github.com/nextcloud/files_lock/pull/82)
- ignore exception on empty session @ArtificialOwl [#75](https://github.com/nextcloud/files_lock/pull/75)
- Fix types of ExtendedQueryBuilder @CarlSchwan [#77](https://github.com/nextcloud/files_lock/pull/77)

### Dependencies

- Bump psalm/phar from 4.22.0 to 4.24.0 @dependabot[bot] [#79](https://github.com/nextcloud/files_lock/pull/79)
- Bump phpunit/phpunit from 9.5.20 to 9.5.21 @dependabot[bot] [#78](https://github.com/nextcloud/files_lock/pull/78)

### Other
- Add psalm and php-cs-fixer @juliushaertl [#65](https://github.com/nextcloud/files_lock/pull/65)

## 24.0.0

- Nextcloud 24 compatibility
- Collaborative locking support
- Support for client integrations
- First implementation of WebDAV locking currently limited to single files
- Infinite lock timeout by default

## 20.1.0

- compat nc23


## 20.0.0

- compat nc20


## 19.0.0

- upgrade of lib


## 0.8.3

- throw ManuallyLockedException with ETA.


## 0.8.2

- compat NC19


## 0.8.1

Beta release, nc18
