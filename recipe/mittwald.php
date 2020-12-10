<?php
declare(strict_types=1);

namespace Deployer;

require_once 'neos.php';

set('html_folder', 'neos');
set('html_path', '/home/www/{{user}}/html');
set('deploy_path', '/home/www/{{user}}/files/{{deploy_folder}}');


desc('Initialize installation on Mittwald server');
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

// no /dev/zero and no ssh-keyscan command available on Mittwald
task('ssh:key', static function () {
    $hasKey = test('[ -f ~/.ssh/id_rsa.pub ]');
    if (!$hasKey) {
        run('ssh-keygen -q -N "" -t rsa -b 4096 -C "$(hostname -f)" -f ~/.ssh/id_rsa');
    }
    $pub = run('cat ~/.ssh/id_rsa.pub');
    writebox('Your id_rsa.pub key is:');
    writeln("<info>$pub</info>");
    writeln('');
    $repository = preg_replace('/.*@([^:]*).*/', '$1', get('repository'));
    if ($repository) {
        writeln("Make sure the host key for $repository is accepted, e.g. by trying to connect once manually!");
    }
})->shallow();


task('install:settings', static function () {
    cd('{{release_path}}');
    run('
cat > Configuration/Settings.yaml <<__EOF__
Neos:
  Imagine:
    driver: Imagick
  Flow:
    core:
      phpBinaryPathAndFilename: \'/usr/local/bin/php\'
      subRequestIniEntries:
        memory_limit: 2048M
    persistence:
      backendOptions:
        driver: pdo_mysql
        host: "{{dbHost}}"
        dbname: "{{dbName}}"
        user: "{{dbUser}}"
        password: "{{dbPassword}}"
    resource:
      targets:
        localWebDirectoryPersistentResourcesTarget:
          targetOptions:
            relativeSymlinks: true
        localWebDirectoryStaticResourcesTarget:
          targetOptions:
            relativeSymlinks: true
__EOF__
');
})->setPrivate();


task('install:import_database', static function () {
    if (askConfirmation(' Do you want to import your local database? ', true)) {
        dbUpload(
            get('release_path'),
            get('dbName'),
            get('dbUser'),
            get('dbPassword'),
            get('dbHost')
        );
    }
})->setPrivate();


task('install:import_resources', static function () {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesUpload(parse('{{deploy_path}}/shared'));
    }
})->setPrivate();


task('install:symlink', static function () {
    within('{{html_path}}', static function () {
        run('if [ -d {{html_folder}} ]; then mv {{html_folder}} {{html_folder}}_OLD; fi');
        run('rm -f {{html_folder}}');
        run('ln -s {{deploy_path}}/current {{html_folder}}');
    });
})->setPrivate();


desc('Edit the cronjobs');
task('edit:cronjob', static function () {
    run('crontab -e', ['timeout' => null, 'tty' => true]);
})->shallow();
