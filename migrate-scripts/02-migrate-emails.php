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

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

// Step 1: Retrieve Remote Database Credentials
$remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();
$localDbCredentials = getLocalDatabaseCredentials();

$websites = getRemoteWebsites();
if (!$websites) {
    output("Failed to retrieve or parse websites list.", exitCode: 1);
}


// Loop through each domain to retrieve and create emails
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    $state = $domainInfo['state'] ?? '';

    output("Processing domain: $domainName", nlBefore: true);

    // Retrieve emails for the current domain
    $emailsJson = executeRemoteSSHCommand("cyberpanel listEmailsJson --domainName $domainName 2>/dev/null", sudo: true);
    if ($emailsJson == 0) {
        continue;
    }
    $emails = parseJson($emailsJson);

    if (!$emails) {
        output("Failed to retrieve or parse emails for domain: $domainName", error: true);
        continue;
    }

    // Create each email account locally
    foreach ($emails as $emailInfo) {
        $email = $emailInfo['email'] ?? '';
        $username = explode('@', $email)[0];
        $emailPassword = bin2hex(random_bytes(6)); // Generate a random password

        output("Creating email account for $email in domain $domainName", nlBefore: true);

        $createEmailCommand = "cyberpanel createEmail --domainName \"$domainName\" --userName \"$username\" --password \"$emailPassword\" 2>&1";
        $createEmailOutput = shellExec($createEmailCommand);

        $result = json_decode($createEmailOutput, true);
        if (!$result) {
            if (str_contains($createEmailOutput, '{"success": 1, "errorMessage": "This account already exists!"}')) {
                output("Email $email already exists.");
            } else {
                output("Failed to create email account for $email in domain $domainName $createEmailOutput", exitCode: 1);
            }
        } else if (!$result['success']) {
            if (str_contains($result['errorMessage'], 'This account already exists!')) {
                output("Email $email already exists.");
            } elseif (str_contains($createEmailOutput, '{"success": 1, "errorMessage": "None"}')) {
                //
            } else {
                output("Failed to create email account for $email in domain $domainName $createEmailOutput", exitCode: 1);
            }
        }

        output("Email: $email created", success: true);
    }
}

// Run updates for each domain
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    if ($domainName) {
        output("Updating credentials for $domainName...", nlBefore: true);
        updateLocalEmailDatabase($remoteDbCredentials, $localDbCredentials, $domainName);
    }
}

output("Email migration completed.", success: true, nlBefore: true);
