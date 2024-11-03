<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeRemoteSSHCommand, and sshCopyId

// Load configurations
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$remotePath = "/home/vmail";
$localPath = "/home/vmail";

// Rsync command to transfer /home/vmail directory
$rsyncCommand = "rsync -a --info=progress2 -e 'ssh -p $remotePort' --rsync-path=\"sudo -n rsync\" $remoteUser@$remoteIp:$remotePath/ $localPath/";
echo "Migrating /home/vmail directory...\n";
$rsyncOutput = shell_exec($rsyncCommand . " 2>&1");
echo $rsyncOutput ? "Rsync completed successfully.\n" : "Rsync failed.\n";


echo "Changing ownership to vmail:vmail for /home/vmail...\n";
// Set ownership to vmail:vmail recursively
$chownCommand = "chown -R vmail:vmail $localPath";
$chownOutput = shell_exec($chownCommand . " 2>&1");

echo "Migration, ownership, and indexing completed.\n";
