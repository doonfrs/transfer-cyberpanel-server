<?php


error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';

checkPhpVersion();

// Load configurations from the ini file
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

output("**********************************************");
output("1 - Please make sure that you are running as root or sudo.");
output("");
output("2 - Please add rsync to the sudoers filein the remote server.");
output("In remote server shell type sudo visudo, and add the following line:");
output("$remoteUser ALL=(ALL) NOPASSWD: /usr/bin/rsync");
output("Then save and exit.");
output("**********************************************");
output("");
output("");
