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

$remotePath = "/home/vmail";
$localPath = "/home/vmail";


$websites = getRemoteWebsites();

if (!$websites) {
    output("No websites found.", exitCode: 1);
}

// Run updates for each domain
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';

    // Rsync command to transfer /home/vmail directory
    output("Migrating /home/vmail/$domainName directory...");
    $rsyncCommand = "rsync -a --info=progress2 -e 'ssh -p $remotePort' --rsync-path=\"sudo rsync\" $remoteUser@$remoteIp:$remotePath/$domainName/ $localPath/$domainName/";
    $rsyncOutput = shellExec($rsyncCommand . " 2>&1");

    if (str_contains($rsyncOutput, 'No such file or directory')) {
        output("No email data found in /home/vmail/$domainName directory.");
        continue;
    } elseif (str_contains($rsyncOutput, "error")) {
        output("Rsync failed. $rsyncOutput, failed command:\n$rsyncCommand\n", exitCode: 1);
    }

    output("Rsync completed successfully.\n$rsyncOutput", success: true);
    output("Changing ownership to vmail:vmail for /home/vmail...");
    // Set ownership to vmail:vmail recursively
    $chownCommand = "chown -R vmail:vmail $localPath/$domainName";
    $chownOutput = shellExec($chownCommand . " 2>&1");
}

output("Email Migration, ownership, and permissions completed.", success: true);
