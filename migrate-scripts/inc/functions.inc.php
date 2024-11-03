<?php

// Function to execute SSH commands and capture errors

function executeRemoteSqlCommand($query, $remoteCredentials)
{
    $query = str_replace("\n", " ", $query);
    $command = "mysql -u{$remoteCredentials['user']} -p{$remoteCredentials['password']} --batch -e \\\"$query\\\" {$remoteCredentials['name']}";

    return executeRemoteSSHCommand($command);
}

function queryRemoteSql($query, $remoteCredentials)
{
    $query = str_replace("\n", " ", $query);
    $command = "mysql -u{$remoteCredentials['user']} -p{$remoteCredentials['password']} --batch -e \\\"$query\\\" {$remoteCredentials['name']}";

    $output = executeRemoteSSHCommand($command);
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

function executeRemoteSSHCommand($command, $saveToFile = null)
{
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remotePort = $config['remote']['port'];
    $remoteUser = $config['remote']['user'];

    $sshCommand = "ssh -p $remotePort -tt $remoteUser@$remoteIp \"$command\" 2>&1";
    if ($saveToFile) {
        $sshCommand .= " > $saveToFile";
    }


    $output = shell_exec($sshCommand);

    if ($saveToFile) {
        shell_exec("sed -i '\${/Connection to [0-9]\{1,3\}\(\.[0-9]\{1,3\}\)\{3\} closed\./d}' $saveToFile");
    } else {
        $output = preg_replace('/^\[sudo\] password.*$/m', '', $output);
        $output = preg_replace('/^Connection to .* closed\.\s*$/m', '', $output);
    }

    return $output;
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
        echo "Error decoding JSON: " . json_last_error_msg() . "\n";
        echo "Raw JSON response:\n$json\n";
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

    // Define the path to the SSH key
    $sshKeyPath = "~/.ssh/id_rsa.pub";

    // Step 1: Check if SSH key-based authentication is set up
    $checkSshCommand = "ssh -o BatchMode=yes -p $remotePort $remoteUser@$remoteIp 'echo SSH connection established' 2>&1";

    $output = shell_exec($checkSshCommand);

    // If SSH key authentication fails, set up the keys using ssh-copy-id
    if (strpos($output, 'SSH connection established') === false) {
        echo "SSH key-based authentication is not set up. Setting it up now...\n";

        // Alert the user before running ssh-copy-id
        echo "We are about to run ssh-copy-id to set up passwordless SSH access to $remoteIp. This will require your SSH password once.\n";

        // Use sshpass with ssh-copy-id to set up key-based authentication
        $sshCopyIdCommand = "sshpass -p '$remotePassword' ssh-copy-id -i $sshKeyPath -o StrictHostKeyChecking=no -p $remotePort $remoteUser@$remoteIp";
        $output = shell_exec($sshCopyIdCommand . " 2>&1");

        echo "ssh-copy-id output:\n$output\n";
    }
}


function getRemoteWebsites()
{
    $config = readConfig();
    $remotePassword = $config['remote']['password'];

    // CyberPanel command to list websites
    $cyberpanelCommand = "echo '$remotePassword' | sudo -S cyberpanel listWebsitesJson 2>/dev/null";

    // Retrieve list of websites from CyberPanel on the remote server
    $websitesJson = executeRemoteSSHCommand($cyberpanelCommand);
    $websites = parseJson($websitesJson);
    return $websites;
}


// Function to retrieve database credentials from a settings file
function getDatabaseCredentialsFromSettings($settingsContent, $dbName)
{
    preg_match_all('/^DATABASES\s*=\s*\{(.+)^\}/ms', $settingsContent, $output_array);
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

    $config = readConfig();
    $remotePassword = $config['remote']['password'];


    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = executeRemoteSSHCommand("echo '$remotePassword' | sudo -S cat $settingsPath");

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings, 'default');
}


function getRemoteDatabaseRootCredentials()
{

    $config = readConfig();
    $remotePassword = $config['remote']['password'];


    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = executeRemoteSSHCommand("echo '$remotePassword' | sudo -S cat $settingsPath");

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings, 'rootdb');
}

function getLocalDatabaseCredentials()
{

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = file_get_contents($settingsPath);

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings, 'default');
}

function getLocalDatabaseRootCredentials()
{

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = file_get_contents($settingsPath);

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings, 'rootdb');
}


// Step 4: Update Local Database with Remote Data
function updateLocalUserDatabase($remoteDbCredentials, $localDbCredentials, $user)
{
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remoteUser = $config['remote']['user'];
    $remotePort = $config['remote']['port'];

    // Fetch remote email data for this domain
    $query = "SELECT password,firstName,lastName,email,type,api,securityLevel,state,initWebsitesLimit,twoFA,secretKey 
    FROM loginSystem_administrator WHERE userName = '$user'";
    $remoteData = queryRemoteSql($query, $remoteDbCredentials);
    if (!$remoteData) {
        echo "Failed to retrieve data for $user from remote database.\n";
        return;
    }
    foreach ($remoteData as $row) {
        extract($row);
        // Prepare the local UPDATE statement
        $updateQuery = "UPDATE loginSystem_administrator SET password='$password', firstName='$firstName',lastName='$lastName',email='$email',type='$type',api='$api',securityLevel='$securityLevel',state='$state',initWebsitesLimit='$initWebsitesLimit',twoFA='$twoFA',secretKey='$secretKey' WHERE userName = '$user'";

        $localUpdateCommand = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} -e \"$updateQuery\" {$localDbCredentials['name']}";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);

        $output = shell_exec($localUpdateCommand);

        echo "Updated $user locally: $output.\n";
    }
}



// Step 4: Update Local Database with Remote Data
function updateLocalEmailDatabase($remoteDbCredentials, $localDbCredentials, $domain)
{
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remoteUser = $config['remote']['user'];
    $remotePort = $config['remote']['port'];

    // Fetch remote email data for this domain
    $query = "SELECT email, password, mail, DiskUsage, emailOwner_id FROM e_users WHERE emailOwner_id = '$domain'";

    $remoteData = queryRemoteSql($query, $remoteDbCredentials);
    if (!$remoteData) {
        echo "Failed to retrieve data for $domain from remote database.\n";
        return;
    }

    foreach ($remoteData as $row) {  // Skip header
        extract($row);

        // Prepare the local UPDATE statement
        $updateQuery = "UPDATE e_users SET password='$password', mail='$mail', DiskUsage='$DiskUsage' WHERE email='$email' AND emailOwner_id='$emailOwner_id'";

        $localUpdateCommand = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} -e \"$updateQuery\" {$localDbCredentials['name']}";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);

        $output = shell_exec($localUpdateCommand);

        echo "Updated $email locally: $output.\n";
    }
}


set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
