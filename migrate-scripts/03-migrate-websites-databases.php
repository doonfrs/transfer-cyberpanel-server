<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeRemoteSSHCommand, and sshCopyId

// Load configurations
$config = readConfig();

// Remote server details
$remoteIp = $config['remote']['ip'];
$remotePort = $config['remote']['port'];
$remoteUser = $config['remote']['user'];
$remotePassword = $config['remote']['password'];

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$remoteRootDbCredentials = getRemoteDatabaseRootCredentials();
$localDbCredentials = getLocalDatabaseCredentials();
$localRootDbCredentials = getLocalDatabaseRootCredentials();
$remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();

$websites = getRemoteWebsites();
if (!$websites) {
    exit("Failed to retrieve or parse websites list.\n");
}

// Loop through each website to migrate data
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $websiteId = $site['id'] ?? '';

    // Minimal logging output
    echo "Migrating website databases: $domain...\n";
    transferWebsiteDatabases($domain);
    echo "Migration completed for databases for website: $domain.\n";
}




// Step 4: Update Local Database with Remote Data
function transferWebsiteDatabases($domain)
{
    global $remoteRootDbCredentials, $localDbCredentials,
        $remoteDbCredentials, $localRootDbCredentials;
    $config = readConfig();

    // Remote server details
    $remoteIp = $config['remote']['ip'];
    $remoteUser = $config['remote']['user'];
    $remotePort = $config['remote']['port'];

    // Fetch remote email data for this domain
    $query = "SELECT dbName,dbUser FROM databases_databases
        INNER JOIN websiteFunctions_websites ON websiteFunctions_websites.id = databases_databases.website_id 
        WHERE websiteFunctions_websites.domain = '$domain'";

    $remoteData = queryRemoteSql($query, $remoteDbCredentials);

    echo "Remote databases: " . count($remoteData) . "\n" . implode(', ', array_column($remoteData, 'dbName')) . "\n";

    if (!$remoteData && !is_array($remoteData)) {
        exit("Failed to retrieve data for $domain from remote database.\n");
    }

    if (!is_dir('mysqldumps')) {
        mkdir('mysqldumps');
    }

    foreach ($remoteData as $row) {
        $dbName = trim($row['dbName']);
        $dbUser = trim($row['dbUser']);

        echo "Creating database $dbName for $domain...\n";

        $randomPassword = bin2hex(random_bytes(6));
        $command = "cyberpanel createDatabase --databaseWebsite $domain --dbName $dbName --dbUsername $dbUser --dbPassword '$randomPassword'";

        $output = shell_exec($command);

        $result = json_decode($output, true);
        if (!$result) {
            if (!str_contains($output, 'This database or user is already taken.')) {
                exit("Failed to create database. $output\n");
            }
        } elseif (!$result['success']) {
            exit("Failed to create database. $output\n");
        }


        echo "Retrieving mysql passwords for $dbUser / $domain...\n";
        //update mysql user
        $query = "SELECT Password,authentication_string FROM mysql.user WHERE User = '$dbUser'";

        $result = queryRemoteSql($query, $remoteRootDbCredentials);

        if (!$result) {
            exit("Failed to mysql passwords for $dbUser / $domain from remote database.\n");
        }

        $result = $result[0];
        $password = $result['Password'];

        $output = shell_exec("mysql -u{$localRootDbCredentials['user']} -p{$localRootDbCredentials['password']} -e \"ALTER USER '$dbUser'@'localhost' IDENTIFIED BY PASSWORD '$password'\"");

        if ($output) {
            exit("Failed to mysql passwords for $dbUser / $domain from remote database $output.\n");
        }

        $dumpFileName = "mysqldumps/$dbName-$dbUser.sql";
        echo "Retrieving mysql dump for $dbName $dbUser...\n";
        executeRemoteSSHCommand(
            "mysqldump -u{$remoteRootDbCredentials['user']} -p{$remoteRootDbCredentials['password']} $dbName",
            saveToFile: $dumpFileName
        );

        echo "Running mysql dump locally...\n";
        $localUpdateCommand = "mysql -u{$localRootDbCredentials['user']} -p{$localRootDbCredentials['password']} $dbName < $dumpFileName";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);
        $output = shell_exec($localUpdateCommand);
        if ($output) {
            exit("Failed to mysql passwords for $dbUser / $domain from remote database $output.\n");
        }
        echo "Created the database $dbName $dbUser on locally.\n";
    }
}
