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
    'deploy:run_migrations',
    'deploy:publish_resources',
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


task('install:settings', function () {
    cd('{{release_path}}');
    run('
cat > Configuration/Settings.yaml <<__EOF__
Neos: &settings
  Imagine:
    driver: Imagick
  Flow:
    core:
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
})->setPrivate()->onRoles('Proserver');


task('install:nginx', function () {
    $sslConfFile = '/usr/local/etc/nginx/vhosts/ssl.conf';
    $neosConfFile = '/usr/local/etc/nginx/include/neos.conf';

    $sslConfFileIndex = createBackupFile($sslConfFile);
    $neosConfFileIndex = createBackupFile($neosConfFile);

    run("sudo sed -i '' 's/welcome/neos/' $sslConfFile");
    run("sudo sed -i '' 's%/var/www/neos/Web%{{deploy_path}}/current/Web%' $neosConfFile");
    run("sudo sed -i '' -e '/location = \/robots\.txt {/,/}/d' $neosConfFile");
    if (!test("grep -sFq 'FLOW_CONTEXT {{flow_context}}' $neosConfFile")) {
        run("sudo sed -i '' 's%FLOW_CONTEXT Production%FLOW_CONTEXT {{flow_context}}%' $neosConfFile");
    }
    deleteDuplicateBackupFile($sslConfFile, $sslConfFileIndex);
    deleteDuplicateBackupFile($neosConfFile, $neosConfFileIndex);
})->shallow()->setPrivate()->onRoles('Root');

before('install:set_server:nginx', 'install:nginx');
