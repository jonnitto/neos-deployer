<?php

namespace Deployer;

require_once 'recipe/flow_framework.php';
require_once 'Packages/Libraries/deployer/recipes/recipe/slack.php';
require_once __DIR__ . '/../functions.php';

set('deploy_folder', 'Neos');
set('sub_context', 'Live');
set('flow_context', function () {
    $array = array_filter(['Production', get('sub_context')]);
    return implode('/', $array);
});

set('bash_sync', 'https://raw.githubusercontent.com/jonnitto/bash/master/bash.sh');

// Share global configuration
set('shared_files', [
    'Configuration/Settings.yaml',
]);

set('slack_title', function () {
    return get('application', getRealHostname());
});

// Set default values
set('port', 22);
set('forwardAgent', false);
set('multiplexing', true);
set('deployUser', function () {
    $gitUser = runLocally('git config --get user.name');
    return $gitUser ? $gitUser : get('user');
});
set('slack_text', '_{{deployUser}}_ deploying `{{branch}}` to *{{target}}*');

desc('Remove robots.txt file');
task('deploy:remove_robotstxt', function () {
    if (get('removeRobotsTxt', true)) {
        run('rm {{release_path}}/Web/robots.txt');
    }
})->setPrivate();
before('deploy:symlink', 'deploy:remove_robotstxt');

desc('Flush caches');
task('deploy:flush_caches', function () {
    $caches = get('flushCache', false);
    if (is_array($caches)) {
        foreach ($caches as $cache) {
            run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} cache:flushone ' . $cache);
        }
    }
})->setPrivate();
after('deploy:symlink', 'deploy:flush_caches');


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


// Set some deploy tasks to private
foreach ([
    'clear_paths',
    'copy_dirs',
    'flush_caches',
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


desc('Check if Neos is already installed');
task('install:check', function () {
    $installed = test('[ -f {{deploy_path}}/shared/Configuration/Settings.yaml ]');
    if ($installed) {
        writebox('<strong>Neos seems already installed</strong><br>Please remove the whole Neos folder to start over again.', 'red');
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


desc('Import your local database and persistent resources');
task('install:import', [
    'install:import_database',
    'install:import_resources'
]);


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


desc('Build frontend files and push them to git');
task('frontend', function () {
    $config = get('frontend', []);

    if (!array_key_exists('command', $config)) {
        $config['command'] = 'yarn pipeline';
    }

    if (!array_key_exists('message', $config)) {
        $config['message'] = 'STATIC: Build frontend resources';
    }

    if (!array_key_exists('paths', $config)) {
        $config['paths'] = ['DistributionPackages/**/Resources/Public'];
    }

    if ($config['command']) {
        runLocally($config['command'], ['timeout' => null]);
    }

    if (is_array($config['paths'])) {
        $makeCommit = false;

        foreach ($config['paths'] as $path) {
            $hasFolder = runLocally("ls $path 2>/dev/null || true");
            $hasCommits = !testLocally("git add --dry-run -- $path");
            if ($hasFolder && $hasCommits) {
                runLocally("git add $path");
                $makeCommit = true;
            }
        }

        if ($makeCommit) {
            runLocally('git commit -m "' . $config['message'] . '" || echo ""');
            runLocally('git push');
        }
    }
})->once();


desc('Repair inconsistent nodes in the content repository');
task('node:repair', function () {
    run('FLOW_CONTEXT={{flow_context}} {{bin/php}} -d memory_limit=8G {{release_path}}/{{flow_command}} node:repair', ['timeout' => null, 'tty' => true]);
})->shallow();


desc('List and run node migrations');
task('node:migrate', function () {
    $output = run('FLOW_CONTEXT={{flow_context}} {{bin/php}} -d memory_limit=8G {{release_path}}/{{flow_command}} node:migrationstatus', ['timeout' => null]);
    writeln($output);
    writeln('');
    while ($version = ask(' Please enter the version number of the migration you want to run. To finish the command, press enter ')) {
        writebox("Run migration <strong>$version</strong>");
        run("FLOW_CONTEXT={{flow_context}} {{bin/php}} -d memory_limit=8G {{release_path}}/{{flow_command}} node:migrate --version $version", ['timeout' => null]);
        writeln('');
    }
    writeln('');
})->shallow();


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


desc('Create a new administrator');
task('user:create_admin', function () {
    $username = ask(' Please enter the username ');
    $password = askHiddenResponse(' Please enter the password ');
    $firstName = ask(' Please enter the first name ');
    $lastName = ask(' Please enter the last name ');
    run("FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} flow user:create --roles Administrator $username $password $firstName $lastName");
})->shallow();


desc('Import the site from the a package with a xml file');
task('site:import', function () {
    $path = '{{release_path}}/DistributionPackages';
    if (test("[ -d $path ]")) {
        cd($path);
    } else {
        cd('{{release_path}}/Packages/Sites');
    }

    $packages = run("ls -d */ | cut -f1 -d'/'");

    if (!$packages) {
        writebox('No packages found', 'red');
        return;
    }

    $packagesArray = preg_split('/\n/i', $packages);
    $package = $packagesArray[0];

    if (count($packagesArray) > 1) {
        $package = askChoice(' Please choose the package with the content you want to import ', $packagesArray);
    }

    writebox("Import the content from <strong>$package</strong>");
    run("FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} site:import --package-key $package", ['timeout' => null, 'tty' => true]);
})->shallow();


after('deploy:failed', 'deploy:unlock');

// Execute flow publish resources after a rollback (path differs, because release_path is the old one here)
task('rollback:publishresources', function () {
    run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} resource:publish');
})->setPrivate();
after('rollback', 'rollback:publishresources');


before('deploy', 'slack:notify');
after('success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');
