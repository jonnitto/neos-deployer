<?php

namespace Deployer;

require_once 'neos.php';

set('deploy_path', '/var/www/{{deploy_folder}}');

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
    'install:output_db',
    'domain:dns'
])->shallow();

$roleProserverTasks = [
    'cleanup',
    'deploy:flush_caches',
    'deploy:info',
    'deploy:lock',
    'deploy:prepare',
    'deploy:publish_resources',
    'deploy:release',
    'deploy:remove_robotstxt',
    'deploy:run_migrations',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:writable',
    'edit:settings',
    'frontend',
    'install:check',
    'install:import',
    'install:info',
    'install:output_db',
    'install:success',
    'install:wait',
    'node:migrate',
    'node:repair',
    'site:import',
    'slack:notify:failure',
    'slack:notify:success',
    'slack:notify',
    'ssh:key',
    'user:create_admin'
];
foreach ($roleProserverTasks as $task) {
    task($task)->onRoles('Proserver');
}

$roleRootTasks = [
    'install:create_database'
];
foreach ($roleRootTasks as $task) {
    task($task)->onRoles('Root');
}


desc('Create a tunnel connection via localhost with the web user');
task('tunnel:web', function () {
    writebox('To close the tunnel, enter <strong>exit</strong> in the console');
    runLocally('ssh -L 2222:127.0.0.1:22 -J jumping@ssh-jumphost.karlsruhe.punkt.de {{user}}@{{hostname}}', ['timeout' => null, 'tty' => true]);
})->onRoles('Proserver');

desc('Create a tunnel connection via localhost with the root user');
task('tunnel:root', function () {
    writebox('To close the tunnel, enter <strong>exit</strong> in the console');
    runLocally('ssh -L 2222:127.0.0.1:22 -J jumping@ssh-jumphost.karlsruhe.punkt.de {{user}}@{{hostname}}', ['timeout' => null, 'tty' => true]);
})->onRoles('Root');


task('install:set_globals', function () {
    $stage = has('stage') ? '_{{stage}}' : '';
    $GLOBALS['dbName'] = parse("{{user}}_neos{$stage}");
    $GLOBALS['dbUser'] = 'root';
    $GLOBALS['dbPassword'] = run('sudo cat /usr/local/etc/mysql-password');
})->shallow()->setPrivate()->onRoles('Root');


task('install:set_credentials', function () {
    set('dbName', $GLOBALS['dbName']);
    set('dbUser', $GLOBALS['dbUser']);
    set('dbPassword', $GLOBALS['dbPassword']);
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
})->setPrivate()->onRoles('Proserver');


task('install:import_resources', function () {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesLocalCompress();
        punktDeUpload('Resources.tgz', parse('{{deploy_path}}/shared'));
        resourcesDecompress(parse('{{deploy_path}}/shared'));
        resourcesRepairPermissions();
    }
})->setPrivate()->onRoles('Proserver');


task('install:redis', function () {
    if (!test('grep -sFq "redis_enable=\"YES\"" /etc/rc.conf')) {
        run("sudo echo 'redis_enable=\"YES\"' >> /etc/rc.conf");
        run("sudo service redis start");
    }
    if (!test('grep -sFq "/usr/local/bin/redis-cli flushall" /etc/rc.local')) {
        run("sudo echo '/usr/local/bin/redis-cli flushall' >> /etc/rc.local");
    }
})->setPrivate()->onRoles('Root');

desc('Output the IP addresses for the host');
task('domain:dns', function () {
    $ipv4 = runLocally('dig +short {{hostname}}');
    $IPv6 = run('ifconfig epair0b | grep inet | grep -v fe80 | cut -w -f3');
    outputTable(
        'Following DNS records need to be set:',
        [
            'A' => $ipv4,
            'AAAA' => $IPv6
        ]
    );
})->shallow()->onRoles('Proserver');


task('domain:ssl:domain', function () {
    $GLOBALS['domain'] = getRealHostname();
})->setPrivate()->shallow()->onRoles('Proserver');

task('domain:ssl:write', function () {
    $file = '/var/www/letsencrypt/domains.txt';
    $domainsString = cleanUpWhitespaces(run("cat $file"));
    $currentEntry = preg_replace('/\s+/', "\n", $domainsString);

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
    $newDomains = implode(" ", $domains);
    $newEntries = preg_replace('/\s+/', "\n", $newDomains);
    run("echo '$domainsString $newDomains' > /var/www/letsencrypt/domains.txt");
    writebox("<strong>Following entries are added:</strong><br><br>$newEntries", 'green');
})->setPrivate()->shallow()->onRoles('Root');

desc('Requested the SSl certificte');
task('domain:ssl:request', function () {
    run('sudo dehydrated -c');
})->onRoles('Root');

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
})->setPrivate()->shallow()->onRoles('Proserver');

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
})->setPrivate()->shallow()->onRoles('Root');

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
})->setPrivate()->onRoles('Root');


desc('Restart nginx');
task('restart:nginx', function () {
    run('sudo service nginx reload');
})->onRoles('Root');


desc('Restart PHP');
task('restart:php', function () {
    run('sudo service php-fpm reload');
})->onRoles('Root');
after('deploy:symlink', 'restart:php');


desc('Edit the cronjobs');
task('edit:cronjob', function () {
    run('sudo crontab -u root -e', ['timeout' => null, 'tty' => true]);
})->shallow()->onRoles('Root');


function punktDeUpload(string $file, string $path)
{
    runLocally("scp -o ProxyJump=jumping@ssh-jumphost.karlsruhe.punkt.de $file proserver@{{hostname}}:{$path}", ['timeout' => null]);
}
