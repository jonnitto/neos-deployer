<?php

namespace Deployer;

require_once __DIR__ . '/../general/neos.php';
require_once __DIR__ . '/../general/proserver.php';

desc('Initialize Neos installation on proserver.punkt.de');
task('install', [
    'install:info',
    'install:check_server_email',
    'install:check',
    'ssh:key',
    'install:wait',
    'install:set_server',
    'install:sendmail',
    'install:set_globals',
    'install:set_credentials',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:shared',
    'deploy:writable',
    'install:settings',
    'install:create_database',
    'install:import',
    'install:redis',
    'install:elasticsearch',
    'deploy:run_migrations',
    'deploy:publish_resources',
    'install:symlink',
    'deploy:symlink',
    'cleanup',
    'install:nginx',
    'install:apache',
    'restart:server',
    'deploy:unlock',
    'install:success',
    'install:output_db',
    'domain:dns'
])->shallow();

$roleProserverTasks = [
    'deploy:flush_caches',
    'deploy:publish_resources',
    'deploy:remove_robotstxt',
    'deploy:run_migrations',
    'edit:settings',
    'frontend',
    'install:import',
    'node:migrate',
    'node:repair',
    'site:import',
    'user:create_admin'
];
foreach ($roleProserverTasks as $task) {
    task($task)->onRoles('Proserver');
}


after('rollback:publishresources', 'restart:php');

desc('Set the symbolic link for this site');
task('install:symlink', function () {
    cd('{{html_path}}');
    symlinkDomain('Web');
})->onRoles('Proserver');


task('install:settings', function () {
    $settingsTemplate = parse(file_get_contents(__DIR__ . '/../template/proserver/neos/Settings.yaml'));
    run("echo '$settingsTemplate' > {{release_path}}/Configuration/Settings.yaml");
})->setPrivate()->onRoles('Proserver');
