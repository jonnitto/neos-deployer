<?php

namespace Deployer;

require_once 'neos.php';

set('html_path', '/var/www/virtual/{{user}}');
set('deploy_path', '/var/www/virtual/{{user}}/Neos');


desc('Initialize installation on Uberspace');
task('install', [
    'install:info',
    'install:check',
    'ssh:key',
    'install:wait',
    'install:php_settings',
    'deploy:restart_php',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:shared',
    'deploy:writable',
    'install:set_credentials',
    'install:settings',
    'install:import_database',
    'install:import_resources',
    'deploy:run_migrations',
    'deploy:publish_resources',
    'install:symlink',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'install:success',
    'install:output_db'
]);

task('install:set_credentials', function () {
    run('echo "memory_limit = 1024M" > ~/etc/php.d/memory_limit.ini');
})->shallow()->setPrivate();

task('install:set_credentials', function () {
    set('dbName', '{{user}}');
    set('dbUser', '{{user}}');
    set('dbPassword', run('grep -Po -m 1 "password=\K(\S)*" ~/.my.cnf'));
})->shallow()->setPrivate();

task('install:settings', function () {
    cd('{{release_path}}');
    run('
cat > Configuration/Settings.yaml <<__EOF__
Neos: &settings
  Imagine:
    driver: Imagick
  Flow:
    core:
      phpBinaryPathAndFilename: \'/usr/bin/php\'
      subRequestIniEntries:
        memory_limit: 2048M
    persistence:
      backendOptions:
        driver: pdo_mysql
        dbname: "{{dbName}}"
        user: "{{dbUser}}"
        password: "{{dbPassword}}"
        host: localhost

TYPO3: *settings
__EOF__
');
})->setPrivate();


task('install:import_database', function () {
    if (askConfirmation(' Do you want to import your local database? ', true)) {
        dbUpload(
            get('release_path'),
            get('dbName'),
            get('dbUser'),
            get('dbPassword')
        );
    }
})->setPrivate();


task('install:import_resources', function () {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesUpload(parse('{{deploy_path}}/shared'));
    }
})->setPrivate();


task('install:symlink', function () {
    within('{{html_path}}', function () {
        run('if [ -d html ]; then mv html html_OLD; fi');
        run('rm -rf html');
        run('ln -s {{deploy_path}}/current/Web html');
    });
})->setPrivate();


desc('Restart PHP');
task('restart:php', function () {
    run('uberspace tools restart php');
});
after('deploy:symlink', 'restart:php');



desc('Add a domain to uberspace');
task('domain:add', function () {
    $realHostname = getRealHostname();
    $currentEntry = run('uberspace web domain list');
    writebox("<strong>Add a domain to uberspace</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To cancel enter <strong>exit</strong> as answer");

    $suggestion = [$realHostname, "www.{$realHostname}"];
    $firstDomain = askDomain('Please enter the domain', $suggestion[0], $suggestion);
    if ($firstDomain == 'exit') {
        return;
    }
    $domains = [
        $firstDomain
    ];
    writeln('');
    while ($domain = askDomain('Please enter another domain or press enter to continue', null, $suggestion)) {
        if ($domain == 'exit') {
            return;
        }
        if ($domain) {
            $domains[] = $domain;
        }
        writeln('');
    }
    $outputDomains = implode("\n", $domains);
    $ip = '';
    foreach ($domains as $domain) {
        $ip = run("uberspace web domain add $domain");
    }
    writebox("<strong>Following entries are added:</strong><br><br>$outputDomains<br><br>$ip", 'green');
})->shallow();

desc('Remove a domain from uberspace');
task('domain:remove', function () {
    $currentEntry = run('uberspace web domain list');
    writebox("<strong>Remove a domain from uberspace</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To finish the setup, press enter or choose the last entry");

    $currentEntriesArray = explode(PHP_EOL, $currentEntry);
    $currentEntriesArray[] = 'Finish setup';
    $domains = [];

    while ($domain = askChoice('Please choose the domain you want to remove', $currentEntriesArray, sizeof($currentEntriesArray) - 1)) {
        if ($domain == 'Finish setup') {
            break;
        }
        $domains[] = $domain;
    }
    if (sizeof($domains)) {
        $outputDomains = implode("\n", $domains);
        foreach ($domains as $domain) {
            run("uberspace web domain del $domain");
        }
        writebox("<strong>Following entries are removed:</strong><br><br>$outputDomains", 'green');
    } else {
        writebox('<strong>No Domains are removed</strong>', 'red');
    }
})->shallow();

desc('Set the PHP version on the server');
task('php:version', [
    'php:version:get',
    'php:version:ask'
])->shallow();


task('php:version:get', function () {
    $currentVersion = [];
    preg_match('/(PHP [\d\.]+)/', run('php -v'), $currentVersion);
    $availableVersions = run('uberspace tools version list php');
    set('phpVersionCurrent', $currentVersion[0]);
    set('phpVersionList', explode(PHP_EOL, str_replace('- ', '', $availableVersions)));
})->setPrivate();


task('php:version:ask', function () {
    writebox('<strong>Set PHP version on uberspace</strong><br><br><strong>Current version:</strong><br>{{phpVersionCurrent}}');
    $version = askChoice(' Please choose the desired version ', get('phpVersionList'));
    $output = run("uberspace tools version use php $version");
    writebox($output, 'green');
})->shallow()->setPrivate();
