<?php
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.inc.php';  // Include common functions like readConfig, parseJson, executeRemoteSSHCommand, and sshCopyId

checkPhpVersion();

// Ensure SSH key-based authentication is set up
sshCopyId();  // This will check if SSH keys are already set up and run ssh-copy-id if not

$websites = getRemoteWebsites();
if (!$websites) {
    output("No websites found.", exitCode: 1);
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
    $remoteRootDbCredentials = getRemoteDatabaseRootCredentials();
    $localRootDbCredentials = getLocalDatabaseRootCredentials();

    $query = "SELECT databases_databases.dbName, databases_databases.dbUser FROM databases_databases
              INNER JOIN websiteFunctions_websites ON websiteFunctions_websites.id = databases_databases.website_id
              WHERE websiteFunctions_websites.domain = '" . addslashes($domain) . "'";

    // Execute remote SQL query with clear error handling
    $remoteData = queryRemoteSql($query);

    if ($remoteData === false) {
        output("Remote SQL query execution failed for domain: $domain. Verify MySQL credentials, remote access, and database structure.", exitCode: 1);
        return;
    }

    if (empty($remoteData)) {
        output("No remote databases found for domain: $domain. Skipping database migration for this domain.", success: false);
        return;
    }

    output("Remote databases found (" . count($remoteData) . "): " . implode(', ', array_column($remoteData, 'dbName')));

    // Fetch existing local databases for comparison
    $localDatabases = queryLocalSql($query);
    if ($localDatabases === false) {
        output("Local SQL query execution failed. Check local MySQL credentials and database schema.", exitCode: 1);
        return;
    }

    if (!is_dir('mysqldumps')) {
        mkdir('mysqldumps');
    }

    foreach ($remoteData as $row) {
        $dbName = trim($row['dbName']);
        $dbUser = trim($row['dbUser']);

        if (array_search($dbName, array_column($localDatabases, 'dbName')) !== false) {
            output("Database `$dbName` already exists locally for `$domain`. Skipping its creation.");
            continue;
        }

        output("Creating local database `$dbName` for `$domain`...");

        $randomPassword = bin2hex(random_bytes(6));
        $command = "cyberpanel createDatabase --databaseWebsite $domain --dbName $dbName --dbUsername $dbUser --dbPassword '$randomPassword'";

        $output = shellExec($command);
        $result = json_decode($output, true);

        if (!$result || !$result['success']) {
            output("Failed to create database `$dbName`. CyberPanel output: $output", exitCode: 1);
        }

        if ($dbUser !== 'admin') {
            output("Updating MySQL password for user `$dbUser`...");

	    $passwordQuery = "SELECT IFNULL(authentication_string, password) AS password FROM mysql.user WHERE User = '" . addslashes($dbUser) . "'";
            $passwordResult = queryRemoteSql($passwordQuery, rootUser: true);

	if ($passwordResult === false || empty($passwordResult)) {
	    output("Failed to retrieve MySQL password for user `$dbUser` from remote server. Query used: $passwordQuery", exitCode: 1);
	}

            $password = $passwordResult[0]['password'];

	    if (!isset($passwordResult[0]['password'])) {
		    output("Password hash not returned by query. Full result: " . print_r($passwordResult, true), exitCode: 1);
            }

            $sql = "ALTER USER '" . addslashes($dbUser) . "'@'localhost' IDENTIFIED BY PASSWORD '" . addslashes($password) . "'";
            $updateResult = execLocalSql($sql, rootUser: true);

            if ($updateResult) {
                output("Failed to update local password for MySQL user `$dbUser`: $updateResult", exitCode: 1);
            } else {
                output("Successfully updated local MySQL password for user `$dbUser`.", success: true);
            }
        }

        $dumpFileName = "mysqldumps/$dbName-$dbUser.sql";
        output("Exporting remote MySQL database `$dbName`...");

	$remoteDumpCommand = "mysqldump -u{$remoteRootDbCredentials['user']} -p{$remoteRootDbCredentials['password']} " . escapeshellarg($dbName) . " | gzip";
	$dumpOutput = executeRemoteSSHCommand($remoteDumpCommand, "$dumpFileName.gz");

	shellExec("gunzip -f " . escapeshellarg("$dumpFileName.gz"));

	if (!file_exists($dumpFileName) || filesize($dumpFileName) < 100) {
	    output("Error during remote database dump for `$dbName` (gzipped): Dump file is missing or too small.", exitCode: 1);
	}

	$sedSanitize = "sed -i '\$s/Connection to .* closed\\.//' " . escapeshellarg($dumpFileName);
	shellExec($sedSanitize);


        if ($dumpOutput !== null && trim($dumpOutput) !== '') {
            output("Error during remote database dump for `$dbName`: $dumpOutput", exitCode: 1);
        }

        output("Importing database dump `$dbName` locally...");
        $localImportCommand = "mysql -u{$localRootDbCredentials['user']} -p{$localRootDbCredentials['password']} " . escapeshellarg($dbName) . " < $dumpFileName";
        $localImportCommand = str_replace('$', '\\$', $localImportCommand);
        $importOutput = shellExec($localImportCommand);

        if ($importOutput) {
            output("Failed to import database `$dbName` locally. Error: $importOutput", exitCode: 1);
        } else {
            output("Successfully imported database `$dbName` locally.", success: true);
	    unlink($dumpFileName);
            output("Deleted dump file `$dumpFileName` after successful import.");

        }
    }
}
