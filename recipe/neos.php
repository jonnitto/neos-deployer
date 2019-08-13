<?php

namespace Deployer;

require_once 'recipe/flow_framework.php';
require_once 'Packages/Libraries/deployer/recipes/recipe/slack.php';
require_once __DIR__ . '/../functions.php';

set('flow_context', 'Production/Live');

set('bash_sync', 'https://raw.githubusercontent.com/jonnitto/bash/master/bash.sh');

// Share global configuration
set('shared_files', [
    'Configuration/Settings.yaml',
]);

// Set default values
set('port', 22);
set('forwardAgent', false);
set('multiplexing', true);
set('deployUser', function () {
    $gitUser = runLocally('git config --get user.name');
    return $gitUser ? $gitUser : get('user');
});
set('slack_text', '_{{deployUser}}_ deploying `{{branch}}` to *{{target}}*');


desc('Create and/or read the deployment key');
task('ssh:key', function () {
    $hasKey = test('[ -f ~/.ssh/id_rsa.pub ]');
    if (!$hasKey) {
        run('cat /dev/zero | ssh-keygen -q -N "" -t rsa -b 4096 -C "$(hostname -f)"');
    }
    $pub = run('cat ~/.ssh/id_rsa.pub');
    writeln('');
    writeln('<comment>Your id_rsa.pub key is:</comment>');
    writeln("<info>$pub</info>");
    writeln('');

    $repository = preg_replace('/.*@([^:]*).*/', '$1', get('repository'));
    if ($repository) {
        run("ssh-keyscan $repository >> ~/.ssh/known_hosts");
    }
})->shallow();


desc('Check if Neos is already installed');
task('install:check', function () {
    $installed = test('[ -f {{deploy_path}}/shared/Configuration/Settings.yaml ]');
    if ($installed) {
        writeln('');
        writeln('<error> Neos seems already installed </error>');
        writeln('<comment>Please remove the whole Neos folder to start over again.</comment>');
        writeln('');
        exit;
    }
})->shallow()->setPrivate();


desc('Install the synchronized bash script');
task('install:bash', function () {
    if (!get('bash_sync', false)) {
        return;
    }
    run('wget -qN {{bash_sync}} -O syncBashScript.sh; source syncBashScript.sh');
})->shallow();


task('install:info', function () {
    $realHostname = getRealHostname();
    writeln('');
    writeln("✈︎ Installing <fg=magenta>$realHostname</fg=magenta> on <fg=cyan>{{hostname}}</fg=cyan>");
})->shallow()->setPrivate();


desc('Wait for the user to continue');
task('install:wait', function () {
    writeln('');
    writeln('<comment>Add this key as a deployment key in your repository</comment>');
    writeln('<comment>under → Settings → Deploy keys</comment>');
    writeln('');
    if (!askConfirmation(' Press enter to continue ', true)) {
        writeln('');
        writeln('<error> Installation canceled </error>');
        writeln('');
        exit;
    }
    writeln('');
})->shallow()->setPrivate();


task('install:create_database', function () {
    run(sprintf('echo %s | %s', escapeshellarg(dbFlushDbSql(get('dbName'))), dbConnectCmd(get('dbUser'), get('dbPassword'))));
})->setPrivate();


task('install:output_db', function () {
    outputTable(
        'Following database credentias are set:',
        [
            'Name' => '{{dbName}}',
            'User' => '{{dbUser}}',
            'Password' => '{{dbPassword}}'
        ]
    );
})->shallow()->setPrivate();


task('install:output_oauth', function () {
    $realHostname = getRealHostname();
    outputTable(
        'Please add these credentials to the oAuth database to enable login:',
        [
            'ID' => '{{authId}}',
            'User' => '{{authSecret}}',
            'Name' => "$realHostname on {{hostname}}"
        ]
    );
})->shallow()->setPrivate();


task('install:success', function () {
    writeln('');
    writeln('<info>Successfully installed!</info>');
    writeln('To deploy your site in the future, simply run <fg=cyan>dep deploy</fg=cyan>.');
    writeln('');
})->shallow()->setPrivate();


task('yarn:pipeline', function () {
    runLocally('yarn pipeline');
})->setPrivate();


task('yarn:git', function () {
    runLocally('git add DistributionPackages/**/Resources/Public');
    runLocally('git add DistributionPacka ge s/**/Resources/Private/Templates/I nlineAssets ');
    runLocally('git commit -m ":lipstick: Build frontend resources" || echo ""');
    runLocally('git push');
})->setPrivate();


desc('Build frontend files and push them to git');
task('yarn', [
    'yarn:pipeline',
    'yarn:git'
]);


desc('Create release tag on git');
task('deploy:tag', function () {
    // Set timestamps tag
    set('tag', date('Y-m-d_T_H-i-s'));
    set('day', date('d.m.Y'));
    set('time', date('H:i:s'));

    runLocally(
        'git tag -a -m "Deployment on the {{day}} at {{time}}" "{{tag}}"'
    );
    runLocally('git push origin --tags');
});


after('deploy:failed', 'deploy:unlock');

// Execute flow publish resources after a rollback (path differs, because release_path is the old one here)
task('rollback:publishresources', function () {
    run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} resource:publish');
})->setPrivate();
after('rollback', 'rollback:publishresources');


before('deploy', 'slack:notify');
after('success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');
