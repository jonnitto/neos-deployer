<?php

namespace Deployer;

use Deployer\Task\Context;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Helper\Table;

function dbFlushDbSql(string $database): string
{
    return sprintf('DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $database, $database);
}

function dbConnectCmd(
    string $username,
    string $password,
    ?string $host = 'localhost',
    int $port = 3306
): string {
    return sprintf('mysql --host=%s --port=%s --user=%s --password=%s', escapeshellarg($host), escapeshellarg($port), escapeshellarg($username), escapeshellarg($password));
}

function dbLocalDump()
{
    $yaml = runLocally('./flow configuration:show --type Settings --path Neos.Flow.persistence.backendOptions');
    $settings = Yaml::parse($yaml);
    $port = isset($settings['port']) ? $settings['port'] : '3306';
    runLocally("mysqldump -h {$settings['host']} -P {$port} -u {$settings['user']} -p{$settings['password']} {$settings['dbname']} > dump.sql");
    runLocally('tar cfz dump.sql.tgz dump.sql');
}

function dbRemoveLocalDump()
{
    runLocally('rm -f dump.sql.tgz dump.sql');
}

function dbExtract(
    string $path,
    string $database,
    string $username,
    string $password,
    ?string $host = 'localhost',
    int $port = 3306
) {
    cd($path);
    run(sprintf('tar xzOf dump.sql.tgz | %s %s', dbConnectCmd($username, $password, $host, $port), $database));
    run('rm -f dump.sql.tgz');
}

function dbUpload(
    string $path,
    string $database,
    string $username,
    string $password,
    ?string $host = 'localhost',
    int $port = 3306
) {
    dbLocalDump();
    upload('dump.sql.tgz', "{$path}/dump.sql.tgz");
    dbExtract($path, $database, $username, $password, $host, $port);
    dbRemoveLocalDump();
}

function resourcesLocalCompress()
{
    runLocally("COPYFILE_DISABLE=1 tar cfz Resources.tgz Data/Persistent/Resources", ['timeout' => null]);
}

function resourcesDecompress(string $path)
{
    cd($path);
    run("tar xf Resources.tgz", ['timeout' => null]);
    run("rm -f Resources.tgz");
    runLocally("rm -f Resources.tgz");
}

function resourcesRepairPermissions()
{
    cd('{{deploy_path}}/shared/Data/Persistent/Resources');
    $group = run('id -g -n');
    writeln('Setting file permissions per file, this might take a while ...');
    run("chown -R {{user}}:{$group} .");
    run('find . -type d -exec chmod 775 {} \;');
    run("find . -type f \! \( -name commit-msg -or -name '*.sh' \) -exec chmod 664 {} \;");
}

function resourcesUpload(string $path)
{
    resourcesLocalCompress();
    upload("Resources.tgz", "{$path}/Resources.tgz", ['timeout' => null]);
    resourcesDecompress($path);
    resourcesRepairPermissions();
}

function getRealHostname(): string
{
    return Context::get()->getHost()->getHostname();
}

function outputTable(?string $headline, array $data)
{
    writeln("");
    writeln("");
    if ($headline) {
        writeln("<info>{$headline}</info>");
        writeln("");
    }

    $rows = [];

    foreach ($data as $key => $value) {
        $rows[] = [$key, parse($value)];
    }

    $table = new Table(output());
    $table->setRows($rows);
    $table->render();

    writeln("");
    writeln("");
}

function writebox($content, $bg = 'blue', $color = "white")
{
    // Replace strong with bold notation
    $content = str_replace('</strong>', '</>', str_replace('<strong>', "<bg={$bg};fg={$color};options=bold>", parse($content)));
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
    writeln("<bg={$bg}>    {$placeholder}    </>");
    foreach ($contents as $array) {
        $space = str_repeat(' ', $maxLength - $array['length']);
        writeln("<bg={$bg};fg={$color}>    {$array['string']}{$space}    </>");
    }
    writeln("<bg={$bg}>    {$placeholder}    </>");
    writeln('');
}

function cleanUpWhitespaces($string)
{
    if (!$string) {
        return null;
    }
    return preg_replace('/\s+/', ' ', trim($string));
}

function askDomain(string $text, $default = null, $suggestedChoices = null)
{
    $domain = cleanUpWhitespaces(ask(" $text ", $default, $suggestedChoices));
    if ($domain == 'exit') {
        writebox('Canceled, nothing was written', 'red');
        return 'exit';
    }
    if ($domain) {
        return $domain;
    }
    return false;
}
