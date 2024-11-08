# CyberPanel Migration Script

This repository provides a set of scripts to automate the migration of websites, email accounts, website data, and vmail directories from a remote server to a local CyberPanel environment. The migration process is orchestrated by a main shell script, `migrate.sh`, which sequentially runs each migration step, ensuring no steps are skipped if an error occurs.

> **Important Note**: Before starting, it is recommended to take a snapshot of both the source and destination servers to ensure data safety.

## Why Use This Script?

This script offers a robust alternative to CyberPanel’s built-in transfer functionality, addressing common issues and improving performance:
1. **Reliability**: CyberPanel’s built-in transfer frequently fails, leaving the migration incomplete or with errors. This script was designed to run smoothly and handle various migration tasks reliably.
2. **Speed**: The script uses `rsync` to transfer data directly without compressing files. This approach is significantly faster than CyberPanel’s method, as it avoids additional disk space requirements on both the remote and local machines, which is essential when space is limited.
3. **Non-Disruptive**: If a user or website already exists on the destination server, the script skips to the next item without attempting to recreate it or overwriting data.

## Prerequisites

1. **Operating System**: Tested on **Ubuntu 22.04**.
2. **rsync in Sudoers**: Ensure `rsync` is added to the sudoers file on the remote server for passwordless execution. You can do this by adding the following line to the remote machine’s sudoers file:
   ```bash
   your_user ALL=(ALL) NOPASSWD: /usr/bin/rsync
   ```
3. **CyberPanel Environment**: This script is designed for environments where CyberPanel is already installed on both source and destination servers.
4. **SSH Key Authorization**: The script uses `ssh-copy-id` to add your SSH key to the remote server’s `authorized_keys`, enabling passwordless SSH connections. To remove the key after migration, follow these steps:
   - SSH into the remote server:
     ```bash
     ssh your_user@remote_ip
     ```
   - Edit the `authorized_keys` file and remove the relevant key:
     ```bash
     nano ~/.ssh/authorized_keys
     ```
   - Save and close the file, or use `rm ~/.ssh/authorized_keys` if you wish to remove the entire file.

## Configuration

1. Copy `config.ini.template` to `config.ini` in the root directory:
    ```bash
    cp config.ini.template config.ini
    ```
2. Edit `config.ini` to include the remote server details:
```
[remote]
ip = 190.190.190.190
port = 22
user = rootuser
password = "123"

[config]
ssl=1
dkim=1
```
   - `ip`: IP address of the remote server
   - `port`: SSH port for the remote server
   - `user`: SSH username for the remote server
   - `password`: SSH password for non-interactive login, if you don't set the password, we will use sudo without password, the script will assume that you already added ssh key for the server, also if you did not set the password, you have to add cat and cyberpanel commands to sudoers, you may remove them later for security reasons.
   - `ssl`: if set to 1, we will use ssl 1 option when creating cyberpanel website
   - `dkim`: if set to 1, we will use dkim 1 option when creating cyberpanel website

   **Note**: Ensure these credentials have sufficient permissions for executing commands on the remote server.

## Usage

1. Run the migration by executing `migrate.sh`:
    ```bash
    ./migrate.sh
    ```
2. Run the migration in verbose mode ( take care that the passwords will be in the bash history )
    ```bash
    ./migrate.sh -v
    ```

3. If `config.ini` is not found, the script will prompt you to create it based on the provided template.

4. **Confirmation Prompt**: You will be prompted to confirm migration. Type `yes` to continue or any other input to abort.

5. **Individual Scripts**: Each script can also be run independently for specific migrations:
    ```bash
    /usr/local/lsws/lsphp81/bin/php migrate-scripts/01-migrate-websites.php
    ```
    run in verbose mode
    ```bash
    /usr/local/lsws/lsphp81/bin/php migrate-scripts/01-migrate-websites.php -v
    ```
## Scripts Overview

1. **00-migrate-info.php**: Provides an overview of the migration process, ensuring all required configurations are in place.

2. **01-migrate-websites.php**: Migrates website configurations from the remote server.

3. **02-migrate-emails.php**: Migrates email accounts associated with each domain.

4. **03-migrate-websites-data.php**: Uses `rsync` to transfer website data from the remote server’s public_html directories.

5. **04-migrate-websites-databases.php**: Migrate websites's mysql databases ( only databases that was created via cyberpanel ).

6. **05-migrate-websites-vmail.php**: Migrates vmail directories for email storage and sets appropriate ownership.

## `migrate.sh` Execution Flow

The `migrate.sh` script ensures each migration step only proceeds if the previous step was successful. If any step fails, the script stops immediately and skips the remaining steps.

### Example Output

```bash
Running migrations...
Migrating websites...
Migrating emails...
Migrating websites data...
Migrating vmail...
All migrations completed.
```

## Additional Notes

- **Full Account Migration**: This script migrates all accounts and associated data from the remote server to the new CyberPanel instance.
- **Safe Execution**: The script is designed to skip over any accounts or websites that already exist on the local server, ensuring no accidental overwrites.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more information.

---

# MIT License

MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
