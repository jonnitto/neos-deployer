<?php

namespace Deployer;

require_once 'neos.php';

set('deploy_path', '/var/www/Neos');

desc('Initialize installation on proserver.punkt.de');
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
    'install:set_globals',
    'install:set_credentials',
    'install:settings',
    'install:create_database',
    'install:import',
    'install:redis',
    'deploy:run_migrations',
    'deploy:publish_resources',
    'deploy:symlink',
    'cleanup',
    'install:nginx',
    'restart:nginx',
    'deploy:unlock',
    'install:success',
    'install:output_db'
])->shallow();

$roleProductionTasks = [
    'cleanup',
    'deploy:info',
    'deploy:lock',
    'deploy:prepare',
    'deploy:publish_resources',
    'deploy:release',
    'deploy:run_migrations',
    'deploy:remove_robotstxt',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:writable',
    'install:check',
    'install:info',
    'install:output_db',
    'install:success',
    'install:wait',
    'ssh:key',
    'deploy:flush_caches',
    'slack:notify',
    'slack:notify:success',
    'slack:notify:failure',
    'frontend'
];
foreach ($roleProductionTasks as $task) {
    task($task)->onRoles('Production');
}

$roleInstallationTasks = [
    'install:create_database'
];
foreach ($roleInstallationTasks as $task) {
    task($task)->onRoles('Installation');
}


desc('Create a tunnel connection via localhost with the web user');
task('tunnel:web', function () {
    writebox('To close the tunnel, enter <strong>exit</strong> in the console');
    runLocally('ssh -L 2222:127.0.0.1:22 -J jumping@ssh-jumphost.karlsruhe.punkt.de {{user}}@{{hostname}}', ['timeout' => null, 'tty' => true]);
})->onRoles('Production');

desc('Create a tunnel connection via localhost with the root user');
task('tunnel:root', function () {
    writebox('To close the tunnel, enter <strong>exit</strong> in the console');
    runLocally('ssh -L 2222:127.0.0.1:22 -J jumping@ssh-jumphost.karlsruhe.punkt.de {{user}}@{{hostname}}', ['timeout' => null, 'tty' => true]);
})->onRoles('Installation');


task('install:set_globals', function () {
    $stage = has('stage') ? '_{{stage}}' : '';
    $GLOBALS['dbName'] = parse("{{user}}_neos{$stage}");
    $GLOBALS['dbUser'] = 'root';
    $GLOBALS['dbPassword'] = run('sudo cat /usr/local/etc/mysql-password');
})->shallow()->setPrivate()->onRoles('Installation');


task('install:set_credentials', function () {
    set('dbName', $GLOBALS['dbName']);
    set('dbUser', $GLOBALS['dbUser']);
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
        resourcesRepairPermissions();
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


task('domain:ssl:domain', function () {
    $GLOBALS['domain'] = getRealHostname();
})->setPrivate()->shallow()->onRoles('Production');

task('domain:ssl:write', function () {
    $file = '/var/www/letsencrypt/domains.txt';
    $currentEntry = run("cat $file");

    writebox("<strong>Add Let's Encrypt SSL certificte</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To cancel enter <strong>exit</strong> as answer");

    $firstDomain = askDomain('Please enter the domain', "{$GLOBALS['domain']} www.{$GLOBALS['domain']}");
    if ($firstDomain == 'exit') {
        return;
    }
    $domains = [
        $firstDomain
    ];
    writeln('');
    while ($domain = askDomain('Please enter another domain or press enter to continue')) {
        if ($domain == 'exit') {
            return;
        }
        if ($domain) {
            $domains[] = $domain;
        }
        writeln('');
    }
    $sslDomains = implode("\n", $domains);
    run("echo '$sslDomains' >> /var/www/letsencrypt/domains.txt");
    writebox("<strong>Following entries are added:</strong><br><br>$sslDomains", 'green');
})->setPrivate()->shallow()->onRoles('Installation');

desc('Requested the SSl certificte');
task('domain:ssl:request', function () {
    run('sudo dehydrated -c');
})->onRoles('Installation');

desc("Add Let's Encrypt SSL certificte");
task('domain:ssl', [
    'domain:ssl:domain',
    'domain:ssl:write',
    'domain:ssl:request'
])->shallow();

task('domain:force:ask', function () {
    if (askConfirmation(' Do you want to force a specific domain? ', true)) {
        $realHostname = getRealHostname();
        $GLOBALS['domain'] = askDomain('Please enter the domain', "www.{$realHostname}", ["www.{$realHostname}", $realHostname]);
    }
})->setPrivate()->shallow()->onRoles('Production');

task('domain:force:write', function () {
    if (!isset($GLOBALS['domain']) || $GLOBALS['domain'] == 'exit') {
        return;
    }

    $confFile = '/usr/local/etc/nginx/vhosts/ssl.conf';

    // Count entries. If there are more than two entries we just overwrite the domain
    $numberOfEntries = intval(str_replace(' ', '', run("cat $confFile | grep -c 'server {'")));
    if ($numberOfEntries > 2) {
        writebox("There is already a domain defined. I'll overwrite the domain");
        $fileContent = run("cat $confFile");
        // Replace redirections
        $fileContent = preg_replace('/^([ ]*return \d{3}) https:\/\/(.+)\$request_uri;$/m', "$1 https://{$GLOBALS['domain']}\$request_uri;", $fileContent);
        // Replace server names
        $fileContent = preg_replace('/^([ ]*server_name) ((?!\.proserver\.punkt\.de).)*$/m', "$1 {$GLOBALS['domain']};", $fileContent);
        // Overwrite the file
        run("echo '{$fileContent}' > {$confFile}");
        return;
    }

    $redirectString = "  location / {\n    return 301 https://{$GLOBALS['domain']}\$request_uri;\n  }";
    $firstLinenummerSecondEntry = run("cat $confFile | grep -n 'server {' | cut -d: -f 1 | tail -1");
    $lastLinenummerFirstEntry = intval($firstLinenummerSecondEntry) - 1;

    $firstEntry = run("head -{$lastLinenummerFirstEntry} $confFile");
    $secondEntry = run("tail -{$firstLinenummerSecondEntry} $confFile");

    // Genereate the 3 server sections
    $httpRedirectEntry = str_replace('$host', $GLOBALS['domain'], $firstEntry);
    $httpsRedirectEntry = preg_replace('/^\s*include\s(.)+$\n/m', '', $secondEntry);
    $httpsRedirectEntry = str_replace('}', "\n$redirectString\n}", $httpsRedirectEntry);
    $httpsEntry = str_replace(' default_server', '', $secondEntry);
    $httpsEntry = preg_replace('/server_name (.)+$/m', "server_name {$GLOBALS['domain']};", $httpsEntry);

    // Overwrite the file
    $fileContent = "{$httpRedirectEntry}\n{$httpsRedirectEntry}\n{$httpsEntry}";
    run("echo '{$fileContent}' > {$confFile}");
})->setPrivate()->shallow()->onRoles('Installation');

desc('Configure the server to force a specific domain');
task('domain:force', [
    'domain:force:ask',
    'domain:force:write',
    'restart:nginx'
])->shallow();


task('install:nginx', function () {
    $neosConfFile = '/usr/local/etc/nginx/include/neos.conf';
    run("sudo sed -i conf 's/welcome/neos/' /usr/local/etc/nginx/vhosts/ssl.conf");
    run("sudo sed -i conf 's%/var/www/neos/Web%{{deploy_path}}/current/Web%' $neosConfFile");
    if (!test("grep -sFq 'FLOW_CONTEXT {{flow_context}}' $neosConfFile")) {
        run("sudo sed -i conf 's%FLOW_CONTEXT Production%FLOW_CONTEXT {{flow_context}}%' $neosConfFile");
    }
})->setPrivate()->onRoles('Installation');


desc('Restart nginx');
task('restart:nginx', function () {
    run('sudo service nginx reload');
})->onRoles('Installation');


desc('Restart PHP');
task('restart:php', function () {
    run('sudo service php-fpm reload');
})->onRoles('Installation');
after('deploy:symlink', 'restart:php');


function punktDeUpload(string $file, string $path)
{
    runLocally("scp -o ProxyJump=jumping@ssh-jumphost.karlsruhe.punkt.de $file proserver@{{hostname}}:{$path}", ['timeout' => null]);
}
