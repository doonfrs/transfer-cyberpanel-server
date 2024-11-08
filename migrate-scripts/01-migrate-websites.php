<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';

checkPhpVersion();

// Load configurations from the ini file
$config = readConfig();

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
    output("Failed to retrieve or parse websites list.", exitCode: 1);
}

$cyberpanelCommand = "cyberpanel listPackagesJson 2>/dev/null";

// Retrieve list of packages
$packagesJson = executeRemoteSSHCommand($cyberpanelCommand, sudo: true);
$packages = parseJson($packagesJson);
if (!$packages) {
    output("Failed to retrieve or parse packages list.", exitCode: 1);
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
        output("Failed to create package $output", exitCode: 1);
    }

    if (!$result['success']) {
        if (str_contains($result['errorMessage'], 'Duplicate entry') && str_contains($result['errorMessage'], "for key 'packageName")) {
            output("Package $packageName already exists.");
        } else {
            output("Failed to create package. $output", exitCode: 1);
        }
    } else {
        output("Package $packageName created.", success: true);
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

    if ($owner == 'admin') {
        output("Skipping creating user $owner");
    } else {
        output("Creating owner $owner for $domain.", nlBefore: true);
        $createUserCommand = "cyberpanel createUser --firstName '$firstName' --lastName '$lastName' --email '$adminEmail' --userName '$owner' --password '$randomPassword' --websitesLimit 100 --selectedACL user --securityLevel HIGH 2>&1";
        $createUserOutput = shellExec($createUserCommand);

        $result = json_decode($createUserOutput, true);
        if (!$result) {
            output("Failed to create user. $createUserOutput", exitCode: 1);
        }

        if (!$result['status']) {
            if (str_contains($result['error_message'], 'Duplicate entry') && str_contains($result['error_message'], "for key 'userName")) {
                output("User $owner already exists.");
            } else {
                output("Failed to create user. $createUserOutput", exitCode: 1);
            }
        } else {
            output("User $owner created.", success: true);
        }
    }


    // Generate a random password
    $randomPassword = bin2hex(random_bytes(6));

    output("Creating website for $domain with owner $owner.",  nlBefore: true);


    // Command to create website on local CyberPanel server
    $createWebsiteCommand = "cyberpanel createWebsite --domainName \"$domain\" --owner \"$owner\" --email \"$adminEmail\" --package \"$package\" --php 8.1 --ssl $ssl --dkim $dkim --password \"$randomPassword\" 2>&1";
    $createWebsiteOutput = shellExec($createWebsiteCommand);

    $result = json_decode($createWebsiteOutput, true);
    if (str_contains($createWebsiteOutput, 'You\'ve reached maximum websites limit as a reseller.')) {
        if (str_contains(shellExec("cyberpanel listWebsitesJson"), $domain)) {
            output("Website $domain already exists.");
            continue;
        } else {
            output("Failed to create website. $createWebsiteOutput", exitCode: 1);
        }
    } elseif (!$result) {
        if (!str_contains($createWebsiteOutput, '{"success": 1, "errorMessage": "None"}')) {
            output("Failed to create website. $createWebsiteOutput", exitCode: 1);
        }
    } else if (!$result['success']) {
        if (str_contains($result['errorMessage'], 'already exists.')) {
            output("Website $domain already exists.");
            continue;
        } else {
            output("Failed to create website. $createWebsiteOutput", exitCode: 1);
        }
    }

    if ($state == 'Suspended') {
        output("Website status is suspended.");
        output("Suspend website for $domain with owner $owner.");
        $suspendWebsiteCommand = "cyberpanel suspendUser --userName \"$owner\" --state SUSPEND 2>&1";
        $suspendWebsiteOutput = shellExec($suspendWebsiteCommand);
        if (!str_contains($suspendWebsiteOutput, '{"status": 1}')) {
            output("Failed to suspend website. $suspendWebsiteOutput", exitCode: 1);
        }
    }
    // Output the domain and password for reference
    output("Website created: $domain", success: true);
}

output("Restarting LiteSpeed.", nlBefore: true);
restartLiteSpeed();

output("Updating websites credentials.", nlBefore: true);
// Run updates for each domain
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $adminEmail = $site['adminEmail'] ?? '';
    $owner = $site['admin'] ?? '';
    output("Updating credentials for $owner...", nlBefore: true);
    updateLocalUserDatabase($remoteDbCredentials, $localDbCredentials, $owner);
}

output("Website migration completed.", success: true, nlBefore: true);
