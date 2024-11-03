<?php

// Function to execute SSH commands and capture errors
function executeSSHCommand($command)
{
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remotePort = $config['remote']['port'];
    $remoteUser = $config['remote']['user'];

    $sshCommand = "ssh -p $remotePort -tt $remoteUser@$remoteIp \"$command\" 2>&1";
    $output = shell_exec($sshCommand);

    if ($output === null) {
        echo "Failed to execute command: $sshCommand\n";
    }
    return $output;
}

// Function to parse and clean JSON, removing unwanted messages
function parseJson($json)
{
    // Remove sudo password prompt and connection closed messages
    $json = preg_replace('/^\[sudo\] password.*$/m', '', $json);
    $json = preg_replace('/^Connection to .* closed\.\s*$/m', '', $json);

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



// Function to retrieve database credentials from a settings file
function getDatabaseCredentialsFromSettings($settingsContent)
{
    preg_match("/'NAME': '(.+?)'/", $settingsContent, $name);
    preg_match("/'USER': '(.+?)'/", $settingsContent, $user);
    preg_match("/'PASSWORD': '(.+?)'/", $settingsContent, $password);

    return [
        'name' => $name[1] ?? '',
        'user' => $user[1] ?? '',
        'password' => $password[1] ?? ''
    ];
}




function getRemoteDatabaseCredentials()
{

    $config = readConfig();
    $remotePassword = $config['remote']['password'];


    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = executeSSHCommand("echo '$remotePassword' | sudo -S cat $settingsPath");

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings);
}




function getLocalDatabaseCredentials()
{

    $settingsPath = "/usr/local/CyberCP/CyberCP/settings.py";
    $settings = file_get_contents($settingsPath);

    if (!$settings) {
        exit("Failed to retrieve remote database credentials.\n");
    }

    return getDatabaseCredentialsFromSettings($settings);
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
    $query = "SELECT password,firstName,lastName,email,type,api,securityLevel,state,initWebsitesLimit,twoFA,secretKey FROM loginSystem_administrator WHERE userName = '$user'";
    $command = "ssh -p $remotePort $remoteUser@$remoteIp \"mysql -u{$remoteDbCredentials['user']} -p{$remoteDbCredentials['password']} -e \\\"$query\\\" {$remoteDbCredentials['name']}\"";

    $remoteData = shell_exec($command);
    if (!$remoteData) {
        echo "Failed to retrieve data for $user from remote database.\n";
        return;
    }

    // Process and update each entry in the local database
    $lines = explode("\n", trim($remoteData));
    foreach (array_slice($lines, 1) as $line) {  // Skip header
        list($password,$firstName,$lastName,$email,$type,$api,$securityLevel,$state,$initWebsitesLimit,$twoFA,$secretKey) = explode("\t", $line);

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
    $command = "ssh -p $remotePort $remoteUser@$remoteIp \"mysql -u{$remoteDbCredentials['user']} -p{$remoteDbCredentials['password']} -e \\\"$query\\\" {$remoteDbCredentials['name']}\"";

    $remoteData = shell_exec($command);
    if (!$remoteData) {
        echo "Failed to retrieve data for $domain from remote database.\n";
        return;
    }

    // Process and update each entry in the local database
    $lines = explode("\n", trim($remoteData));
    foreach (array_slice($lines, 1) as $line) {  // Skip header
        list($email, $password, $mail, $DiskUsage, $emailOwner_id) = explode("\t", $line);

        // Prepare the local UPDATE statement
        $updateQuery = "UPDATE e_users SET password='$password', mail='$mail', DiskUsage='$DiskUsage' WHERE email='$email' AND emailOwner_id='$emailOwner_id'";
        
        $localUpdateCommand = "mysql -u{$localDbCredentials['user']} -p{$localDbCredentials['password']} -e \"$updateQuery\" {$localDbCredentials['name']}";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);

        $output = shell_exec($localUpdateCommand);

        echo "Updated $email locally: $output.\n";
    }
}
