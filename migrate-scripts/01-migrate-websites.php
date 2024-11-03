<?php

require_once 'inc/functions.inc.php';

// Load configurations from the ini file
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$remoteDbCredentials = getRemoteDatabaseCredentials();
$localDbCredentials = getLocalDatabaseCredentials();


// CyberPanel command to list websites
$cyberpanelCommand = "echo '$remotePassword' | sudo -S cyberpanel listWebsitesJson 2>/dev/null";

$websitesJson = executeSSHCommand($cyberpanelCommand);

$websites = parseJson($websitesJson);
if (!$websites) {
    exit("Failed to retrieve or parse websites list.\n");
}

$cyberpanelCommand = "echo '$remotePassword' | sudo -S cyberpanel listPackagesJson 2>/dev/null";

// Retrieve list of packages
$packagesJson = executeSSHCommand($cyberpanelCommand);
$packages = parseJson($packagesJson);
if (!$packages) {
    exit("Failed to retrieve or parse packages list.\n");
}

foreach ($packages as $package) {

    $packageName = $package['packageName'];
    $domains = $package['domains'];
    $diskSpace = $package['diskSpace'];
    $bandwidth = $package['bandwidth'];
    $ftpAccounts = $package['ftpAccounts'] ?? $package['ftpAccounts '];
    $dataBases = $package['dataBases'];
    $emailAccounts = $package['emailAccounts'];

    $cyberpanelCommand = "cyberpanel createPackage --owner admin --packageName '$packageName' --diskSpace $diskSpace --bandwidth $bandwidth --emailAccounts $emailAccounts --dataBases $dataBases --ftpAccounts $ftpAccounts --allowedDomains $domains 2>&1";
    $output = shell_exec($cyberpanelCommand);

    $result = json_decode($output, true);
    if (!$result) {
        exit("Failed to create package $output\n");
    }

    if (!$result['success']) {
        if (str_contains($result['errorMessage'], 'Duplicate entry') && str_contains($result['errorMessage'], "for key 'packageName")) {
            echo "Package $packageName already exists.\n";
        } else {
            exit("Failed to create package. $output\n");
        }
    }
}

// Loop through each website to create locally if active
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $adminEmail = $site['adminEmail'] ?? '';
    $owner = $site['admin'] ?? '';
    $package = $site['package'] ?? '';
    $state = $site['state'] ?? '';

    $firstName = strlen($owner) > 10 ? substr($owner, 0, 10) : $owner;
    $lastName = strlen($owner) > 10 ? substr($owner, 0, 10) : $owner;
    $firstName = preg_replace('/[^a-zA-Z]/', '', $firstName);
    $lastName = preg_replace('/[^a-zA-Z]/', '', $lastName);

    // Generate a random password
    $randomPassword = bin2hex(random_bytes(6));

    echo "Creating owner $owner for $domain.\n";
    $createUserCommand = "cyberpanel createUser --firstName '$firstName' --lastName '$lastName' --email '$adminEmail' --userName '$owner' --password '$randomPassword' --websitesLimit 100 --selectedACL user --securityLevel HIGH 2>&1";
    $createUserOutput = shell_exec($createUserCommand);

    $result = json_decode($createUserOutput, true);
    if (!$result) {
        exit("Failed to create user. $createUserOutput\n");
    }

    if (!$result['status']) {
        if (str_contains($result['error_message'], 'Duplicate entry') && str_contains($result['error_message'], "for key 'userName")) {
            echo "User $owner already exists.\n";
        } else {
            exit("Failed to create user. $createUserOutput\n");
        }
    }

    // Generate a random password
    $randomPassword = bin2hex(random_bytes(6));

    echo "Creating website for $domain with owner $owner.\n";

    // Command to create website on local CyberPanel server
    $createWebsiteCommand = "cyberpanel createWebsite --domainName \"$domain\" --owner \"$owner\" --email \"$adminEmail\" --package \"$package\" --php 8.1 --ssl 1 --dkim 1 --password \"$randomPassword\" 2>&1";
    $createWebsiteOutput = shell_exec($createWebsiteCommand);

    $result = json_decode($createWebsiteOutput, true);
    if (!$result) {
        if (!str_contains($createWebsiteOutput, '{"success": 1, "errorMessage": "None"}')) {
            exit("Failed to create website. $createWebsiteOutput\n");
        }
    } else if (!$result['success']) {
        if (str_contains($result['errorMessage'], 'already exists.')) {
            echo "Website $domain already exists.\n";
        } else {
            exit("Failed to create website. $createWebsiteOutput\n");
        }
    }
    // Output the domain and password for reference
    echo "Website created: $domain\n";
}



// Run updates for each domain
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $adminEmail = $site['adminEmail'] ?? '';
    $owner = $site['admin'] ?? '';
    echo "Updating credentials for $owner...\n";
    updateLocalUserDatabase($remoteDbCredentials, $localDbCredentials, $owner);
}