<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Collaborative File Locking

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/files_lock)](https://api.reuse.software/info/github.com/nextcloud/files_lock)

The **Collaborative File Locking** (`files_lock`) app allows users, apps, and clients to temporarily lock files to prevent conflicting writes by other users, apps, or clients. The app supports both manual and automatic locking. For example, automatic locking can facilitate collaborative editing with Nextcloud Text or Nextcloud Office.

![](screenshots/0.7.0.png)

## Overview

Locks use an ownership and authorization model (**lock type**) and are created or removed through an **access path**. The lock type determines the lock owner and the rules used to validate release of the lock. The access path determines how a request reaches the lock service and which caller identity or credentials are available for those checks.

### Lock types

- **0 — User-owned lock**

  This lock is associated with a user account. It is typically used for locks initiated manually through the Web UI or a client. Clients and applications may use the lock state to restrict or prevent other users from writing to or modifying the file. The file owner can override the lock through access paths that support user override.

- **1 — App-owned lock**

  This lock is associated with an application identifier. It is commonly used by collaborative editors, such as Text or Office, to prevent conflicting writes through WebDAV or other applications while a collaborative editing session is active.

- **2 — Token-owned lock**

  This lock type supports token-based ownership for native WebDAV locking. A native WebDAV lock is assigned a WebDAV lock token, which must be provided to unlock the lock in a subsequent `UNLOCK` request.

  Token-owned locks are primarily intended for automatic client locking, for example when a file is opened by a desktop client or another WebDAV client that supports native WebDAV locking. The lock type alone does not imply that a lock was created automatically.

  A token-owned lock records both the token and the user who created it. Modifying the file requires the token and the request must come from that user (RFC 4918, section 6.4); a request from another user is refused even if it carries the token, and a write through the PHP file system API requires the token to be presented through the lock scope of `ILockManager`. The lock is released by a native `UNLOCK` that presents the token and comes from someone who may write the file, by the user recorded as its owner, by the file owner, or by an administrator. Possession of the token alone is not enough: the token is a publicly readable value (RFC 4918, section 6.5), so privileges are enforced through the normal permission mechanism as section 6.4 requires.

### Enforcement

Locks are enforced by file identity on every storage a file can be reached through: the owner's home storage, shares, group and team folders, external storages and custom mounts. A user lock blocks every other user, an app lock blocks everything outside the owning app's lock scope, and a token lock blocks everything that does not present the token as described above. Deleting or moving a folder is refused while it contains a file locked by someone else, so a locked file can neither be removed nor relocated through its parent.

### Access paths

An access path is the mechanism used to create or remove a lock. Locks can be created and removed through the Web UI, the OCS API, native WebDAV `LOCK`/`UNLOCK`, or the `X-User-Lock` WebDAV extension. These are access paths to the same lock implementation; they are not separate lock types.

- **Web UI**

  The Web UI creates and removes user-owned locks for the currently authenticated user.

- **OCS API**

  The OCS API allows authenticated users and clients to create and remove locks. When creating a lock, the optional `lockType` parameter selects the stored lock type.

  OCS requests are authenticated using the current user session; they do not supply or validate a native WebDAV lock token. In particular, using `lockType=2` through OCS creates a token-owned lock type, but does not make subsequent OCS requests token-authenticated.

- **Native WebDAV `LOCK`/`UNLOCK`**

  Native WebDAV locking is the token-based access path for Type 2 locks.

  A `LOCK` request creates a token-owned lock for the authenticated user and associates it with a WebDAV lock token. A subsequent `UNLOCK` request must provide that token and must come from a user who may write the file. A `Timeout` header sets the lifetime of the lock (`Second-N` or `Infinite`); without it the configured `lock_timeout` applies. A `LOCK` refresh (no body, the token in the `If` header) moves the expiry. The `<D:owner>` element of the request is not stored; the lock reports the display name of the user who created it. A `LOCK` on a file that already carries another lock answers `423 Locked`, an `UNLOCK` with an unknown token answers `409 Conflict`, and an `UNLOCK` the caller is not allowed to perform answers `403 Forbidden`.

- **WebDAV with `X-User-Lock`**

  `X-User-Lock` is a Nextcloud-specific WebDAV extension that handles `LOCK` and `UNLOCK` requests through the Files Lock API rather than the native WebDAV lock-token flow. The optional `X-User-Lock-Type` header selects one of the existing lock types; if omitted, it defaults to type `0`.

  Requests using `X-User-Lock` are authorized using the authenticated user session. They are not token-authenticated WebDAV follow-up requests, even when `X-User-Lock-Type: 2` is used.

- **CLI**

  Locks can also be created, inspected, and removed via the command line. For example, `occ files:lock <fileId> [<lockOwner>]` creates a user-owned (Type 0) lock associated with the specified `lockOwner`. The CLI does not expose an option to create Type 1 app-owned or Type 2 token-owned locks.

  An existing lock of any type can be removed using the CLI as well, including token-owned locks.

## Commands

![](screenshots/cli.png)

### Unlocking

The file owner can override existing locks on files stored in their own home storage (directly or through a share of it); on group folders, external storages and other mounts every user is reported as owner, so no owner override applies there. The user recorded as the owner of a lock can always release it, whatever the lock type. Administrators can force-unlock files using `occ files:lock --unlock <fileId>`. In the case of automatic locks, apps and client applications are typically responsible for removing no longer needed locks.

By default, files are locked indefinitely.

A forced unlock removes the lock of any type and does not require the user who created it to still have access to the file:

`occ files:lock --unlock <fileId>`

This command can be helpful when locks become stale. For example, when a user forgets to remove a manually created lock or a desktop client remains offline and automatic unlocking is not configured.

Deleting a file removes its lock. A file restored from the trash bin starts unlocked.

### Lock timeout

Locks have no expiry by default (`lock_timeout = -1`).

Administrators can change the time of the maximum lock time in minutes (30) using the command:

`occ config:app:set --value '30' files_lock lock_timeout`

Set `lock_timeout` to `-1` to disable expiration.

Every lock carries an absolute expiry. Locking a file again (OCS, `X-User-Lock`, or a native WebDAV refresh) moves the expiry to the configured timeout, or to the timeout requested through the WebDAV `Timeout` header, counted from the moment of the refresh. Expired locks stop blocking immediately and a background job removes them from the database.

### Locking

Administrators can manually lock files using `occ`:

`occ files:lock <fileId> [<lockOwner>] [--status] [--unlock]`

Only files can be locked through the Web UI, the OCS API, `X-User-Lock` and the command line; a folder ID is refused there. Native WebDAV clients may still lock a collection as RFC 4918 requires; such a lock protects the collection's own name and location, not its members.

## Capabilities API

If locking is available the app will expose itself through the capabilities endpoint under the `files` key:

Make sure to validate that also the key exists in the capabilities response, not just check the value as on older versions the entry might be missing completely.

```
curl http://admin:admin@nextcloud.local/ocs/v1.php/cloud/capabilities\?format\=json \
	-H 'OCS-APIRequest: true' \
	| jq .ocs.data.capabilities.files
{
  ...
  "locking": "1.0",
  "api-feature-lock-type" => true,
  ...
}
```

- `locking`: The version of the locking API
- `api-feature-lock-type`: Feature flag, whether the server supports the `lockType` parameter for the OCS API or `X-User-Lock-Type` header for WebDAV requests.

## WebDAV API

### Fetching lock details

WebDAV returns the following additional properties in response to a `PROPFIND` request:

- `{http://nextcloud.org/ns}lock`: `true` if the file is locked, otherwise `false`
- `{http://nextcloud.org/ns}lock-owner-type`: Lock type
  - `0` represents a user-owned lock
  - `1` represents an app-owned lock, for example while a collaborative editor such as Office or Text is active
  - `2` represents a token-owned lock, typically created through native WebDAV locking
- `{http://nextcloud.org/ns}lock-owner`: User ID of the lock owner for user-owned and token-owned locks. This property is empty for app-owned locks.
- `{http://nextcloud.org/ns}lock-owner-displayname`: Display name of the lock owner
- `{http://nextcloud.org/ns}lock-owner-editor`: App ID for an app-owned lock. Clients can use it to suggest joining the collaborative editing session in the web interface or through direct editing. It is empty for user-owned and token-owned locks on every access path.
- `{http://nextcloud.org/ns}lock-time`: Timestamp at which the lock was created
- `{http://nextcloud.org/ns}lock-timeout`: Lifetime of the lock in seconds counted from `lock-time`; it grows when the lock is refreshed. A value of `0` indicates that the lock does not expire.
- `{http://nextcloud.org/ns}lock-token`: Lock token. Clients using native WebDAV locking must retain it while holding the lock and provide it when unlocking.

```bash
curl -X PROPFIND \
  --url http://admin:admin@nextcloud.dev.local/remote.php/dav/files/admin/myfile.odt \
  --data '<D:propfind xmlns:D="DAV:" xmlns:NC="http://nextcloud.org/ns">
	<D:prop>
		<NC:lock />
		<NC:lock-owner-type />
		<NC:lock-owner />
		<NC:lock-owner-displayname />
		<NC:lock-owner-editor />
		<NC:lock-time />
		<NC:lock-timeout />
		<NC:lock-token />
	</D:prop>
</D:propfind>'
```

### Manually lock a file

```
curl -X LOCK \
  --url http://admin:admin@nextcloud.dev.local/remote.php/dav/files/admin/myfile.odt \
  --header 'X-User-Lock: 1'
```

#### Headers

- `X-User-Lock`: Handle the request through the Files Lock API instead of the native WebDAV lock-token flow.
- `X-User-Lock-Type`: The lock type to use. Defaults to `0` when omitted.

#### Response

The response will give back the updated properties after obtaining the lock with a `200 OK` status code or the existing lock details in case of a `423 Locked` status.

```xml
<?xml version="1.0"?>
<d:prop
	xmlns:d="DAV:"
	xmlns:s="http://sabredav.org/ns"
	xmlns:oc="http://owncloud.org/ns"
	xmlns:nc="http://nextcloud.org/ns">
	<nc:lock>1</nc:lock>
	<nc:lock-owner-type>0</nc:lock-owner-type>
	<nc:lock-owner>user1</nc:lock-owner>
	<nc:lock-owner-displayname>user1</nc:lock-owner-displayname>
	<nc:lock-owner-editor>user1</nc:lock-owner-editor>
	<nc:lock-time>1648046707</nc:lock-time>
</d:prop>
```

#### Error status codes

- 400 Unsupported `X-User-Lock-Type`
- 403 The caller lacks update permission, or the resource is a folder
- 404 The resource does not exist
- 423 Unable to lock because the file is already locked by another owner

### Manually unlock a file

```
curl -X UNLOCK \
  --url http://admin:admin@nextcloud.dev.local/remote.php/dav/files/admin/myfile.odt \
  --header 'X-User-Lock: 1'
```

#### Headers

- `X-User-Lock`: Handle the request through the Files Lock API instead of the native WebDAV lock-token flow.
- `X-User-Lock-Type`: The requested lock type. To release a user-owned or app-owned lock, use the same type that was used to create it. When omitted, it defaults to `0`.

#### Response

```xml
<?xml version="1.0"?>
<d:prop
	xmlns:d="DAV:"
	xmlns:s="http://sabredav.org/ns"
	xmlns:oc="http://owncloud.org/ns"
	xmlns:nc="http://nextcloud.org/ns">
	<nc:lock></nc:lock>
	<nc:lock-owner-type/>
	<nc:lock-owner/>
	<nc:lock-owner-displayname/>
	<nc:lock-owner-editor/>
	<nc:lock-time/>
</d:prop>

```

#### Error status codes

- 400 Unsupported `X-User-Lock-Type`
- 404 The resource does not exist
- 412 Unable to unlock because the file is not locked
- 423 Unable to unlock if the lock is owned by another user; the response carries the existing lock

## OCS API

### Locking a file

`PUT /apps/files_lock/lock/{fileId}`

```bash
curl -X PUT 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'`
```

#### Parameters

- `lockType` (optional): The lock type to create. Defaults to `0` (user-owned). Possible values are:
  - `0`: User-owned lock
  - `1`: App-owned lock
  - `2`: Token-owned lock type. OCS does not accept a native WebDAV lock token.

#### Success
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>ok</status>
  <statuscode>200</statuscode>
  <message>OK</message>
 </meta>
</ocs>
```

#### Failure

The file is already locked by someone else. The response carries the existing lock, without its token:
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>failure</status>
  <statuscode>423</statuscode>
  <message>File is currently locked by admin</message>
 </meta>
 <data>
  <id>12</id>
  <userId>admin</userId>
  <displayName>admin</displayName>
  <fileId>123</fileId>
  <eta>-1</eta>
  <creation>1648046707</creation>
  <expiresAt/>
  <type>0</type>
 </data>
</ocs>
```

#### Status codes

- 200 Lock created or refreshed
- 400 Unsupported `lockType`, invalid file ID, or the ID belongs to a folder
- 403 The caller lacks update permission on the file
- 404 The file does not exist or is not accessible to the caller
- 423 The file is locked by another owner

### Unlocking a file

`DELETE /apps/files_lock/lock/{fileId}`

```bash
curl -X DELETE 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'
```

#### Parameters

- `lockType` (optional): The lock type the caller asserts, matching the value used when locking. The user recorded as the owner of a lock can release it whatever the type, and the file owner can release any lock on a file of their home storage.

#### Success
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>ok</status>
  <statuscode>200</statuscode>
  <message>OK</message>
 </meta>
</ocs>
```

#### Failure

The file is not locked:
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>failure</status>
  <statuscode>412</statuscode>
  <message>File is not locked</message>
 </meta>
 <data/>
</ocs>
```

#### Status codes

- 200 Lock released
- 400 Unsupported `lockType` or invalid file ID
- 404 The file does not exist or is not accessible to the caller
- 412 The file is not locked
- 423 The lock is held by someone else and the caller may not release it; the response carries the existing lock
