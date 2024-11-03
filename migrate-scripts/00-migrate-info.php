<?php


error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';

// Load configurations from the ini file
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

echo "**********************************************\n";
echo "1 - Please make sure that you are running as root or sudo.\n";
echo "\n";
echo "2 - Please add rsync to the sudoers filein the remote server.\n";
echo "In remote server shell type sudo visudo, and add the following line:\n";
echo "$remoteUser ALL=(ALL) NOPASSWD: /usr/bin/rsync\n";
echo "Then save and exit.\n";
echo "**********************************************\n";
echo "\n";
echo "\n";
