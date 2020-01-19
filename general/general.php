<?php

namespace Deployer;

require_once __DIR__ . '/../../../deployer/recipes/recipe/slack.php';
require_once __DIR__ . '/functions.php';


set('bash_sync', 'https://raw.githubusercontent.com/jonnitto/bash/master/bash.sh');

set('slack_title', function () {
    return get('application', getRealHostname());
});

// Set default values
set('port', 22);
set('forwardAgent', false);
set('multiplexing', true);
set('deployUser', function () {
    $getUserCommand = 'git config --get user.name';
    $user = get('user');
    if (!testLocally("$getUserCommand 2>/dev/null || true")) {
        $user = runLocally($getUserCommand);
    }
    return $user;
});
set('slack_text', '_{{deployUser}}_ deploying `{{branch}}` to *{{target}}*');
set('release_name', function () {
    return run('date +"%Y-%m-%d__%H-%M-%S"');
});


desc('Create and/or read the deployment key');
task('ssh:key', function () {
    $hasKey = test('[ -f ~/.ssh/id_rsa.pub ]');
    if (!$hasKey) {
        run('cat /dev/zero | ssh-keygen -q -N "" -t rsa -b 4096 -C "$(hostname -f)"');
    }
    $pub = run('cat ~/.ssh/id_rsa.pub');
    writebox('Your id_rsa.pub key is:');
    writeln("<info>$pub</info>");
    writeln('');

    $repository = preg_replace('/.*@([^:]*).*/', '$1', get('repository'));
    if ($repository) {
        run("ssh-keyscan $repository >> ~/.ssh/known_hosts");
    }
})->shallow();


desc('Install the synchronized bash script');
task('install:bash', function () {
    if (!get('bash_sync', false)) {
        return;
    }
    run('wget -qN {{bash_sync}} -O syncBashScript.sh; source syncBashScript.sh');
})->shallow();


task('install:info', function () {
    $realHostname = getRealHostname();
    writebox("✈︎ Installing <strong>$realHostname</strong> on <strong>{{hostname}}</strong>");
})->shallow()->setPrivate();


desc('Wait for the user to continue');
task('install:wait', function () {
    writebox("<strong>Add this key as a deployment key in your repository</strong><br>under → Settings → Deploy keys");
    if (!askConfirmation(' Press enter to continue ', true)) {
        writebox('Installation canceled', 'red');
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


task('install:success', function () {
    $stage = has('stage') ? ' {{stage}}' : '';
    writebox("<strong>Successfully installed!</strong><br>To deploy your site in the future, simply run <strong>dep deploy$stage</strong>", 'green');
})->shallow()->setPrivate();


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
})->once();


after('deploy:failed', 'deploy:unlock');
before('deploy', 'slack:notify');
after('success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');


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
