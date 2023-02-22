# Changelog

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
