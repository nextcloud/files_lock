### the Files Lock app


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

