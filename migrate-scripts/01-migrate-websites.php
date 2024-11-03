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

$ssl = $config['config']['ssl'] ?? 0;
$dkim = $config['config']['dkim'] ?? 0;

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();
$localDbCredentials = getLocalDatabaseCredentials();


// CyberPanel command to list websites
$cyberpanelCommand = "cyberpanel listWebsitesJson 2>/dev/null";

$websitesJson = executeRemoteSSHCommand($cyberpanelCommand, sudo: true);

$websites = parseJson($websitesJson);
if (!$websites) {
    exit("Failed to retrieve or parse websites list.\n");
}

$cyberpanelCommand = "cyberpanel listPackagesJson 2>/dev/null";

// Retrieve list of packages
$packagesJson = executeRemoteSSHCommand($cyberpanelCommand, sudo: true);
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
    $output = shellExec($cyberpanelCommand);

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

// Loop through each website to create locally 
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $adminEmail = $site['adminEmail'] ?? '';
    $owner = $site['admin'] ?? '';
    $package = $site['package'] ?? '';
    $state = $site['state'] ?? '';

    $firstName = strlen($owner) > 10 ? substr($owner, 0, 10) : $owner;
    $lastName = strlen($owner) > 10 ? substr($owner, 0, 10) : $owner;
    $firstName = 'cyberpanel';
    $lastName = 'cyberpanel';

    // Generate a random password
    $randomPassword = bin2hex(random_bytes(6));

    echo "Creating owner $owner for $domain.\n";
    $createUserCommand = "cyberpanel createUser --firstName '$firstName' --lastName '$lastName' --email '$adminEmail' --userName '$owner' --password '$randomPassword' --websitesLimit 100 --selectedACL user --securityLevel HIGH 2>&1";
    $createUserOutput = shellExec($createUserCommand);

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
    $createWebsiteCommand = "cyberpanel createWebsite --domainName \"$domain\" --owner \"$owner\" --email \"$adminEmail\" --package \"$package\" --php 8.1 --ssl $ssl --dkim $dkim --password \"$randomPassword\" 2>&1";
    $createWebsiteOutput = shellExec($createWebsiteCommand);

    $result = json_decode($createWebsiteOutput, true);
    if (str_contains($createWebsiteOutput, 'You\'ve reached maximum websites limit as a reseller.')) {
        if (str_contains(shellExec("cyberpanel listWebsites"), $domain)) {
            echo "Website $domain already exists.\n";
            continue;
        } else {
            exit("Failed to create website. $createWebsiteOutput\n");
        }
    } elseif (!$result) {
        if (!str_contains($createWebsiteOutput, '{"success": 1, "errorMessage": "None"}')) {
            exit("Failed to create website. $createWebsiteOutput\n");
        }
    } else if (!$result['success']) {
        if (str_contains($result['errorMessage'], 'already exists.')) {
            echo "Website $domain already exists.\n";
            continue;
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
