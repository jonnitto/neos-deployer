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

function resourcesUpload(string $path)
{
    resourcesLocalCompress();
    upload("Resources.tgz", "{$path}/Resources.tgz", ['timeout' => null]);
    resourcesDecompress($path);
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

function generatePassword(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $count = mb_strlen($chars);
    $length = rand(8, 12);

    for ($i = 0, $result = ''; $i < $length; $i++) {
        $index = rand(0, $count - 1);
        $result .= mb_substr($chars, $index, 1);
    }

    return $result;
}


function generateUUID(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

        // 32 bits for "time_low"
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),

        // 16 bits for "time_mid"
        mt_rand(0, 0xffff),

        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
        mt_rand(0, 0x0fff) | 0x4000,

        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
        mt_rand(0, 0x3fff) | 0x8000,

        // 48 bits for "node"
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}
