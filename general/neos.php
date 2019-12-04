<?php

namespace Deployer;

require_once 'recipe/flow_framework.php';
require_once __DIR__ . '/general.php';

set('deploy_folder', 'Neos');
set('sub_context', 'Live');
set('system', 'neos');
set('web_root', '/Web');

set('flow_context', function () {
    $array = array_filter(['Production', get('sub_context')]);
    return implode('/', $array);
});

// Share global configuration
set('shared_files', [
    'Configuration/Settings.yaml',
]);


desc('Remove robots.txt file');
task('deploy:remove_robotstxt', function () {
    if (get('removeRobotsTxt', true)) {
        run('rm -f {{release_path}}/Web/robots.txt');
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


desc('Import your local database and persistent resources');
task('install:import', [
    'install:import:database',
    'install:import:resources'
]);


desc('Check if Neos is already installed');
task('install:check', function () {
    $installed = test('[ -f {{deploy_path}}/shared/Configuration/Settings.yaml ]');
    if ($installed) {
        writebox('<strong>Neos seems already installed</strong><br>Please remove the whole Neos folder to start over again.', 'red');
        exit;
    }
})->shallow()->setPrivate();


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


desc('Create a new administrator');
task('user:create_admin', function () {
    $username = ask(' Please enter the username ');
    $password = askHiddenResponse(' Please enter the password ');
    $firstName = ask(' Please enter the first name ');
    $lastName = ask(' Please enter the last name ');
    run("FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} user:create --roles Administrator $username $password $firstName $lastName");
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


desc('Edit the Neos Settings.yaml file');
task('edit:settings', function () {
    run('nano {{release_path}}/Configuration/Settings.yaml', ['timeout' => null, 'tty' => true]);
})->shallow();


// Execute flow publish resources after a rollback (path differs, because release_path is the old one here)
task('rollback:publishresources', function () {
    run('FLOW_CONTEXT={{flow_context}} {{bin/php}} {{release_path}}/{{flow_command}} resource:publish');
})->setPrivate();
after('rollback', 'rollback:publishresources');
