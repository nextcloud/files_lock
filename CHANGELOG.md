# Changelog

## 27.0.4

### Added

- feat: Add API parameters to specify the lock type @backportbot[bot] [#220](https://github.com/nextcloud/files_lock/pull/220)

## 27.0.3

### Fixed

- fix: Preserve lock data in file info model @juliushaertl [#201](https://github.com/nextcloud/files_lock/pull/201)
- perf: Avoid re-query of already fetched lock info [#200](https://github.com/nextcloud/files_lock/pull/200)
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
