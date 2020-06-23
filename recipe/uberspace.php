<?php

namespace Deployer;

require_once __DIR__ . '/../general/neos.php';

set('html_path', '/var/www/virtual/{{user}}');
set('deploy_path', '/var/www/virtual/{{user}}/{{deploy_folder}}');


desc('Initialize installation on Uberspace');
task('install', [
    'deploy:prepare',
    'deploy:lock',
    'install:info',
    'install:check',
    'ssh:key',
    'install:wait',
    'php:version',
    'install:php_settings',
    'restart:php',
    'install:set_credentials',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:shared',
    'deploy:writable',
    'install:settings',
    'install:import',
    'deploy:run_migrations',
    'deploy:publish_resources',
    'install:symlink',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'install:success',
    'install:output_db'
]);


after('rollback:publishresources', 'restart:php');


task('install:php_settings', static function (): void {
    run('echo "memory_limit = 1024M" > ~/etc/php.d/memory_limit.ini');
})->shallow()->setPrivate();


task('install:set_credentials', static function (): void {
    set('dbName', getDbName());
    set('dbUser', '{{user}}');
    set('dbPassword', run('grep -Po -m 1 "password=\K(\S)*" ~/.my.cnf'));

    if (get('dbName') !== get('user')) {
        // We need to create the db
        run('mysql -e "CREATE DATABASE {{dbName}}"');
    }
})->shallow()->setPrivate();


task('install:settings', static function (): void {
    $settingsTemplate = parse(\file_get_contents(__DIR__ . '/../template/uberspace/neos/Settings.yaml'));
    run("echo '$settingsTemplate' > {{release_path}}/Configuration/Settings.yaml");
})->setPrivate();


task('install:import:database', static function (): void {
    if (askConfirmation(' Do you want to import your local database? ', true)) {
        dbUploadNeos(get('release_path'), get('dbName'));
    }
})->setPrivate();


task('install:import:resources', static function (): void {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesUploadNeos(parse('{{deploy_path}}/shared'));
    }
})->setPrivate();


desc('Set the symbolic link for this site');
task('install:symlink', static function (): void {
    cd('{{html_path}}');
    $action = symlinkDomain(parse('{{user}}.uber.space'));
    if ($action === 'setToDefault') {
        invoke('restart:php');
    }
});


desc('Restart PHP');
task('restart:php', static function (): void {
    run('uberspace tools restart php');
});
after('deploy:symlink', 'restart:php');


task('install:update:database', static function (): void {
    $oldDatabase = getDbNameFromConfigFile();
    $newDatabase = getDbName();
    renameDB($oldDatabase, $newDatabase);
    writeNewDbNameInConfigFile($newDatabase);
})->setPrivate();

after('install:update', 'install:update:database');
after('install:update:database', 'restart:php');


desc('Add a domain to uberspace');
task('domain:add', static function (): void {
    writebox('Get list of domains, please wait...', 'black');
    $currentEntry = run('uberspace web domain list');
    $realHostname = getRealHostname();
    writebox("<strong>Add a domain to uberspace</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To cancel enter <strong>exit</strong> as answer");
    // Check if the realHostname seems to have a subdomain
    $wwwDomain = 'www.' . $realHostname;
    $defaultDomain = \substr_count($realHostname, '.') > 1 ? $realHostname : $wwwDomain;
    $suggestions = [$realHostname, $wwwDomain];
    $firstDomain = askDomain('Please enter the domain', $defaultDomain, $suggestions);
    if ($firstDomain === 'exit') {
        return;
    }
    $domains = [
        $firstDomain
    ];
    writeln('');
    while ($domain = askDomain('Please enter another domain or press enter to continue', null, $suggestions)) {
        if ($domain === 'exit') {
            return;
        }
        if ($domain) {
            $domains[] = $domain;
        }
        writeln('');
    }
    $outputDomains = \implode("\n", $domains);
    $ip = '';
    writebox('Adding domains, please wait...', 'black');
    foreach ($domains as $domain) {
        $ip = run("uberspace web domain add $domain");
    }
    writebox("<strong>Following entries are added:</strong><br><br>$outputDomains<br><br>$ip", 'green');
})->shallow();

desc('Remove a domain from uberspace');
task('domain:remove', static function (): void {
    writebox('Get list of domains, please wait...', 'black');
    $currentEntry = run('uberspace web domain list');
    writebox("<strong>Remove a domain from uberspace</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To finish the setup, press enter or choose the last entry");

    $currentEntriesArray = \explode(\PHP_EOL, $currentEntry);
    $currentEntriesArray[] = 'Finish setup';
    $domains = [];

    while ($domain = askChoice('Please choose the domain you want to remove', $currentEntriesArray, sizeof($currentEntriesArray) - 1)) {
        if ($domain === 'Finish setup') {
            break;
        }
        $domains[] = $domain;
    }
    if (sizeof($domains)) {
        $outputDomains = \implode("\n", $domains);
        writebox('Removing domains, please wait...', 'black');
        foreach ($domains as $domain) {
            run("uberspace web domain del $domain");
        }
        writebox('<strong>Following entries are removed:</strong><br><br>' . $outputDomains, 'green');
    } else {
        writebox('<strong>No Domains are removed</strong>', 'red');
    }
})->shallow();


desc('List all domains and sub-domains');
task('domain:list', static function (): void {
    writebox('Get list of domains, please wait...', 'black');
    $currentEntry = run('uberspace web domain list');
    writebox("<strong>Registered domains on the uberspace account \"{{user}}\"</strong>

$currentEntry
");
});


desc('Set the PHP version on the server');
task('php:version', [
    'php:version:get',
    'php:version:ask'
])->shallow();


task('php:version:get', static function (): void {
    $currentVersion = [];
    \preg_match('/(PHP [\d\.]+)/', run('php -v'), $currentVersion);
    $availableVersions = run('uberspace tools version list php');
    set('phpVersionCurrent', $currentVersion[0]);
    set('phpVersionList', \explode(\PHP_EOL, \str_replace('- ', '', $availableVersions)));
})->setPrivate();


task('php:version:ask', static function (): void {
    writebox('<strong>Set PHP version on uberspace</strong><br><br><strong>Current version:</strong><br>{{phpVersionCurrent}}');
    $version = askChoice(' Please choose the desired version ', get('phpVersionList'));
    $output = run("uberspace tools version use php $version");
    writebox($output, 'green');
})->shallow()->setPrivate();


desc('Edit the cronjobs');
task('edit:cronjob', static function (): void {
    run('crontab -e', ['timeout' => null, 'tty' => true]);
})->shallow();
