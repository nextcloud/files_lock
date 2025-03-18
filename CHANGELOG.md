<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

## 30.0.2

### Fixed

- fix: Hide empty inline menu entry in grid view [#602](https://github.com/nextcloud/files_lock/pull/602)
- fix(timeout): compare creation time to now - timeout [#531](https://github.com/nextcloud/files_lock/pull/531)

### Other

- Add playwright skeleton for e2e tests @juliusknorr [#559](https://github.com/nextcloud/files_lock/pull/559)

## 30.0.1

### Added

- fix: Use icons instead of avatar for locking indication [#472](https://github.com/nextcloud/files_lock/pull/472)

### Fixed

- Allow force unlock of automated client locks [#441](https://github.com/nextcloud/files_lock/pull/441)
- fix: Use proper user when unlocking an app locked file with occ [#465](https://github.com/nextcloud/files_lock/pull/465)

### Dependencies

- Prepare composer files for Nextcloud 30 @susnux [#338](https://github.com/nextcloud/files_lock/pull/338)

### Other

- chore(CI): Adjust testing matrix for Nextcloud 30 on stable30 @nickvergessen [#341](https://github.com/nextcloud/files_lock/pull/341)
- fix(ci): litmus test with current upload-artifact [#371](https://github.com/nextcloud/files_lock/pull/371)
- chore(dev-deps): Bump nextcloud/ocp package @juliusknorr [#373](https://github.com/nextcloud/files_lock/pull/373)

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
