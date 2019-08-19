<?php

namespace Deployer;

require_once 'neos.php';

set('deploy_path', '/var/www/Neos');

desc('Initialize installation on punkt.de');
task('install', [
    'install:info',
    'install:check',
    'ssh:key',
    'install:wait',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:shared',
    'deploy:writable',
    'install:get_mysql_password',
    'install:set_credentials',
    'install:settings',
    'install:create_database',
    'install:import_database',
    'install:import_resources',
    'deploy:run_migrations',
    'deploy:publish_resources',
    'deploy:symlink',
    'cleanup',
    'install:redis',
    'install:nginx',
    'deploy:unlock',
    'install:success',
    'install:output_db'
])->shallow();

task('cleanup')->onRoles('Production');
task('deploy:info')->onRoles('Production');
task('deploy:lock')->onRoles('Production');
task('deploy:prepare')->onRoles('Production');
task('deploy:publish_resources')->onRoles('Production');
task('deploy:release')->onRoles('Production');
task('deploy:run_migrations')->onRoles('Production');
task('deploy:symlink')->onRoles('Production');
task('deploy:unlock')->onRoles('Production');
task('deploy:update_code')->onRoles('Production');
task('deploy:vendors')->onRoles('Production');
task('deploy:writable')->onRoles('Production');
task('install:check')->onRoles('Production');
task('install:info')->onRoles('Production');
task('install:output_db')->onRoles('Production');
task('install:success')->onRoles('Production');
task('install:wait')->onRoles('Production');
task('ssh:key')->onRoles('Production');
task('deploy:flush_caches')->onRoles('Production');
task('slack:notify')->onRoles('Production');
task('slack:notify:success')->onRoles('Production');
task('slack:notify:failure')->onRoles('Production');

task('install:create_database')->onRoles('Installation');


task('install:get_mysql_password', function () {
    $GLOBALS['dbPassword'] = run('sudo cat /usr/local/etc/mysql-password');
})->shallow()->setPrivate()->onRoles('Installation');


task('install:set_credentials', function () {
    $stage = has('stage') ? '_{{stage}}' : '';
    set('dbName', "{{user}}_neos{$stage}");
    set('dbUser', 'root');
    set('dbPassword', $GLOBALS['dbPassword']);
})->shallow()->setPrivate()->onRoles('Production');


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
})->setPrivate()->onRoles('Production');


task('install:import_database', function () {
    if (askConfirmation(' Do you want to import your local database? ', true)) {
        dbLocalDump();
        punktDeUpload('dump.sql.tgz', get('release_path'));
        dbExtract(
            get('release_path'),
            get('dbName'),
            get('dbUser'),
            get('dbPassword')
        );
        dbRemoveLocalDump();
    }
})->setPrivate()->onRoles('Production');


task('install:import_resources', function () {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesLocalCompress();
        punktDeUpload('Resources.tgz', parse('{{deploy_path}}/shared'));
        resourcesDecompress(parse('{{deploy_path}}/shared'));
    }
})->setPrivate()->onRoles('Production');


task('install:redis', function () {
    if (!test('grep -sFq "redis_enable=\"YES\"" /etc/rc.conf')) {
        run("sudo echo 'redis_enable=\"YES\"' >> /etc/rc.conf");
        run("sudo service redis start");
    }
    if (!test('grep -sFq "/usr/local/bin/redis-cli flushall" /etc/rc.local')) {
        run("sudo echo '/usr/local/bin/redis-cli flushall' >> /etc/rc.local");
    }
})->setPrivate()->onRoles('Installation');


task('install:nginx', function () {
    run("sudo sed -i conf 's/welcome/neos/' /usr/local/etc/nginx/vhosts/ssl.conf");
    run("sudo sed -i conf 's%/var/www/neos/web%{{deploy_path}}/current/Web%' /usr/local/etc/nginx/include/neos.conf");
    run("sudo sed -i conf 's%FLOW_CONTEXT Production%FLOW_CONTEXT Production/Live%' /usr/local/etc/nginx/include/neos.conf");
    run('sudo service nginx reload');
})->setPrivate()->onRoles('Installation');


task('deploy:restart_php', function () {
    run('sudo service php-fpm reload');
})->setPrivate()->onRoles('Installation');
after('deploy:symlink', 'deploy:restart_php');


function punktDeUpload(string $file, string $path)
{
    runLocally("scp -o ProxyJump=jumping@ssh-jumphost.karlsruhe.punkt.de $file proserver@{{hostname}}:{$path}", ['timeout' => null]);
}
