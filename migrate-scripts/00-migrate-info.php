<?php


error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';

checkPhpVersion();

// Load configurations from the ini file
$config = readConfig();

$remoteUser = $config['remote']['user'];
$remotePassword = trim($config['remote']['password'] ?? '');

output('', writeDate: false);
output("**********************************************", writeDate: false);
output("
1 - Please make sure that you are running as root or sudo.
2 - Please add rsync to the sudoers filein the remote server.
In remote server shell type sudo visudo, and add the following line:
$remoteUser ALL=(ALL) NOPASSWD: /usr/bin/rsync
", info: true, writeDate: false);

if (!$remotePassword) {
    output("
Also, because you did not provide a password for the remote server, you will need to add the following line to the remote server's sudoers file:
$remoteUser ALL=(ALL) NOPASSWD: /usr/bin/cat
$remoteUser ALL=(ALL) NOPASSWD: /usr/bin/cyberpanel
", info: true, writeDate: false);
}

output("**********************************************", writeDate: false);
output("", writeDate: false);
