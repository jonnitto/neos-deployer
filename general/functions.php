<?php

namespace Deployer;

use Deployer\Task\Context;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Yaml\Yaml;

/**
 * Return the mysql command string to flush a database
 * This is done by dropping the database and create an new, empty one
 *
 * @param string $database The name of the database
 * @param string|null $characterSet (optional) The character set of the new database
 * @return string
 */
function dbFlushDbSql(string $database, ?string $characterSet = null): string
{
    if (!$characterSet) {
        $characterSet = 'utf8mb4';
    }

    return \sprintf('DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s_unicode_ci;', $database, $database, $characterSet, $characterSet);
}

/**
 * Rename the database by moving the tables to the newly created one
 *
 * @param string $oldDatabase
 * @param string $newDatabase
 * @return void
 */
function renameDB(string $oldDatabase, string $newDatabase): void
{
    if ($oldDatabase === $newDatabase) {
        writebox("Could not rename database, as the names <strong>($oldDatabase)</strong> are identical", 'red');
        return;
    }

    if (run("mysqlshow | grep ' $oldDatabase '|wc -l | tr -d '[:space:]'") < 1) {
        writebox("The database <strong>$oldDatabase</strong> which should be renamed does not exists", 'red');
        return;
    }

    if (run("mysqlshow | grep ' $newDatabase '|wc -l | tr -d '[:space:]'") > 0) {
        writebox("The database with the name <strong>$newDatabase</strong><br>already exists. Please check this manually.", 'red');
        return;
    }

    // Create new database
    run(\sprintf('echo %s | mysql', \escapeshellarg(dbFlushDbSql($newDatabase))));
    // Move tables
    run("mysql $oldDatabase -sNe 'show tables' | while read table; do mysql -sNe \"RENAME TABLE $oldDatabase.\$table TO $newDatabase.\$table\"; done");
    // Drop old database
    run("mysql -e \"DROP DATABASE IF EXISTS $oldDatabase\"");

    writebox("The database <strong>$oldDatabase</strong> was renamed to <strong>$newDatabase</strong>");
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
 *
 * @return void
 */
function dbExtract(
    string $path,
    string $database
): void {
    cd($path);
    run("tar xzOf dump.sql.tgz | mysql $database");
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
 * @param string|null $previewDomain Custom preview domain. If `null` it defaults to the hostname
 * @return void
 */
function symlinkDomain(?string $previewDomain = null): string
{
    $defaultFolder = 'html';
    $realDomain = getRealHostname();
    $folderToWebRoot = parse('{{deploy_folder}}/current{{web_root}}');
    $defaultFolderIsSymbolicLink = !test("readlink $defaultFolder 2>/dev/null || true");
    $defaultFolderIsPresent = $defaultFolderIsSymbolicLink ? false : test("[ -d $defaultFolder ]");

    if ($defaultFolderIsSymbolicLink && run("readlink $defaultFolder") == $folderToWebRoot) {
        writebox("<strong>$realDomain</strong> is already the default domain<br>There is no need to set a domain folder.");
        if (!askConfirmation(' Do you want to force also a custom domain? ')) {
            return 'alreadyDefault';
        }
    } elseif (askConfirmation(" Should the default target on this server be set to $realDomain?", true)) {
        if ($defaultFolderIsPresent) {
            if (test("[ -d $defaultFolder.backup ]")) {
                writebox("<strong>$defaultFolder</strong> is a folder and <strong>$defaultFolder.backup</strong> is also present<br>Please log in and check these folders.", 'red');
                return 'backupFolderPresent';
            }
            run("mv $defaultFolder $defaultFolder.backup");
        }
        run("rm -rf $defaultFolder");
        run("ln -s $folderToWebRoot $defaultFolder");
        writebox("<strong>$realDomain</strong> is now the default target for this server", 'green');
        return 'setToDefault';
    }

    if (!$previewDomain) {
        $previewDomain = get('hostname');
    }
    // Check if the realDomain seems to have a subdomain
    $wwwDomain = 'www.' . $realDomain;
    $defaultDomain = \substr_count($realDomain, '.') > 1 ? $realDomain : $wwwDomain;
    $suggestions = [$realDomain, $wwwDomain, $previewDomain];
    $folderToCreate = askDomain('Please enter the domain you want to link this site', $defaultDomain, $suggestions);
    if ($folderToCreate && $folderToCreate !== 'exit') {
        run("rm -rf $folderToCreate");
        run("ln -s $folderToWebRoot $folderToCreate");
        writebox("<strong>$folderToCreate</strong> was linked to this site", 'green');
        return 'linkedToFolder';
    }
    return 'nothingDone';
}

/**
 * Output a table to the console
 *
 * @param string|null $headline (optional) The headline above the table
 * @param array $data The data for the table
 *
 * @return void
 */
function outputTable(?string $headline, array $data): void
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
 * Output a box with content to the console
 *
 * @param string $content The content of the box. Content in <strong> gets printed bold, <br> / <br/> create a linebreak
 * @param string $bg The background color of the box, defaults to `blue`
 * @param string|null $color The text color of the box, defaults to `white`
 * @return void
 */
function writebox(string $content, string $bg = 'blue', ?string $color = null): void
{
    $colorMap = [
        'black' => 'white',
        'red' => 'white',
        'green' => 'black',
        'yellow' => 'black',
        'blue' => 'white',
        'blue' => 'white',
        'magenta' => 'white',
        'cyan' => 'black',
        'white' => 'black',
        'default' => 'white',
    ];

    if ($color === null) {
        $color = $colorMap[$bg];
    }
    // Replace strong with bold notation
    $content = \str_replace(
        ['<strong>', '</strong>'],
        ["<bg={$bg};fg={$color};options=bold>", '</>'],
        parse($content)
    );
    // Replace br tags with a linebreak
    $contentArray = \preg_split('/(<br[^>]*>|\n)/i', $content);
    $contents = [];
    $maxLength = 0;
    foreach ($contentArray as $key => $string) {
        $length = \grapheme_strlen(\strip_tags($string));
        $contents[$key] = \compact('length', 'string');
        if ($length > $maxLength) {
            $maxLength = $length;
        }
    }
    $placeholder = \str_repeat(' ', $maxLength);

    writeln('');
    writeln("<bg={$bg}> {$placeholder} </>");
    foreach ($contents as $array) {
        $space = \str_repeat(' ', $maxLength - $array['length']);
        writeln("<bg={$bg};fg={$color}> {$array['string']}{$space} </>");
    }
    writeln("<bg={$bg}> {$placeholder} </>");
    writeln('');
}


/**
 * Return the database name
 *
 * @param string $prefix
 * @return string
 */
function getDbName(string $prefix = '{{user}}_'): string
{
    $stage = has('stage') ? '_{{stage}}' : '';
    $name = has('database') ? '{{database}}' : camelCaseToSnakeCase(get('repositoryShortName')) . '_neos';

    return parse($prefix . $name . $stage);
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
    return \preg_replace('/\s+/', ' ', \trim($string));
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
    if ($domain === 'exit') {
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
 * The backup files are appended with ascending numbers for each new backup file. This number is also returned.
 *
 * @param string $file The file for which a backup should be created
 * @param bool $sudo
 *
 * @return int
 */
function createBackupFile(string $file, bool $sudo = true): int
{
    $i = 1;
    $prefixCommand = $sudo ? 'sudo ' : '';
    while (test("[ -f $file.backup.$i ]")) {
        $i++;
    }
    if (test("[ -f $file ]")) {
        run("{$prefixCommand}cp -i $file $file.backup.$i");
    }
    return $i;
}

/**
 * Compares a backup file to the previous one and deletes it if they have the same content
 *
 * @param string $file The original file name
 * @param int $i The index of the last created backup file
 *
 * @return void
 */
function deleteDuplicateBackupFile(string $file, int $i = 1, bool $sudo = true): void
{
    $indexes = [];
    $prefixCommand = $sudo ? 'sudo ' : '';
    while (($i > 1) && compareBackupFiles($file, $i)) {
        $indexes[] = $i;
        $i--;
    }
    $count = count($indexes);
    $indexes = \implode(',', $indexes);
    $indexes = $count > 1 ? '{' . $indexes . '}' : $indexes;
    if ($count) {
        // Delete all files at once
        run("{$prefixCommand}rm -f $file.backup.$indexes");
    }
}

/**
 * Compare to backup files
 *
 * @param string $file The original file name
 * @param int $i The index of the created backup file
 *
 * @return bool
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
    $yaml = runLocally('./flow configuration:show --type Settings --path Neos.Flow.persistence.backendOptions');
    $settings = Yaml::parse($yaml);
    $port = $settings['port'] ?? '3306';
    runLocally("mysqldump -h {$settings['host']} -P {$port} -u {$settings['user']} -p{$settings['password']} {$settings['dbname']} > dump.sql");
    runLocally('tar cfz dump.sql.tgz dump.sql');
}

/**
 * Get database name from config file
 *
 * @return string|null
 */
function getDbNameFromConfigFile(): ?string
{
    $neosConfigFile = parse('{{release_path}}/Configuration/Settings.yaml');
    if (test("[ -f $neosConfigFile ]")) {
        $settings = Yaml::parse(run("cat $neosConfigFile"));
        return $settings['Neos']['Flow']['persistence']['backendOptions']['dbname'];
    }
    return null;
}

/**
 * Write new database name into the config file
 *
 * @param string $newDatabaseName
 * @return void
 */
function writeNewDbNameInConfigFile(string $newDatabaseName): void
{
    $neosConfigFile = parse('{{release_path}}/Configuration/Settings.yaml');
    if (test("[ -f $neosConfigFile ]")) {
        $fileContent = run("cat $neosConfigFile");
        $settings = Yaml::parse($fileContent);
        if (isset($settings['Neos']['Flow']['persistence']['backendOptions']['dbname'])) {
            $settings['Neos']['Flow']['persistence']['backendOptions']['dbname'] = $newDatabaseName;
            $newFileContent = Yaml::dump($settings);
            if ($fileContent !== $newFileContent) {
                $fileIndex = createBackupFile($neosConfigFile, false);
                run(\sprintf('echo %s > %s', \escapeshellarg($newFileContent), $neosConfigFile));
                deleteDuplicateBackupFile($neosConfigFile, $fileIndex, false);
            }
        }
    }
}

/**
 * Convert a string to snake_case
 *
 * @param string $input
 * @return string
 */
function camelCaseToSnakeCase(string $input): string
{
    return \strtolower(\preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
}

/**
 * Compress the local resources of Neos
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
    $group = run('id -g -n');
    cd('{{release_path}}/Data/Persistent/Resources');
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
    upload("Resources.tgz", $path . '/Resources.tgz', ['timeout' => null]);
    resourcesDecompressNeos($path);
    resourcesRepairPermissionsNeos();
}

/**
 * Create a local database dump, upload, import it and remove the local dump files
 *
 * @param string $path The path where the file should be uploaded
 * @param string $database The name of the database
 *
 * @return void
 */
function dbUploadNeos(
    string $path,
    string $database
): void {
    dbLocalDumpNeos();
    upload('dump.sql.tgz', $path . '/dump.sql.tgz', ['timeout' => null]);
    dbExtract($path, $database);
    dbRemoveLocalDump();
}

/**
 * Proserver-specific functions
 */

/**
 * Upload a file to the Proserver (done with ProxyJump)
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
    }

    if (run('ps -acx|grep nginx|wc -l | tr -d "[:space:]"') > 0) {
        return 'nginx';
    }

    return null;
}
