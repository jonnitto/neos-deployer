<?php

namespace Deployer;

use Deployer\Task\Context;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Helper\Table;

/**
 * Return the mysql command string to flush a database
 * This is done by dropping the database and create an new, emty one
 *
 * @param string $database The name of the database
 * @param string|null $characterSet (optional) The character set of the new database
 * @return string
 */
function dbFlushDbSql(string $database, ?string $characterSet = null): string
{
    if (!$characterSet) {
        $characterSet = getNeosNamespace() == 'TYPO3' ? 'utf8' : 'utf8mb4';
    }

    return sprintf("DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET $characterSet COLLATE {$characterSet}_unicode_ci;", $database, $database);
}

/**
 * Return the mysql command string to connect to a database
 *
 * @param string $username The username
 * @param string $password The password of the user
 * @param string $host The host to connect to the database, defaults to `localhost`
 * @param integer $port The port of the connection, defaults to `3306`
 * @return string
 */
function dbConnectCmd(
    string $username,
    string $password,
    string $host = 'localhost',
    int $port = 3306
): string {
    return sprintf('mysql --host=%s --port=%s --user=%s --password=%s', escapeshellarg($host), escapeshellarg($port), escapeshellarg($username), escapeshellarg($password));
}

/**
 * Removes the local compressed and uncompressed sql files
 *
 * @return void
 */
function dbRemoveLocalDump(): void
{
    runLocally('rm -f dump.sql.tgz dump.sql');
}

/**
 * Extract, import and delete the uploaded sql file
 *
 * @param string $path The path where the uploaded files is located
 * @param string $database The name of the database
 * @param string $username The username
 * @param string $password The password of the user
 * @param string $host The host to connect to the database, defaults to `localhost`
 * @param integer $port The port of the connection, defaults to `3306`
 * @return void
 */
function dbExtract(
    string $path,
    string $database,
    string $username,
    string $password,
    string $host = 'localhost',
    int $port = 3306
): void {
    cd($path);
    run(sprintf('tar xzOf dump.sql.tgz | %s %s', dbConnectCmd($username, $password, $host, $port), $database));
    run('rm -f dump.sql.tgz');
}

/**
 * Returns the real hostname (domain.tld)
 *
 * @return string
 */
function getRealHostname(): string
{
    return Context::get()->getHost()->getHostname();
}

/**
 * Symlinks a site to a general folder of specific domain
 *
 * @param string $subfolder A subfolder of current for the web root
 * @param string $defaultFolder The folder, where the default targets are
 * @param string|null $previewDomain Custom preview domain. If `null` it defaults to the hostname
 * @return void
 */
function symlinkDomain(string $subfolder = '', string $defaultFolder = 'html', ?string $previewDomain = null): void
{
    $realDomain = getRealHostname();
    $folderToWebRoot = parse('{{deploy_folder}}/current' . ($subfolder ? "/$subfolder" : ''));
    $defaultFolderIsSymbolicLink = !test("readlink $defaultFolder 2>/dev/null || true");
    $defaultFolderIsPresent = $defaultFolderIsSymbolicLink ? false : test("[ -d $defaultFolder ]");

    if ($defaultFolderIsSymbolicLink && run("readlink $defaultFolder") == $folderToWebRoot) {
        writebox("<strong>$realDomain</strong> is already the default domain<br>There is no need to set a domain folder.");
        return;
    }

    if (askConfirmation(" Should $realDomain set as default target on this server? ", true)) {
        if ($defaultFolderIsPresent) {
            if (test("[ -d $defaultFolder.backup ]")) {
                writebox("<strong>$defaultFolder</strong> is a folder and <strong>$defaultFolder.backup</strong> is also present<br>Please log in and check these folders.", 'red');
                return;
            }
            run("mv $defaultFolder $defaultFolder.backup");
        }
        run("rm -rf $defaultFolder");
        run("ln -s $folderToWebRoot $defaultFolder");
        writebox("<strong>$realDomain</strong> is now the default target for this server", 'green');
        return;
    } else {
        if (!$previewDomain) {
            $previewDomain = get('hostname');
        }
        // Check if the realDomain seems to have a subdomain
        $defaultDomain = substr_count($realDomain, '.') > 1 ? $realDomain : "www.{$realDomain}";
        $suggestions = [$realDomain, "www.{$realDomain}", $previewDomain];
        $folderToCreate = askDomain('Please enter the domain you want to link this site', $defaultDomain, $suggestions);
        if ($folderToCreate && $folderToCreate != 'exit') {
            run("rm -rf $folderToCreate");
            run("ln -s $folderToWebRoot $folderToCreate");
            writebox("<strong>$folderToCreate</strong> was linked to this site", 'green');
        }
    }
}

/**
 * Output a table to the console
 *
 * @param string $headline (optional) The headline above the table
 * @param array $data The data for the table
 * @return void
 */
function outputTable(?string $headline = null, array $data): void
{
    writeln('');
    writeln('');
    if ($headline) {
        writeln("<info>{$headline}</info>");
        writeln('');
    }

    $rows = [];

    foreach ($data as $key => $value) {
        $rows[] = [$key, parse($value)];
    }

    $table = new Table(output());
    $table->setRows($rows);
    $table->render();

    writeln('');
    writeln('');
}

/**
 * Ouput a box with content to the console
 *
 * @param string $content The content of the box. Content in <strong> gets printed bold, <br> / <br/> create a linebreak
 * @param string $bg The background color of the box, defaults to `blue`
 * @param string $color The text color of the box, defaults to `white`
 * @return void
 */
function writebox(string $content, string $bg = 'blue', string $color = 'white'): void
{
    // Replace strong with bold notation
    $content = str_replace('</strong>', '</>', str_replace('<strong>', "<bg={$bg};fg={$color};options=bold>", parse($content)));
    // Replace br tags with a linebreak
    $contentArray = preg_split('/(<br[^>]*>|\n)/i', $content);
    $contents = [];
    $maxLength = 0;
    foreach ($contentArray as $key => $string) {
        $length = grapheme_strlen(strip_tags($string));
        $contents[$key] = [
            'length' => $length,
            'string' => $string
        ];
        if ($length > $maxLength) {
            $maxLength = $length;
        }
    }
    $placeholder = str_repeat(' ', $maxLength);

    writeln('');
    writeln("<bg={$bg}> {$placeholder} </>");
    foreach ($contents as $array) {
        $space = str_repeat(' ', $maxLength - $array['length']);
        writeln("<bg={$bg};fg={$color}> {$array['string']}{$space} </>");
    }
    writeln("<bg={$bg}> {$placeholder} </>");
    writeln('');
}

/**
 * Removes double whitespaces and trim the string
 *
 * @param string|null $string
 * @return string|null
 */
function cleanUpWhitespaces(?string $string = null): ?string
{
    if (!$string) {
        return null;
    }
    return preg_replace('/\s+/', ' ', trim($string));
}

/**
 * Ask the user to enter a domain
 *
 * @param string $text The question who gets printed
 * @param string|null $default The optional, default value
 * @param array|null $suggestedChoices Suggested choices
 * @return string|null
 */
function askDomain(string $text, ?string $default = null, ?array $suggestedChoices = null): ?string
{
    $domain = cleanUpWhitespaces(ask(" $text ", $default, $suggestedChoices));
    if ($domain == 'exit') {
        writebox('Canceled, nothing was written', 'red');
        return 'exit';
    }
    if ($domain) {
        return $domain;
    }
    return null;
}

/**
 * Creates a backup of a file
 * The backup file get a upcounting number for different backup file. This number get also returned
 *
 * @param string $file The file who should get a backup
 * @return integer
 */
function createBackupFile(string $file): int
{
    $i = 1;
    while (test("[ -f $file.backup.$i ]")) {
        $i++;
    }
    if (test("[ -f $file ]")) {
        run("sudo cp -i $file $file.backup.$i");
    }
    return $i;
}

/**
 * Compares a backup file to the previous one and deletes it if they have the same content
 *
 * @param string $file The orignal file name
 * @param integer $i The index of the last created backup file
 * @return void
 */
function deleteDuplicateBackupFile(string $file, int $i = 1): void
{
    $indexes = [];
    while (($i > 1) && compareBackupFiles($file, $i)) {
        $indexes[] = $i;
        $i--;
    }
    $count = count($indexes);
    $indexes = join(',', $indexes);
    $indexes = $count > 1 ? '{' . $indexes . '}' : $indexes;
    if ($count) {
        // Delete all files at once
        run("sudo rm -f $file.backup.$indexes");
    }
}

/**
 * Compare to backup files
 *
 * @param string $file The orignal file name
 * @param integer $i The index of the created backup file
 * @return boolean
 */
function compareBackupFiles(string $file, int $i): bool
{
    $prevIndex = $i - 1;
    return test("diff $file.backup.$i $file.backup.$prevIndex");
}

/**
 * Neos-specific functions
 */

/**
 * Read the local database configuration of Neos and export and compress the data into a file
 *
 * @return void
 */
function dbLocalDumpNeos(): void
{
    $namespace = getNeosNamespace();
    $yaml = runLocally("./flow configuration:show --type Settings --path $namespace.Flow.persistence.backendOptions");
    $settings = Yaml::parse($yaml);
    $port = isset($settings['port']) ? $settings['port'] : '3306';
    runLocally("mysqldump -h {$settings['host']} -P {$port} -u {$settings['user']} -p{$settings['password']} {$settings['dbname']} > dump.sql");
    runLocally('tar cfz dump.sql.tgz dump.sql');
}

/**
 * Returns the namespace from Neos
 *
 * @return string
 */

function getNeosNamespace(): string
{
    if (testLocally('composer info typo3/neos -q')) {
        return 'TYPO3';
    } else {
        return 'Neos';
    }
}

/**
 * Compress the local resoures of Neos
 *
 * @return void
 */
function resourcesLocalCompressNeos(): void
{
    runLocally("COPYFILE_DISABLE=1 tar cfz Resources.tgz Data/Persistent/Resources", ['timeout' => null]);
}

/**
 * Decompress the Neos resources on the server and delete the compressed file on the server and also locally
 *
 * @param string $path
 * @return void
 */
function resourcesDecompressNeos(string $path): void
{
    cd($path);
    run("tar xf Resources.tgz", ['timeout' => null]);
    run("rm -f Resources.tgz");
    runLocally("rm -f Resources.tgz");
}

/**
 * Repairs the file permissions of the uncompressed resources of Neos
 *
 * @return void
 */
function resourcesRepairPermissionsNeos(): void
{
    cd('{{deploy_path}}/shared/Data/Persistent/Resources');
    $group = run('id -g -n');
    writeln('Setting file permissions per file, this might take a while ...');
    run("chown -R {{user}}:{$group} .");
    run('find . -type d -exec chmod 775 {} \;');
    run("find . -type f \! \( -name commit-msg -or -name '*.sh' \) -exec chmod 664 {} \;");
}

/**
 * Compress the local resources file of Neos, upload them, decompress them and repair the permissions of the resources
 *
 * @param string $path
 * @return void
 */
function resourcesUploadNeos(string $path): void
{
    resourcesLocalCompressNeos();
    upload("Resources.tgz", "{$path}/Resources.tgz", ['timeout' => null]);
    resourcesDecompressNeos($path);
    resourcesRepairPermissionsNeos();
}

/**
 * Create a local database dump, upload, import it and remove the local dump files
 *
 * @param string $path The path where the file should be uploaded
 * @param string $database The name of the database
 * @param string $username The username
 * @param string $password The password of the user
 * @param string $host The host to connect to the database, defaults to `localhost`
 * @param integer $port The port of the connection, defaults to `3306`
 * @return void
 */
function dbUploadNeos(
    string $path,
    string $database,
    string $username,
    string $password,
    string $host = 'localhost',
    int $port = 3306
): void {
    dbLocalDumpNeos();
    upload('dump.sql.tgz', "{$path}/dump.sql.tgz");
    dbExtract($path, $database, $username, $password, $host, $port);
    dbRemoveLocalDump();
}

/**
 * Proserver-specific functions
 */

/**
 * Upload a file to the Proserver (done with ProxyJumo)
 *
 * @param string $file The filename
 * @param string $path The location where the file should get uploaded
 * @return void
 */
function uploadProserver(string $file, string $path): void
{
    runLocally("scp -o ProxyJump=jumping@ssh-jumphost.karlsruhe.punkt.de $file proserver@{{hostname}}:{$path}", ['timeout' => null]);
}

/**
 * Returns the system which the server is currently running
 *
 * @return string|null
 */
function getRunningServerSystemProserver(): ?string
{
    if (run('ps -acx|grep httpd|wc -l | tr -d "[:space:]"') > 0) {
        return 'apache';
    } elseif (run('ps -acx|grep nginx|wc -l | tr -d "[:space:]"') > 0) {
        return 'nginx';
    }

    return null;
}
