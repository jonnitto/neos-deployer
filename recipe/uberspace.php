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
})->setPrivate();
after('deploy:symlink', 'restart:php');
