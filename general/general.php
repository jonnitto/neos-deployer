<?php

namespace Deployer;

require_once __DIR__ . '/defaultValues.php';
require_once __DIR__ . '/slack.php';
require_once __DIR__ . '/functions.php';


desc('Create and/or read the deployment key');
task('ssh:key', static function () {
    if (!test('[ -f ~/.ssh/{{sshKey}}.pub ]')) {
        // -q Silence key generation
        // -t Set algorithm
        // -b Specifies the number of bits in the key
        // -N Set the passphrase
        // -C Comment for the key
        // -f Specifies name of the file in which to store the created key
        run('cat /dev/zero | ssh-keygen -q -t rsa -b 4096 -N "" -C "$(hostname -f)" -f ~/.ssh/{{sshKey}}');
    }

    // We dont use `ssh-keygen -y` because we also want to output the comment
    writebox('The public key ({{sshKey}}.pub) from <strong>' . getRealHostname() . '</strong> is:');
    writeln('<info>' . run('cat ~/.ssh/{{sshKey}}.pub') . '</info>',);
    writeln('');

    $sshKnowHostsFile = '~/.ssh/known_hosts';
    $repoHost = get('repositoryUrlParts')['host'];

    if (!test("grep -q '$repoHost' $sshKnowHostsFile")) {
        run("ssh-keyscan $repoHost >> $sshKnowHostsFile");
    }
})->shallow();




task('install:info', static function (): void {
    writebox('✈︎ Installing <strong>' . getRealHostname() . '</strong> on <strong>{{hostname}}</strong>');
})->shallow()->setPrivate();


desc('Wait for the user to continue');
task('install:wait', static function (): void {
    writebox('<strong>Add this key as a deployment key in your repository</strong><br>under → Settings → Deploy keys');
    if (!askConfirmation(' Press enter to continue ', true)) {
        invoke('deploy:unlock');
        writebox('Installation canceled', 'red');
        exit;
    }
    writeln('');
})->shallow()->setPrivate();


task('install:create_database', static function (): void {
    run(\sprintf('echo %s | mysql', \escapeshellarg(dbFlushDbSql(get('dbName')))));
})->setPrivate();


task('install:output_db', static function (): void {
    outputTable(
        'Following database credentials are set:',
        [
            'Name' => '{{dbName}}',
            'User' => '{{dbUser}}',
            'Password' => '{{dbPassword}}'
        ]
    );
})->shallow()->setPrivate();


task('install:output_oauth', static function (): void {
    outputTable(
        'Please add these credentials to the oAuth database to enable login:',
        [
            'ID' => '{{authId}}',
            'Secret' => '{{authSecret}}',
            'Name' => getRealHostname() . ' on {{hostname}}'
        ]
    );
})->shallow()->setPrivate();

task('deploy:git_config', static function () {
    cd('{{release_path}}');
    run('{{bin/git}} config --local --add core.sshCommand "ssh -i ~/.ssh/{{sshKey}}"');
})->setPrivate();
after('deploy:update_code', 'deploy:git_config');


task('install:success', static function (): void {
    $stage = has('stage') ? ' {{stage}}' : '';
    writebox('<strong>Successfully installed!</strong><br>To deploy your site in the future, simply run <strong>dep deploy' . $stage . '</strong>', 'green');
})->shallow()->setPrivate();


desc('Create release tag on git');
task('deploy:tag', static function (): void {
    // Set timestamps tag
    set('tag', \date('Y-m-d_T_H-i-s'));
    set('day', \date('d.m.Y'));
    set('time', \date('H:i:s'));

    runLocally(
        'git tag -a -m "Deployment on the {{day}} at {{time}}" "{{tag}}"'
    );
    runLocally('git push origin --tags');
})->once();


after('deploy:failed', 'deploy:unlock');
fail('install', 'deploy:unlock');


desc('Update from an older version of jonnitto/neos-deployer');
task('install:update', [
    'install:update:deployfolder',
    'install:update:sshkey',
    'install:symlink',
]);

task('install:update:deployfolder', static function (): void {
    if (!test('[ -d {{deploy_path}} ]')) {
        cd('{{html_path}}');
        run('mv Neos {{deploy_folder}}');
        writebox('Neos was renamed to {{deploy_folder}}');
    }
})->shallow()->setPrivate();

task('install:update:sshkey', static function () {
    cd('~/.ssh');
    if (!test('[ -f {{sshKey}}.pub ]')) {
        run('mv id_rsa {{sshKey}}');
        run('mv id_rsa.pub {{sshKey}}.pub');
        writebox('The default ssh key <strong>id_rsa</strong> was renamed to <strong>{{sshKey}}</strong>');
    }
})->shallow()->setPrivate();


// Set some deploy tasks to private
foreach ([
    'clear_paths',
    'copy_dirs',
    'lock',
    'prepare',
    'release',
    'shared',
    'symlink',
    'update_code',
    'vendors',
    'writable'
] as $task) {
    task("deploy:{$task}")->setPrivate();
}
