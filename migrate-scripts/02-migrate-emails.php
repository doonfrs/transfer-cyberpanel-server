<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';

checkPhpVersion();

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$websites = getRemoteWebsites();
if (!$websites) {
    output("No websites found.", exitCode: 1);
}


// Loop through each domain to retrieve and create emails
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    $state = $domainInfo['state'] ?? '';

    output("Processing domain: $domainName", nlBefore: true);

    $emails = getWebsiteEmails($domainName);

    if (!$emails) {
        output("no emails found for domain: $domainName");
        continue;
    }


    // Create each email account locally
    foreach ($emails as $emailInfo) {
        $email = $emailInfo['email'] ?? '';
        $username = $emailInfo['emailOwner_id'] ?? '';
        $emailPassword = bin2hex(random_bytes(6)); // Generate a random password

        output("Creating email account for $email in domain $domainName", nlBefore: true);

        $createEmailCommand = "cyberpanel createEmail --domainName \"$domainName\" --userName \"$username\" --password \"$emailPassword\" 2>&1";
        $createEmailOutput = shellExec($createEmailCommand);

        $result = json_decode($createEmailOutput, true);
        if (!$result) {
            if (str_contains($createEmailOutput, '{"success": 1, "errorMessage": "This account already exists!"}')) {
                output("Email $email already exists.");
            } elseif (!(str_contains($createEmailOutput, '{"success": 1, "errorMessage": "None"}'))) {
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
        } else {
            output("Email: $email created", success: true);
        }
    }
}

// Run updates for each domain
foreach ($websites as $domainInfo) {
    $domainName = $domainInfo['domain'] ?? '';
    if ($domainName) {
        output("Updating credentials for $domainName...", nlBefore: true);
        updateLocalEmailDatabase($domainName);
    }
}

output("Email migration completed.", success: true, nlBefore: true);
