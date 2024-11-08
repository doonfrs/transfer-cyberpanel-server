<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeRemoteSSHCommand, and sshCopyId

checkPhpVersion();

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$remoteRootDbCredentials = getRemoteDatabaseRootCredentials();
$localDbCredentials = getLocalDatabaseCredentials();
$localRootDbCredentials = getLocalDatabaseRootCredentials();
$remoteDbCredentials = getRemoteDatabaseCyberPanelCredentials();

$websites = getRemoteWebsites();
if (!$websites) {
    output("Failed to retrieve or parse websites list.", exitCode: 1);
}

// Loop through each website to migrate data
foreach ($websites as $site) {
    $domain = $site['domain'] ?? '';
    $websiteId = $site['id'] ?? '';

    // Minimal logging output
    output("Migrating website databases: $domain...", nlBefore: true);
    transferWebsiteDatabases($domain);
    output("Migration completed for databases for website: $domain.", success: true);
}


output("Restarting LiteSpeed.", nlBefore: true);
restartLiteSpeed();
output("Migration of databases completed.", success: true);


// Step 4: Update Local Database with Remote Data
function transferWebsiteDatabases($domain)
{
    global $remoteRootDbCredentials, $localDbCredentials,
        $remoteDbCredentials, $localRootDbCredentials;

    // Fetch remote email data for this domain
    $query = "SELECT dbName,dbUser FROM databases_databases
        INNER JOIN websiteFunctions_websites ON websiteFunctions_websites.id = databases_databases.website_id 
        WHERE websiteFunctions_websites.domain = '$domain'";

    $remoteData = queryRemoteSql($query, $remoteDbCredentials);
    if (!$remoteData) {
        return;
    }

    output("Remote databases: " . count($remoteData) . "" . implode(', ', array_column($remoteData, 'dbName')) . "");

    $localDatabases = queryLocalSql($query, $localDbCredentials);


    if (!$remoteData && !is_array($remoteData)) {
        output("Failed to retrieve data for $domain from remote database.", exitCode: 1);
    }

    if (!is_dir('mysqldumps')) {
        mkdir('mysqldumps');
    }

    foreach ($remoteData as $row) {
        $dbName = trim($row['dbName']);
        $dbUser = trim($row['dbUser']);

        if (array_search($dbName, array_column($localDatabases, 'dbName')) !== false) {
            output("Database $dbName already exists for $domain, if you want to replace it, please drop it manually first.");
            continue;
        }

        output("Creating database $dbName for $domain...");

        $randomPassword = bin2hex(random_bytes(6));
        $command = "cyberpanel createDatabase --databaseWebsite $domain --dbName $dbName --dbUsername $dbUser --dbPassword '$randomPassword'";

        $output = shellExec($command);

        $result = json_decode($output, true);
        if (!$result) {
            if (!str_contains($output, 'This database or user is already taken.')) {
                output("Failed to create database. $output", exitCode: 1);
            }
        } elseif (!$result['success']) {
            output("Failed to create database. $output", exitCode: 1);
        }


        if ($dbUser != 'admin') {
            output("Retrieving mysql passwords for $dbUser / $domain...");
            //update mysql user
            $query = "SELECT Password,authentication_string FROM mysql.user WHERE User = '$dbUser'";
            $result = queryRemoteSql($query, $remoteRootDbCredentials);

            if (!$result) {
                output("Failed to mysql passwords for $dbUser / $domain from remote database.", exitCode: 1);
            }

            $result = $result[0];
            $password = $result['Password'];

            $output = shellExec("mysql -u{$localRootDbCredentials['user']} -p{$localRootDbCredentials['password']} -e \"ALTER USER '$dbUser'@'localhost' IDENTIFIED BY PASSWORD '$password'\"");

            if ($output) {
                output("Failed to mysql passwords for $dbUser / $domain from remote database $output.", exitCode: 1);
            } else {
                output("Successfully updated mysql passwords for $dbUser / $domain.", success: true);
            }
        }


        $dumpFileName = "mysqldumps/$dbName-$dbUser.sql";
        output("Retrieving mysql dump for $dbName $dbUser...");
        executeRemoteSSHCommand(
            "mysqldump -u{$remoteRootDbCredentials['user']} -p{$remoteRootDbCredentials['password']} $dbName",
            saveToFile: $dumpFileName
        );

        output("Running mysql dump locally...");
        $localUpdateCommand = "mysql -u{$localRootDbCredentials['user']} -p{$localRootDbCredentials['password']} $dbName < $dumpFileName";
        $localUpdateCommand = str_replace('$', '\$', $localUpdateCommand);
        $output = shellExec($localUpdateCommand);
        if ($output) {
            output("Failed to mysql passwords for $dbUser / $domain from remote database $output.", exitCode: 1);
        }
    }
}
