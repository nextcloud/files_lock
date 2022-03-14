# Temporary files lock

![](screenshots/0.7.0.png)

**Files Lock** allows your users to temporary lock a file to avoid other users' edits.  
By default, files locked using this app will be unlocked after 30 minutes.




### Settings

Administrators can change the time of the maximum lock time (30) using the command:

`./occ config:app:set --value '30' files_lock lock_timeout`



### More commands

Administrators can also lock files using the `./occ` command:

`./occ files:lock <fileId> [<lockOwner>] [--status] [--unlock]`

![](screenshots/cli.png)

## API

### Capability

If locking is available the app will expose itself through the capabilties endpoint under the files key:
```
curl http://admin:admin@nextcloud.local/ocs/v1.php/cloud/capabilities\?format\=json -H 'OCS-APIRequest: true' \
	| jq .ocs.data.capabilities.files
{
  ...
  "locking": "1.0",
  ...
}
```

### Fetching lock details

WebDAV returns the following additional properties if requests through a `PROPFIND`:

- `{http://nextcloud.org/ns}lock`: `true` if the file is locked, otherwise `false`
- `{http://nextcloud.org/ns}lock-owner`: User id of the lock owner
- `{http://nextcloud.org/ns}lock-owner-displayname`: Display name of the lock owner
- `{http://nextcloud.org/ns}lock-time`: Timestamp of the log creation time

### Locking a file

`PUT /apps/files_lock/lock/{fileId}`

```bash
curl -X PUT 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'`
```

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


### Unlocking a file

`DELETE /apps/files_lock/lock/{fileId}`

```bash
curl -X DELETE 'http://admin:admin@nextcloud.local/ocs/v2.php/apps/files_lock/lock/123' -H 'OCS-APIREQUEST: true'
```

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
