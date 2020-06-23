<?php

namespace Deployer;

// Set default values
set('port', 22);
set('forwardAgent', false);
set('multiplexing', true);
set('release_name', static function (): string {
    return run('date +"%Y-%m-%d__%H-%M-%S"');
});
set('sub_context', 'Live');
set('flow_context', static function (): string {
    $array = \array_filter(['Production', get('sub_context')]);
    return \implode('/', $array);
});

set('repositoryUrlParts', static function (): array {
    \preg_match(
        '/^(?:(?<user>[^@]*)@)?(?<host>[^:]*):(?<path>.*\/(?<shortName>.*))\.git$/',
        get('repository'),
        $urlParts
    );
    return $urlParts;
});

set('repositoryShortName', static function (): string {
    return get('repositoryUrlParts')['shortName'];
});

set('deployUser', static function (): string {
    $user = \getenv('GIT_AUTHOR_NAME');
    if ($user === false) {
        $user = \getenv('GIT_COMMITTER_NAME');
        if ($user === false) {
            $getUserCommand = 'git config --get user.name';
            if (!testLocally($getUserCommand . ' 2>/dev/null || true')) {
                $user = runLocally($getUserCommand);
            } else {
                $user = get('user');
            }
        }
    }
    return $user;
});

set('deploy_folder', get('repositoryShortName'));
set('sshKey', get('repositoryShortName'));
set('bin/git', static function (): string {
    return 'GIT_SSH_COMMAND="ssh -i ~/.ssh/' . get('sshKey') . '" ' . locateBinaryPath('git');
});
