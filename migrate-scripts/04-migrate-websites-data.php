<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeRemoteSSHCommand, and sshCopyId

checkPhpVersion();

// Load configurations
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not
$websites = getRemoteWebsites();
if (!$websites) {
    output("No websites found.", exitCode: 1);
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
    output("Migrating website: $domain...", nlBefore: true);
    transferWebsiteData($domain, $remotePath, $localPath);
    setLocalOwnershipFromParent($domain);
    output("Migration completed for website: $domain.", success: true);
}

output("Restarting LiteSpeed.", nlBefore: true);
restartLiteSpeed();
output("Data migration completed.", success: true, nlBefore: true);

// Function to transfer website data using rsync with sudo on the remote side
function transferWebsiteData($domain, $remotePath, $localPath)
{
    global $remoteUser, $remoteIp, $remotePort;

    // Include the remote port using -p in the SSH command
	$rsyncCommand = "rsync -a --info=progress2 -e 'ssh -p $remotePort' --rsync-path=\"sudo -n rsync\" $remoteUser@$remoteIp:$remotePath/ $localPath/";
	$output = shellExec($rsyncCommand . " 2>&1");

	if (str_contains($output, 'No such file or directory')) {
	    // RED TEXT with visual markers
	    echo "\033[1;31m";
	    echo "==================== WARNING ====================\n";
	    echo "No data found for `$domain`. Skipping rsync.\n";
	    echo "=================================================\n";
	    echo "\033[0m";
	    return;
	}

	if (str_contains($output, "error")) {
	    output("Rsync failed. $output, failed command:\n$rsyncCommand\n", exitCode: 1);
	}

	output("Rsync completed successfully.\n$output", success: true);
}

// Function to set ownership of the local public_html directory based on /home/$domain owner
function setLocalOwnershipFromParent($domain)
{
    $parentDir = "/home/$domain";
    $targetDir = "$parentDir/public_html";

    // Retrieve the owner and group of the /home/$domain directory
    $ownerInfo = posix_getpwuid(fileowner($parentDir));
    $groupInfo = posix_getgrgid(filegroup($parentDir));

    if ($ownerInfo && $groupInfo) {
        $localOwner = $ownerInfo['name'];
        $localGroup = $groupInfo['name'];
        $chownCommand = "chown -R $localOwner:$localGroup $targetDir";
        $output = shellExec($chownCommand . " 2>&1");
        if ($output) {
            output("Unable to set ownership for $domain. $output", exitCode: 1);
        }

        $chmodCommand = "chmod -R 755 $targetDir";
        $output = shellExec($chmodCommand . " 2>&1");
        if ($output) {
            output("Unable to set pemission for $domain. $output", exitCode: 1);
        }
    } else {
        output("Unable to set local ownership for $domain.", exitCode: 1);
    }
}
