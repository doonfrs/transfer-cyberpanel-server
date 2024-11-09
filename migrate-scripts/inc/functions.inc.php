<?php

// Function to execute SSH commands and capture errors

function executeRemoteSqlCommand($query, $remoteCredentials)
{
    $query = str_replace("\n", " ", $query);
    $command = "mysql -u{$remoteCredentials['user']} -p{$remoteCredentials['password']} --batch -e \\\"$query\\\" {$remoteCredentials['name']}";

    return executeRemoteSSHCommand($command);
}

function queryRemoteSql($query, $rootUser = false)
{
    if ($rootUser) {
        $remoteCredentials = getRemoteDatabaseRootCredentials();
    } else {
        $remoteCredentials = getRemoteDatabaseCyberPanelCredentials();
    }


    $query = str_replace("\n", " ", $query);
    $command = "mysql -u{$remoteCredentials['user']} -p{$remoteCredentials['password']} --batch -e \\\"$query\\\" {$remoteCredentials['name']}";

    $output = executeRemoteSSHCommand($command, failOnNoOutput: true);
    $lines = explode("\n", trim($output));
    $result = [];
    $header = array_shift($lines);
    $header = explode("\t", $header);
    $header = array_map('trim', $header);
    foreach ($lines as $line) {
        $result[] = array_combine($header, explode("\t", $line));
    }
    return $result;
}

function execLocalSql($query, $rootUser = false)
{
    if ($rootUser) {
        $localDbCredentials = getLocalDatabaseRootCredentials();
    } else {
        $localDbCredentials = getLocalDatabaseCyberPanelCredentials();
    }

    $query = str_replace("\n", " ", $query);
    $query = str_replace('$', '\$', $query);

    $command = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} --batch -e \"$query\" {$localDbCredentials['name']}";

    $output = shellExec($command);

    return $output;
}




function queryLocalSql($query, $rootUser = false)
{
    if ($rootUser) {
        $localDbCredentials = getLocalDatabaseRootCredentials();
    } else {
        $localDbCredentials = getLocalDatabaseCyberPanelCredentials();
    }

    $query = str_replace("\n", " ", $query);
    $command = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} --batch -e \"$query\" {$localDbCredentials['name']}";

    $output = shellExec($command, failOnNoOutput: true);
    if (!$output) {
        return null;
    }
    $lines = explode("\n", trim($output));
    $result = [];
    $header = array_shift($lines);
    $header = explode("\t", $header);
    $header = array_map('trim', $header);
    foreach ($lines as $line) {
        $result[] = array_combine($header, explode("\t", $line));
    }
    return $result;
}

function executeRemoteSSHCommand(
    $command,
    $saveToFile = null,
    $sudo = false,
    $failOnNoOutput = false
) {
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remotePort = $config['remote']['port'];
    $remoteUser = $config['remote']['user'];
    $remotePassword = trim($config['remote']['password'] ?? '');

    if ($sudo) {
        if ($remotePassword) {
            $remotePassword = str_replace('$', '\$', $remotePassword);
            $command = "echo '$remotePassword' | sudo -S $command";
        } else {
            $command = "sudo $command";
        }
    }

    $sshCommand = "ssh -p $remotePort -tt $remoteUser@$remoteIp \"$command\" 2>&1";
    if ($saveToFile) {
        $sshCommand .= " > $saveToFile";
    }

    $output = shellExec($sshCommand);

    if (empty($output) && $failOnNoOutput) {
        output("Failed to retrieve data from command\n$command", exitCode: 1);
    }

    if ($saveToFile) {
        shellExec("sed -i '\${/Connection to [0-9]\{1,3\}\(\.[0-9]\{1,3\}\)\{3\} closed\./d}' $saveToFile");
    } else {
        $output = preg_replace('/^\[sudo\] password.*$/m', '', $output);
        $output = preg_replace('/^Connection to .* closed\.\s*$/m', '', $output);
    }

    return trim($output);
}

// Function to parse and clean JSON, removing unwanted messages
function parseJson($json)
{
    // Decode the JSON
    $data = json_decode($json, true);
    if (is_string($data)) {
        $data = json_decode($data, true);
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        output("Error decoding JSON: " . json_last_error_msg() . "");
        output("Raw JSON response:\n$json");
        return null;
    }

    return $data;
}

function readConfig()
{
    $config = parse_ini_file("config.ini", true);
    return $config;
}


function sshCopyId()
{

    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remotePort = $config['remote']['port'];
    $remoteUser = $config['remote']['user'];
    $remotePassword = $config['remote']['password'];

    if (!$remotePassword) {
        return;
    }



    if (!file_exists(getenv("HOME") . "/.ssh/id_rsa.pub")) {
        output("SSH key not found in ~/.ssh/id_rsa.pub we will generate it now.");
        // Generate the SSH key
        $sshKeyCommand = "ssh-keygen -t rsa -b 4096 -P '' -f ~/.ssh/id_rsa -q";
        shellExec($sshKeyCommand . " 2>&1");
    }

    // Step 1: Check if SSH key-based authentication is set up
    $checkSshCommand = "ssh -o BatchMode=yes -p $remotePort $remoteUser@$remoteIp 'echo SSH connection established' 2>&1";

    $output = shellExec($checkSshCommand);

    // If SSH key authentication fails, set up the keys using ssh-copy-id
    if (strpos($output, 'SSH connection established') === false) {
        output("SSH key-based authentication is not set up. Setting it up now...");

        // Alert the user before running ssh-copy-id
        output("We are about to run ssh-copy-id to set up passwordless SSH access to $remoteIp. This will require your SSH password once.");

        // Use sshpass with ssh-copy-id to set up key-based authentication
        $sshCopyIdCommand = "sshpass -p '$remotePassword' ssh-copy-id -i ~/.ssh/id_rsa.pub -o StrictHostKeyChecking=no -p $remotePort $remoteUser@$remoteIp";
        $output = shellExec($sshCopyIdCommand . " 2>&1");

        output("ssh-copy-id output:\n$output");
    }
}


function getRemoteWebsites()
{
    // CyberPanel command to list websites
    $cyberpanelCommand = "cyberpanel listWebsitesJson 2>/dev/null";

    // Retrieve list of websites from CyberPanel on the remote server
    $websitesJson = executeRemoteSSHCommand($cyberpanelCommand, sudo: true);
    $websites = parseJson($websitesJson);

    $onlyWebsites = getWebsiteArguments();

    if ($onlyWebsites) {
        $onlyWebsites = array_map('trim', $onlyWebsites);

        output("Only migrating " . implode(', ', $onlyWebsites), nlBefore: true);
        $websites = array_filter($websites, function ($website) use ($onlyWebsites) {
            return in_array(trim($website['domain']), $onlyWebsites);
        });
        output("found: " . implode(', ', array_column($websites, 'domain')), nlBefore: true);
    }

    return $websites;
}



function getRemotePackages()
{
    $cyberpanelCommand = "cyberpanel listPackagesJson 2>/dev/null";

    // Retrieve list of packages
    $packagesJson = executeRemoteSSHCommand($cyberpanelCommand, sudo: true);
    $packages = parseJson($packagesJson);
    if (!$packages) {
        output("Failed to retrieve or parse packages list.", exitCode: 1);
    }

    return $packages;
}


// Function to retrieve database credentials from a settings file
function getDatabaseCredentialsFromSettings($settingsContent, $dbName)
{
    preg_match_all('/^DATABASES\s*=\s*\{(.+)^\}/ms', $settingsContent, $output_array);
    if (!isset($output_array[1][0])) {
        output("Failed to retrieve database credentials, the settings file is probably not valid.\n\n$settingsContent", exitCode: 1);
    }
    $settingsContent = $output_array[1][0];

    preg_match_all('/\'' . $dbName . '\':\s*\{(.+?)\},/ms', $settingsContent, $output_array);

    $settingsContent = $output_array[1][0];

    preg_match("/'NAME': '(.+?)'/", $settingsContent, $name);
    preg_match("/'USER': '(.+?)'/", $settingsContent, $user);
    preg_match("/'PASSWORD': '(.+?)'/", $settingsContent, $password);

    return [
        'name' => $name[1] ?? '',
        'user' => $user[1] ?? '',
        'password' => $password[1] ?? ''
    ];
}




function getRemoteDatabaseCyberPanelCredentials()
{

    if (isset($GLOBALS['remoteCyberPanelDbCredentials'])) {
        return $GLOBALS['remoteCyberPanelDbCredentials'];
    }

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = trim(executeRemoteSSHCommand("cat $settingsPath", sudo: true));

    if (!$settings) {
        output("Failed to retrieve remote database credentials.", exitCode: 1);
    }

    $GLOBALS['remoteCyberPanelDbCredentials'] = getDatabaseCredentialsFromSettings($settings, 'default');

    return $GLOBALS['remoteCyberPanelDbCredentials'];
}



function getRemoteDatabaseRootCredentials()
{
    if (isset($GLOBALS['remoteRootDbCredentials'])) {
        return $GLOBALS['remoteRootDbCredentials'];
    }

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = trim(executeRemoteSSHCommand("cat $settingsPath", sudo: true));

    if (!$settings) {
        output("Failed to retrieve remote database credentials.", exitCode: 1);
    }

    $GLOBALS['remoteRootDbCredentials'] = getDatabaseCredentialsFromSettings($settings, 'rootdb');

    return $GLOBALS['remoteRootDbCredentials'];
}

function getLocalDatabaseCyberPanelCredentials()
{

    if (isset($GLOBALS['localCyberPanelDbCredentials'])) {
        return $GLOBALS['localCyberPanelDbCredentials'];
    }

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = trim(file_get_contents($settingsPath));

    if (!$settings) {
        output("Failed to retrieve remote database credentials.", exitCode: 1);
    }

    $GLOBALS['localCyberPanelDbCredentials'] = getDatabaseCredentialsFromSettings($settings, 'default');

    return $GLOBALS['localCyberPanelDbCredentials'];
}

function getLocalDatabaseRootCredentials()
{

    if (isset($GLOBALS['localRootDbCredentials'])) {
        return $GLOBALS['localRootDbCredentials'];
    }

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = trim(file_get_contents($settingsPath));

    if (!$settings) {
        output("Failed to retrieve remote database credentials.", exitCode: 1);
    }

    $GLOBALS['localRootDbCredentials'] = getDatabaseCredentialsFromSettings($settings, 'rootdb');

    return $GLOBALS['localRootDbCredentials'];
}


// Step 4: Update Local Database with Remote Data
function updateLocalUserDatabase($user)
{
    $remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();
    $localDbCredentials  = getLocalDatabaseCyberPanelCredentials();

    // Fetch remote email data for this domain
    $query = "SELECT password,firstName,lastName,email,type,api,securityLevel,state,initWebsitesLimit,twoFA,secretKey 
    FROM loginSystem_administrator WHERE userName = '$user'";
    $remoteData = queryRemoteSql($query, $remoteDbCredentials);
    if (!$remoteData) {
        output("No email data for $user found on remote server.");
        return;
    }
    foreach ($remoteData as $row) {
        if (!isset($row['password'])) {
            output("No password for $user found on remote server, returned row: " . json_encode($row) . "\nReturned data: " . json_encode($remoteData) . "\n", error: true);
            //press anykey to continue
            readLine();
            continue;
        }

        $password = $row['password'];
        $firstName = $row['firstName'];
        $lastName = $row['lastName'];
        $email = $row['email'];
        $type = $row['type'];
        $api = $row['api'];
        $securityLevel = $row['securityLevel'];
        $state = $row['state'];
        $initWebsitesLimit = $row['initWebsitesLimit'];
        $twoFA = $row['twoFA'];
        $secretKey = $row['secretKey'];

        // Prepare the local UPDATE statement
        $updateQuery = "UPDATE loginSystem_administrator SET password='$password', firstName='$firstName',lastName='$lastName',email='$email',type='$type',api='$api',securityLevel='$securityLevel',state='$state',initWebsitesLimit='$initWebsitesLimit',twoFA='$twoFA',secretKey='$secretKey' WHERE userName = '$user'";

        $localUpdateCommand = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} -e \"$updateQuery\" {$localDbCredentials['name']}";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);

        $output = shellExec($localUpdateCommand);
        if ($output) {
            output("Failed to update $user locally, error: $output", exitCode: 1);
        }
    }
}


function getWebsiteEmails($domain)
{

    $remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();
    // Fetch remote email data for this domain
    $query = "SELECT email,emailOwner_id, password, mail, DiskUsage  FROM e_users WHERE emailOwner_id = '$domain'";

    $remoteData = queryRemoteSql($query, $remoteDbCredentials);
    if (!$remoteData) {
        return;
    }

    return $remoteData;
}

// Step 4: Update Local Database with Remote Data
function updateLocalEmailDatabase($domain)
{

    // Fetch remote email data for this domain
    $query = "SELECT email, password, mail, DiskUsage, emailOwner_id FROM e_users WHERE emailOwner_id = '$domain'";

    $remoteData = queryRemoteSql($query);
    if (!$remoteData) {
        return;
    }

    foreach ($remoteData as $row) {  // Skip header
        extract($row);

        output("Updating email: $email");

        // Prepare the local UPDATE statement
        $updateQuery = "UPDATE e_users SET password='$password', DiskUsage='$DiskUsage' WHERE email='$email' AND emailOwner_id='$emailOwner_id'";

        $output = execLocalSql($updateQuery);

        if ($output) {
            output("failed  to update email $domain locally, error: $output", exitCode: 1);
        }
    }
}


function shellExec(
    $command,
    $failOnNoOutput = false
) {
    if (isVerboseMode()) {
        output($command . "");
    }
    $output =  trim(shell_exec($command) ?? '');

    if (empty($output) && $failOnNoOutput) {
        output("Failed to execute command: $command", exitCode: 1);
    }

    return $output;
}


function isVerboseMode()
{
    return isset(getopt('v')['v']);
}

function getWebsiteArguments(): ?array
{
    $opts =  getopt('v', ['website:']);
    $websiteArg = $opts['website'] ?? null;
    if ($websiteArg && is_string($websiteArg)) {
        $websiteArg = [$websiteArg];
    }
    return $websiteArg;
}

function restartLiteSpeed(bool $gracefull = true)
{
    $command = "/usr/local/lsws/bin/lswsctrl";
    if ($gracefull) {
        $command .= " restart";
    } else {
        $command .= " fullrestart";
    }

    return shellExec($command);
}

function checkPhpVersion()
{
    $requiredVersion = '8.1.0';
    $currentVersion = PHP_VERSION;
    if (version_compare($currentVersion, $requiredVersion, '<')) {
        output("Current PHP version is $currentVersion, which is lower than the required version 8.1.0.");
        output("Please upgrade PHP to version 8.1 or higher.");
        exit(1); // Exit with a non-zero status to indicate failure
    }

    ini_set('memory_limit', '-1');
}

function output(
    $message,
    ?bool $error = false,
    ?bool $success = false,
    ?bool $info = false,
    $exitCode = null,
    $nl = true,
    $nlBefore = false,
    ?bool $writeDate = true,
) {

    $timestamp = null;

    if ($writeDate) {
        $timestamp = "[ " . date('Y-m-d H:i:s') . " ]";
    }

    // Set color codes
    $colorStart = "";
    $colorEnd = "\033[0m";  // Reset color at the end of the message

    // Determine color based on log level
    if ($error || ($exitCode !== 0 && $exitCode !== null)) {
        $colorStart = "\033[31m";  // Red for errors
    } elseif ($success) {
        $colorStart = "\033[32m";  // Green for success
    } elseif ($info) {
        $colorStart = "\033[34m";  // Blue for info
    }


    if ($nlBefore) {
        echo ("\n");
    }

    // Display the formatted message with timestamp and color
    echo ("{$colorStart}$timestamp $message{$colorEnd}");

    if ($nl) {
        echo ("\n");
    }

    if ($exitCode !== null) {
        exit($exitCode);
    }
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
