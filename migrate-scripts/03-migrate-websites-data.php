<?php

require_once 'inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeSSHCommand, and sshCopyId

// Load configurations
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

// CyberPanel command to list websites
$cyberpanelCommand = "echo '$remotePassword' | sudo -S cyberpanel listWebsitesJson 2>/dev/null";

// Retrieve list of websites from CyberPanel on the remote server
$websitesJson = executeSSHCommand($cyberpanelCommand);
$websites = parseJson($websitesJson);
if (!$websites) {
    exit("Failed to retrieve or parse websites list.\n");
}

// Function to transfer website data using rsync with sudo on the remote side
function transferWebsiteData($remoteUser, $remoteIp, $remotePort, $remotePath, $localPath) {
    // Include the remote port using -p in the SSH command
    $rsyncCommand = "rsync -a --info=progress2 -e 'ssh -p $remotePort' --rsync-path=\"sudo -n rsync\" $remoteUser@$remoteIp:$remotePath/ $localPath/";
    shell_exec($rsyncCommand . " 2>&1");
}

// Function to set ownership of the local public_html directory based on /home/$domain owner
function setLocalOwnershipFromParent($domain) {
    $parentDir = "/home/$domain";
    $targetDir = "$parentDir/public_html";

    // Retrieve the owner and group of the /home/$domain directory
    $ownerInfo = posix_getpwuid(fileowner($parentDir));
    $groupInfo = posix_getgrgid(filegroup($parentDir));

    if ($ownerInfo && $groupInfo) {
        $localOwner = $ownerInfo['name'];
        $localGroup = $groupInfo['name'];
        $chownCommand = "chown -R $localOwner:$localGroup $targetDir";
        shell_exec($chownCommand . " 2>&1");
    }
}

// Loop through each website to migrate data
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';

    if (!$domain) {
        continue;  // Skip if domain is invalid
    }

    // Define the remote and local paths for rsync, both following the /home/<domain>/public_html structure
    $remotePath = "/home/$domain/public_html";
    $localPath = "/home/$domain/public_html";

    // Check if local path exists; if not, skip migration
    if (!file_exists($localPath)) {
        continue;
    }

    // Minimal logging output
    echo "Migrating website: $domain...\n";
    transferWebsiteData($remoteUser, $remoteIp, $remotePort, $remotePath, $localPath);
    setLocalOwnershipFromParent($domain);
    echo "Migration completed for website: $domain.\n";
}