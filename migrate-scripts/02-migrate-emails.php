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

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

// Step 1: Retrieve Remote Database Credentials
$remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();
$localDbCredentials = getLocalDatabaseCredentials();

$websites = getRemoteWebsites();
if (!$websites) {
    exit("Failed to retrieve or parse websites list.\n");
}


// Loop through each domain to retrieve and create emails
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    $state = $domainInfo['state'] ?? '';

    echo "Processing domain: $domainName (Status: $state)\n";

    // Retrieve emails for the current domain
    $emailsJson = executeRemoteSSHCommand("cyberpanel listEmailsJson --domainName $domainName 2>/dev/null", sudo: true);
    if ($emailsJson == 0) {
        continue;
    }
    $emails = parseJson($emailsJson);

    if (!$emails) {
        echo "Failed to retrieve or parse emails for domain: $domainName\n";
        continue;
    }

    // Create each email account locally
    foreach ($emails as $emailInfo) {
        $email = $emailInfo['email'] ?? '';
        $username = explode('@', $email)[0];
        $emailPassword = bin2hex(random_bytes(6)); // Generate a random password

        echo "Creating email account for $email in domain $domainName \n";

        $createEmailCommand = "cyberpanel createEmail --domainName \"$domainName\" --userName \"$username\" --password \"$emailPassword\" 2>&1";
        $createEmailOutput = shellExec($createEmailCommand);

        $result = json_decode($createEmailOutput, true);
        if (!$result) {
            exit("Failed to create email account for $email in domain $domainName $createEmailOutput\n");
        }

        if (!$result['success']) {
            if (str_contains($result['errorMessage'], 'This account already exists!')) {
                echo "Email $email already exists.\n";
            } elseif (str_contains($createEmailOutput, '{"success": 1, "errorMessage": "None"}')) {
                //
            } else {
                exit("Failed to create email account for $email in domain $domainName $createEmailOutput\n");
            }
        }

        echo "Email: $email created\n";
    }
}

// Run updates for each domain
foreach ($domains as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    if ($domainName) {
        echo "Updating credentials for $domainName...\n";
        updateLocalEmailDatabase($remoteDbCredentials, $localDbCredentials, $domainName);
    }
}

echo "Email migration and database update completed.\n";
