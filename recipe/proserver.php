<?php

namespace Deployer;

require_once __DIR__ . '/../general/neos.php';

set('html_path', '/var/www');
set('deploy_path', '/var/www/{{deploy_folder}}');
set('server', 'nginx');
set('runningServer', static function (): ?string {
    return getRunningServerSystemProserver();
});


desc('Create a tunnel connection via localhost');
task('tunnel', static function (): void {
    $port = '22';
    $forward = '2222';
    $type = askChoice(' Choose your type of connection ', ['SFTP', 'MySQL']);
    if ($type === 'MySQL') {
        $port = '3306';
        $forward = '3333';
    }
    writebox("The port <strong>$forward</strong> is now forwared to <strong>127.0.0.1</strong> with the port <strong>$port</strong> for a <strong>$type</strong> connection.<br>To close the tunnel, enter <strong>exit</strong> in the console");
    // https://github.com/deployphp/deployer/issues/1891
    runLocally("ssh -L $forward:127.0.0.1:$port -J jumping@ssh-jumphost.karlsruhe.punkt.de {{user}}@{{hostname}}", ['timeout' => null, 'tty' => true]);
})->onRoles('Proserver');


task('install:set_globals', static function (): void {
    $GLOBALS['dbName'] = getDbName();
    $GLOBALS['dbUser'] = 'root';
    $GLOBALS['dbPassword'] = run('sudo cat /usr/local/etc/mysql-password');
})->shallow()->setPrivate()->onRoles('Root');


task('install:set_credentials', static function (): void {
    set('dbName', $GLOBALS['dbName']);
    set('dbUser', $GLOBALS['dbUser']);
    set('dbPassword', $GLOBALS['dbPassword']);
})->shallow()->setPrivate();


desc('Check if a server email address is set');
task('install:check_server_email', function () {
    if (!has('serverEmail')) {
        writebox('<strong>The variable serverEmail is not set</strong><br>Please add it to you deploy.yaml and start the installation again.', 'red');
        exit;
    }
})->shallow()->setPrivate()->onRoles('Proserver');


desc('Import your local database and persistent resources to the server');
task('install:import', [
    'install:set_globals',
    'install:set_credentials',
    'install:import:database',
    'install:import:resources'
]);


task('install:import:database', static function (): void {
    if (askConfirmation(' Do you want to import your local database? ', true)) {
        dbLocalDumpNeos();
        uploadProserver('dump.sql.tgz', get('release_path'));
        dbExtract(get('release_path'), get('dbName'));
        dbRemoveLocalDump();
    }
})->setPrivate()->onRoles('Proserver');


task('install:import:resources', static function (): void {
    if (askConfirmation(' Do you want to import your local persistent resources? ', true)) {
        resourcesLocalCompressNeos();
        uploadProserver('Resources.tgz', parse('{{deploy_path}}/shared'));
        resourcesDecompressNeos(parse('{{deploy_path}}/shared'));
        resourcesRepairPermissionsNeos();
    }
})->setPrivate()->onRoles('Proserver');


desc('Activate Redis on the server');
task('install:redis', static function (): void {
    $rcConfFile = '/etc/rc.conf';
    $rcLocalFile = '/etc/rc.local';
    $isEnabled = test('grep -sFq \'redis_enable="YES"\' ' . $rcConfFile);

    if ($isEnabled) {
        run('sudo service redis restart');
        writebox('Redis is already activated');
        return;
    }

    if (!askConfirmation(' Should Redis be activated? ', true)) {
        return;
    }

    $rcConfFileIndex = createBackupFile($rcConfFile);
    run('sudo echo \'redis_enable="YES"\' >> ' . $rcConfFile);
    deleteDuplicateBackupFile($rcConfFile, $rcConfFileIndex);
    run('sudo service redis start');

    if (!test('grep -sFq "/usr/local/bin/redis-cli flushall" ' . $rcLocalFile)) {
        $rcLocalFileIndex = createBackupFile($rcLocalFile);
        run('sudo echo "/usr/local/bin/redis-cli flushall" >> ' . $rcLocalFile);
        deleteDuplicateBackupFile($rcLocalFile, $rcLocalFileIndex);
    }
})->onRoles('Root');


desc('Activate Elasticsearch on the server');
task('install:elasticsearch', static function (): void {
    $rcConfFile = '/etc/rc.conf';
    $isEnabled = test('grep -sFq \'elasticsearch_enable="YES"\' ' . $rcConfFile);

    if ($isEnabled) {
        run('sudo service elasticsearch restart');
        writebox('Elasticsearch is already activated');
        return;
    }

    if (!askConfirmation(' Should Elasticsearch be activated? ', true)) {
        return;
    }

    $rcConfFileIndex = createBackupFile($rcConfFile);
    run('sudo echo \'elasticsearch_enable="YES"\' >> ' . $rcConfFile);
    deleteDuplicateBackupFile($rcConfFile, $rcConfFileIndex);
    run('sudo service elasticsearch start');
})->onRoles('Root');


desc('Output the IP addresses for the host');
task('domain:dns', static function (): void {
    $ipv4 = runLocally('dig +short {{hostname}}');
    $ipv6 = run('ifconfig epair0b | grep inet | grep -v fe80 | cut -w -f3');
    outputTable(
        'Following DNS records need to be set:',
        [
            'A' => $ipv4,
            'AAAA' => $ipv6
        ]
    );
})->shallow()->onRoles('Proserver');


task('domain:ssl:domain', static function (): void {
    $GLOBALS['domain'] = getRealHostname();
})->setPrivate()->shallow()->onRoles('Proserver');

task('domain:ssl:write', static function (): void {
    $file = '/var/www/letsencrypt/domains.txt';
    $domainsString = cleanUpWhitespaces(run('cat ' . $file));
    $currentArray = \explode(' ', $domainsString);
    $currentEntry = \implode("\n", $currentArray);

    writebox("<strong>Add Let's Encrypt SSL certificate</strong>
If you have multiple domains, you will be asked
after every entry if you wand to add another domain.

<strong>Current entry:</strong>
$currentEntry

To cancel enter <strong>exit</strong> as answer");

    $firstDomain = askDomain('Please enter the domain', "{$GLOBALS['domain']} www.{$GLOBALS['domain']}");
    if ($firstDomain === 'exit') {
        return;
    }
    $domains = [
        $firstDomain
    ];

    writeln('');
    while ($domain = askDomain('Please enter another domain or press enter to continue')) {
        if ($domain === 'exit') {
            return;
        }
        if ($domain) {
            $domains[] = $domain;
        }
        writeln('');
    }

    // Make sure every domain has a single entry
    $domains = \explode(' ', cleanUpWhitespaces(\implode(' ', $domains)));

    // Remove entries which already exist and remove double entries
    $domains = \array_unique(\array_diff($domains, $currentArray));

    // Save all domains in one string
    $uniqueDomains = \implode(' ', \array_merge($currentArray, $domains));

    $fileIndex = createBackupFile($file);
    run("echo '$uniqueDomains' > $file");
    deleteDuplicateBackupFile($file, $fileIndex);
    writebox('<strong>Following entries where added:</strong><br><br>' . \implode("\n", $domains), 'green');
})->setPrivate()->shallow()->onRoles('Root');

desc('Requested the SSl certificate');
task('domain:ssl:request', static function (): void {
    run('sudo dehydrated -c');
})->onRoles('Root');

desc('Add Let\'s Encrypt SSL certificate');
task('domain:ssl', [
    'domain:ssl:domain',
    'domain:ssl:write',
    'domain:ssl:request'
])->shallow();


desc('Remove domain(s) from Let\'s Encrypt SSL certificate list');
task('domain:ssl:remove', static function (): void {
    $file = '/var/www/letsencrypt/domains.txt';
    $domainsString = cleanUpWhitespaces(run("cat $file"));
    $oldDomainArray = \explode(' ', $domainsString);
    $domainsToRemove = askChoice(' Which domains should be removed? You can select multiple domains (comma-separated) ', array_diff($oldDomainArray, [get('hostname')]), null, true);
    // Save all domains in one string
    $newDomainsEntry = \implode(' ', \array_diff($oldDomainArray, $domainsToRemove));

    // Save removed entries for output on the end
    $removedEntries = \implode("\n", $domainsToRemove);

    $fileIndex = createBackupFile($file);
    run('echo "' . $newDomainsEntry . '" > ' . $file);
    deleteDuplicateBackupFile($file, $fileIndex);
    writebox('<strong>Following entries where removed:</strong><br><br>' . $removedEntries, 'green');
})->shallow()->onRoles('Root');


task('domain:force:ask', static function (): void {
    if (askConfirmation(' Do you want to force a specific domain? ', true)) {
        $realHostname = getRealHostname();

        // Check if the realDomain seems to have a subdomain
        $wwwDomain = 'www.' . $realHostname;
        $defaultDomain = \substr_count($realHostname, '.') > 1 ? $realHostname : $wwwDomain;
        $suggestions = [$realHostname, $wwwDomain];
        $GLOBALS['domain'] = askDomain('Please enter the domain', $defaultDomain, $suggestions);
    }
})->setPrivate()->shallow()->onRoles('Proserver');

task('domain:force:write', static function (): void {
    if (!isset($GLOBALS['domain']) || $GLOBALS['domain'] === 'exit') {
        return;
    }

    $confFile = '/usr/local/etc/nginx/vhosts/ssl.conf';
    $confFileIndex = createBackupFile($confFile);

    // Count entries. If there are more than two entries we just overwrite the domain
    $numberOfEntries = (int) \str_replace(' ', '', run("cat $confFile | grep -c 'server {'"));
    if ($numberOfEntries > 2) {
        writebox('There is already a domain defined. I\'ll overwrite the domain');
        $fileContent = run('cat ' . $confFile);
        // Replace redirections
        $fileContent = \preg_replace('/^([ ]*return \d{3}) https:\/\/(.+)\$request_uri;$/m', "$1 https://{$GLOBALS['domain']}\$request_uri;", $fileContent);
        // Replace server names
        $fileContent = \preg_replace('/^([ ]*server_name) ((?!\.proserver\.punkt\.de).)*$/m', "$1 {$GLOBALS['domain']};", $fileContent);
        // Overwrite the file
        run("echo '{$fileContent}' > $confFile");
        return;
    }

    $redirectString = "  location / {\n    return 301 https://{$GLOBALS['domain']}\$request_uri;\n  }";
    $firstLineNumberSecondEntry = run("cat $confFile | grep -n 'server {' | cut -d: -f 1 | tail -1");
    $lastLineNumberFirstEntry = (int) $firstLineNumberSecondEntry - 1;

    $firstEntry = run("head -{$lastLineNumberFirstEntry} $confFile");
    $secondEntry = run("tail -{$firstLineNumberSecondEntry} $confFile");

    // Generate the 3 server sections
    $httpRedirectEntry = \str_replace('$host', $GLOBALS['domain'], $firstEntry);
    $httpsRedirectEntry = \preg_replace('/^\s*include\s(.)+$\n/m', '', $secondEntry);
    $httpsRedirectEntry = \str_replace('}', "\n$redirectString\n}", $httpsRedirectEntry);
    $httpsEntry = \str_replace(' default_server', '', $secondEntry);
    $httpsEntry = \preg_replace('/server_name (.)+$/m', "server_name {$GLOBALS['domain']};", $httpsEntry);

    // Overwrite the file
    $fileContent = "{$httpRedirectEntry}\n{$httpsRedirectEntry}\n{$httpsEntry}";
    run("echo '{$fileContent}' > $confFile");
    deleteDuplicateBackupFile($confFile, $confFileIndex);
})->setPrivate()->shallow()->onRoles('Root');

desc('Configure the server to force a specific domain');
task('domain:force', [
    'domain:force:ask',
    'domain:force:write',
    'restart:server'
])->shallow();


desc('Activate sendmail on the server');
task('install:sendmail', static function (): void {
    $rcConfFile = '/etc/rc.conf';
    $aliasesConfFile = '/etc/mail/aliases';
    // Is it already enabled?
    if (!test("grep -q '^sendmail_' $rcConfFile")) {
        writebox('Sending mails is already activated');
        return;
    }

    if (!askConfirmation(' Should the server be able to sending mails? ', true)) {
        return;
    }

    $rcConfFileIndex = createBackupFile($rcConfFile);
    run("sudo sed -i '' 's/^sendmail_/# sendmail_/' $rcConfFile");
    deleteDuplicateBackupFile($rcConfFile, $rcConfFileIndex);

    // Check root email
    if (test("grep -q '^# root:	me@my.domain' $aliasesConfFile")) {
        $aliasesConfFileIndex = createBackupFile($aliasesConfFile);
        run("sudo sed -i '' 's/^# root:	me@my.domain$/root:	{{serverEmail}}/' $aliasesConfFile");
        deleteDuplicateBackupFile($aliasesConfFile, $aliasesConfFileIndex);
    }

    // Make installation
    cd('/etc/mail');
    run('sudo make');
    run('sudo make install');
    run('sudo service sendmail start');
})->onRoles('Root');


desc('Restart server');
task('restart:server', static function (): void {
    if (get('runningServer') !== get('server')) {
        writebox(
            'deployer.yaml is configured to run with <strong>{{server}}</strong>,<br />but the server runs with <strong>{{runningServer}}</strong>',
            'red'
        );
        if (askConfirmation(' Should I switch the to the configured server? ', true)) {
            invoke('install:set_server');
        }
    }
    switch (get('runningServer')) {
        case 'apache':
            run('sudo service apache24 reload');
            break;

        case 'nginx':
            run('sudo service nginx reload');
            break;
    }
})->onRoles('Root');


task('install:update:nginx', static function (): void {
    run("sudo sed -i '' 's/neos\.conf/html\.conf/' /usr/local/etc/nginx/vhosts/ssl.conf");
})->setPrivate()->onRoles('Root');

task('install:update:database', static function (): void {
    $oldDatabase = getDbNameFromConfigFile();
    $newDatabase = $GLOBALS['dbName'];
    renameDB($oldDatabase, $newDatabase);
    writeNewDbNameInConfigFile($newDatabase);
})->setPrivate()->onRoles('Proserver');


task('install:update:proserver', [
    'install:set_globals',
    'install:set_credentials',
    'install:write_my_cnf',
    'install:update:database',
    'install:update:nginx',
    'install:nginx',
    'install:apache',
    'install:redis',
    'install:elasticsearch',
    'restart:server',
    'deploy',
])->shallow()->setPrivate();

after('install:update', 'install:update:proserver');


desc('Set server to Apache or Nginx');
task('install:set_server', static function (): void {
    $server = get('server');
    if (get('runningServer') !== $server) {
        invoke('install:set_server:' . $server);
        set('runningServer', static function (): ?string {
            return getRunningServerSystemProserver();
        });
        writebox('Set server to <strong>{{runningServer}}</strong>');
    }
})->shallow()->onRoles('Root');

task('install:set_server:apache', static function (): void {
    $configFile = '/etc/rc.conf';
    $configFileIndex = createBackupFile($configFile);
    run('sudo service nginx stop');
    run("sudo sed -i '' 's/^nginx_enable/apache24_enable/' $configFile");
    run('sudo service apache24 start');
    deleteDuplicateBackupFile($configFile, $configFileIndex);
})->shallow()->setPrivate()->onRoles('Root');

task('install:set_server:nginx', static function (): void {
    $configFile = '/etc/rc.conf';
    $configFileIndex = createBackupFile($configFile);
    run('sudo service apache24 stop');
    run("sudo sed -i '' 's/^apache24_enable/nginx_enable/' $configFile");
    run('sudo service nginx start');
    deleteDuplicateBackupFile($configFile, $configFileIndex);
})->shallow()->setPrivate()->onRoles('Root');


task('install:apache', static function (): void {
    $vHostTemplate = parse(\file_get_contents(__DIR__ . '/../template/proserver/apache/vhost.conf'));
    $vhostConfFile = '/usr/local/etc/apache24/Includes/vhost.conf';
    $httpConfFile = '/usr/local/etc/apache24/httpd.conf';
    $loadModuleEntry = 'LoadModule alias_module libexec/apache24/mod_alias.so';
    // Make backup files
    $vhostConfFileIndex = createBackupFile($vhostConfFile);
    $httpConfFileIndex = createBackupFile($httpConfFile);

    run("sudo echo '$vHostTemplate' > $vhostConfFile");

    if (!test("grep -sFq 'LoadModule deflate_module libexec/apache24/mod_deflate.so' $httpConfFile")) {
        run("sudo sed -i '' 's%$loadModuleEntry%$loadModuleEntry\\\nLoadModule deflate_module libexec/apache24/mod_deflate.so%' $httpConfFile");
    }
    if (test("grep -sFq 'ServerAdmin you@example.com' $httpConfFile")) {
        run("sudo sed -i '' 's%ServerAdmin you@example.com%ServerAdmin {{serverEmail}}%' $httpConfFile");
    }

    deleteDuplicateBackupFile($vhostConfFile, $vhostConfFileIndex);
    deleteDuplicateBackupFile($httpConfFile, $httpConfFileIndex);
})->shallow()->setPrivate()->onRoles('Root');
before('install:set_server:apache', 'install:apache');


task('install:nginx', static function (): void {
    $htmlConfTemplate = parse(\file_get_contents(__DIR__ . '/../template/proserver/nginx/html.conf'));

    $htmlConfFile = '/usr/local/etc/nginx/include/html.conf';
    $sslConfFile = '/usr/local/etc/nginx/vhosts/ssl.conf';

    $sslConfFileIndex = createBackupFile($sslConfFile);
    $htmlConfFileIndex = createBackupFile($htmlConfFile);

    run("sudo sed -i '' 's/welcome/html/' $sslConfFile");
    run("sudo echo '$htmlConfTemplate' > $htmlConfFile");

    deleteDuplicateBackupFile($sslConfFile, $sslConfFileIndex);
    deleteDuplicateBackupFile($htmlConfFile, $htmlConfFileIndex);
})->shallow()->setPrivate()->onRoles('Root');
before('install:set_server:nginx', 'install:nginx');


desc('Restart PHP');
task('restart:php', static function (): void {
    run('sudo service php-fpm reload');
})->onRoles('Root');
after('deploy:symlink', 'restart:php');

desc('Set the symbolic link for this site');
task('install:symlink', [
    'install:symlink:ask',
    'install:symlink:php'
]);

task('install:symlink:ask', static function (): void {
    cd('{{html_path}}');
    $GLOBALS['symlinkAction'] = symlinkDomain();
})->shallow()->onRoles('Proserver')->setPrivate();

task('install:symlink:php', static function (): void {
    if ($GLOBALS['symlinkAction'] === 'setToDefault') {
        invoke('restart:php');
    }
})->shallow()->onRoles('Root')->setPrivate();


desc('Edit the cronjobs');
task('edit:cronjob', static function (): void {
    $user = askChoice(' Which user should run the cronjob? ', ['proserver', 'root']);
    run("sudo EDITOR=nano crontab -u $user -e", ['timeout' => null, 'tty' => true]);
})->shallow()->onRoles('Root');

task('install:write_my_cnf', static function (): void {
    $file = get('roles') === 'Root' ? parse('/home/{{user}}/.my.cnf') : '~/.my.cnf';

    if (test("[ -f $file ]")) {
        return;
    }
    run("echo '[client]\nuser = {{dbUser}}\npassword = {{dbPassword}}' > $file");
})->setPrivate();


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
    'install:create_database',
    'install:import',
    'install:info',
    'install:output_db',
    'install:success',
    'install:update:deployfolder',
    'install:update:sshkey',
    'install:wait',
    'node:migrate',
    'node:repair',
    'rollback:publishresources',
    'rollback',
    'site:import',
    'slack:notify:failure',
    'slack:notify:success',
    'slack:notify',
    'ssh:key',
    'user:create_admin',
];
foreach ($roleProserverTasks as $task) {
    task($task)->onRoles('Proserver');
}

desc('Initialize Neos installation on proserver.punkt.de');
task('install', [
    'deploy:prepare',
    'deploy:lock',
    'install:info',
    'install:check_server_email',
    'install:check',
    'ssh:key',
    'install:wait',
    'install:set_server',
    'install:sendmail',
    'install:set_globals',
    'install:set_credentials',
    'install:write_my_cnf',
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
    'domain:dns',
])->shallow();

after('rollback:publishresources', 'restart:php');


task('install:settings', static function (): void {
    $settingsTemplate = parse(\file_get_contents(__DIR__ . '/../template/proserver/neos/Settings.yaml'));
    run("echo '$settingsTemplate' > {{release_path}}/Configuration/Settings.yaml");
})->setPrivate()->onRoles('Proserver');
