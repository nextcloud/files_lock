<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Temporary files lock

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/files_lock)](https://api.reuse.software/info/github.com/nextcloud/files_lock)

![](screenshots/0.7.0.png)

**Files Lock** allows your users to temporary lock a file to avoid other users' edits.  The app also supports automatic locking of files when they are opened in collaborative editing like Nextcloud Text or Nextcloud Office and automatic locking by the desktop client.

Since some locks may become stale by design (e.g. when a user forgets to unlock or a desktop client is left offline and no automatic unlock is happening), the owner of a file can always unlock any existing locks. In addition admins can unlock any file using the `occ files:lock -u <fileId>` command.

By default, files locked using this app will be locked indefinitely.

### Settings

Administrators can change the time of the maximum lock time in minutes (30) using the command:

`./occ config:app:set --value '30' files_lock lock_timeout`

Locks have no expiry by default.


### More commands

Administrators can also lock files using the `./occ` command:

`./occ files:lock <fileId> [<lockOwner>] [--status] [--unlock]`

![](screenshots/cli.png)

## API

Locks are separated into three different types:
- **0 User owned manual lock:**
  This lock type is initiated by a user manually through the WebUI or Clients and will limit editing capabilities on the file to the lock owning user.
- **1 App owned lock:**
  This lock type is created by collaborative apps like Text or Office to avoid outside changes through WevDAV or other apps.
- **2 Token owned lock:** This lock type will bind the ownership to the provided lock token. Any request that aims to modify the file will be required to sent the token, the user itself is not able to write to files without the token. This will allow to limit the locking to an individual client.
  - This is mostly used for automatic client locking, e.g. when a file is opened in a client or with WebDAV clients that support native WebDAV locking. The lock token can be skipped on follow up requests using the OCS API or the `X-User-Lock` header for WebDAV requests, but in that case the server will not be able to validate the lock ownership when unlocking the file from the client.

### Capability

If locking is available the app will expose itself through the capabilties endpoint under the files key:

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

### WebDAV: Fetching lock details

WebDAV returns the following additional properties if requests through a `PROPFIND`:

- `{http://nextcloud.org/ns}lock`: `true` if the file is locked, otherwise `false`
- `{http://nextcloud.org/ns}lock-owner-type`: User id of the lock owner
  - `0` represents a manual lock by a user
  - `1` represents a collaboratively locked file, e.g. when being edited through Office or Text
  - `2` represents a WebDAV lock identified by a lock token, which will have the other properties set as if it was type 0
- `{http://nextcloud.org/ns}lock-owner`: User id of the lock owner
- `{http://nextcloud.org/ns}lock-owner-displayname`: Display name of the lock owner
- `{http://nextcloud.org/ns}lock-owner-editor`: App id of an app owned lock to allow clients to suggest joining the collaborative editing session through the web or direct editing
- `{http://nextcloud.org/ns}lock-time`: Timestamp of the log creation time
- `{http://nextcloud.org/ns}lock-timeout`: TTL of the lock in seconds staring from the creation time. a value of 0 means the timeout is infinite. client implementations should properly handle this specific value
- `{http://nextcloud.org/ns}lock-token`: Unique lock token (to be preserved on the client side while holding the lock to sent once full webdav locking is implemented)

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
	</D:prop>
</D:propfind>'
```


### WebDAV: Manually lock a file

```
curl -X LOCK \
  --url http://admin:admin@nextcloud.dev.local/remote.php/dav/files/admin/myfile.odt \
  --header 'X-User-Lock: 1'
```

#### Headers

- `X-User-Lock`: Indicate that locking shall ignore token requirements that the WebDAV standard required.
- `X-User-Lock-Type`: The type of the lock (see description above)

#### Response

The response will give back the updated properties after obtaining the lock with a `200 Success` status code or the existing lock details in case of a `423 Locked` status.

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

- 423 Unable to unlock, if the lock is owned by another user




### WebDAV: Manually unlock a file

```
curl -X UNLOCK \
  --url http://admin:admin@nextcloud.dev.local/remote.php/dav/files/admin/myfile.odt \
  --header 'X-User-Lock: 1'
```

#### Headers

- `X-User-Lock`: Indicate that locking shall ignore token requirements that the WebDAV standard required.
- `X-User-Lock-Type`: The type of the lock (see description above)

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

- 412 Unable to unlock because the file is not locked
- 423 Unable to unlock, if the lock is owned by another user

### OCS: Locking a file

`PUT /apps/files_lock/lock/{fileId}`

```bash
curl -X PUT 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'`
```

#### Parameters

- `lockType` (optional): The type of the lock. Defaults to `0` (user owned manual lock). Possible values are:
  - `0`: User owned manual lock
  - `1`: App owned lock
  - `2`: Token owned lock

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
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>failure</status>
  <statuscode>500</statuscode>
  <message/>
 </meta>
 <data>
  <status>-1</status>
  <exception>OCA\FilesLock\Exceptions\AlreadyLockedException</exception>
  <message>File is already locked by admin</message>
 </data>
</ocs>
```


### OCS: Unlocking a file

`DELETE /apps/files_lock/lock/{fileId}`

```bash
curl -X DELETE 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'
```

#### Parameters

- `lockType` (optional): The type of the lock. Defaults to `0` (user owned manual lock). Possible values are:
  - `0`: User owned manual lock
  - `1`: App owned lock
  - `2`: Token owned lock

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
```
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>failure</status>
  <statuscode>500</statuscode>
  <message/>
 </meta>
 <data>
  <status>-1</status>
  <exception>OCA\FilesLock\Exceptions\LockNotFoundException</exception>
  <message></message>
 </data>
</ocs>
```
