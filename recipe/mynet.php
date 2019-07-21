<?php

namespace Deployer;

require_once 'neos.php';

set('html_path', '/web/{{user}}');
set('deploy_path', '/web/{{user}}/Neos');


desc('Initialize installation on myNET');
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
    'install:output_db',
    'install:output_oauth'
]);

task('install:set_credentials', function () {
    $availableDB = array_map(function ($i) {
        return parse("{{user}}db{$i}");
    }, range(1, 9));
    $phpVersion = askChoice(' Please choose the version of PHP ', ['7.0', '7.1', '7.2', '7.3'], 1);
    set('phpVersion', str_replace('.', '', $phpVersion));
    set('dbUser', '{{user}}');
    set('dbName', askChoice(' Please choose the name of the database ', $availableDB, 0));
    set('dbPassword', ask(' Please enter the password for the database user '));
    set('dbHost', ask(' Please enter the host for the database connection ', 'web5-db.mynet.at'));
    set('authId', ask(' Please enter the id for the oAuth login ', generateUUID()));
    set('authSecret', ask(' Please enter the secrect for the oAuth login ', generateUUID()));
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
      subRequestPhpIniPathAndFilename: /etc/opt/mynet-php{{phpVersion}}/php.ini
      subRequestIniEntries:
        memory_limit: 2048M
    persistence:
      backendOptions:
        driver: pdo_mysql
        dbname: "{{dbName}}"
        user: "{{dbUser}}"
        password: "{{dbPassword}}"
        host: "{{dbHost}}"

TYPO3: *settings

GesagtGetan:
  OAuth2Client:
    clientId: {{authId}}
    clientSecret: {{authSecret}}
__EOF__
');
})->setPrivate();


task('install:import_database', function () {
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


task('install:import_resources', function () {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesUpload(parse('{{deploy_path}}/shared'));
    }
})->setPrivate();


task('install:symlink', function () {
    within('{{html_path}}', function () {
        run('if [ -d web ]; then mv web web_OLD; fi');
        run('rm -rf web');
        run('ln -s {{deploy_path}}/current/Web web');
    });
})->setPrivate();
