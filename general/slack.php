<?php

/**
 * This slack function allows to post in multiple channels
 */

namespace Deployer;

use Deployer\Utility\Httpie;

require_once __DIR__ . '/defaultValues.php';

set('slack_application', static function (): string {
    return get('application', getRealHostname());
});
set('slack_title', '{{slack_application}} (Neos)');
set('slack_text', '_{{deployUser}}_ deploying *{{repositoryShortName}}* on `{{branch}}` to *{{target}}*');
set('slack_success_text', 'Deploy from *{{repositoryShortName}}* to *{{target}}* successful');
set('slack_failure_text', 'Deploy from *{{repositoryShortName}}* to *{{target}}* failed');


// Color of attachment
set('slack_color', '#4d91f7');
set('slack_success_color', '#00c100');
set('slack_failure_color', '#ff0909');

desc('Notifying Slack');
task('slack:notify', static function (): void {
    $slackWebhook = get('slack_webhook', false);
    if (!$slackWebhook) {
        return;
    }
    if (!\is_array($slackWebhook)) {
        $slackWebhook = [$slackWebhook];
    }

    $attachment = [
        'title' => get('slack_title'),
        'text' => get('slack_text'),
        'color' => get('slack_color'),
        'mrkdwn_in' => ['text'],
    ];

    foreach (\array_unique($slackWebhook) as $hook) {
        Httpie::post($hook)->body(['attachments' => [$attachment]])->send();
    }
})->once()->shallow()->setPrivate();

desc('Notifying Slack about deploy finish');
task('slack:notify:success', static function (): void {
    $slackWebhook = get('slack_webhook', false);
    if (!$slackWebhook) {
        return;
    }
    if (!\is_array($slackWebhook)) {
        $slackWebhook = [$slackWebhook];
    }

    $attachment = [
        'title' => get('slack_title'),
        'text' => get('slack_success_text'),
        'color' => get('slack_success_color'),
        'mrkdwn_in' => ['text'],
    ];

    foreach (\array_unique($slackWebhook) as $hook) {
        Httpie::post($hook)->body(['attachments' => [$attachment]])->send();
    }
})->once()->shallow()->setPrivate();

desc('Notifying Slack about deploy failure');
task('slack:notify:failure', static function (): void {
    $slackWebhook = get('slack_webhook', false);
    if (!$slackWebhook) {
        return;
    }
    if (!\is_array($slackWebhook)) {
        $slackWebhook = [$slackWebhook];
    }

    $attachment = [
        'title' => get('slack_title'),
        'text' => get('slack_failure_text'),
        'color' => get('slack_failure_color'),
        'mrkdwn_in' => ['text'],
    ];

    foreach (\array_unique($slackWebhook) as $hook) {
        Httpie::post($hook)->body(['attachments' => [$attachment]])->send();
    }
})->once()->shallow()->setPrivate();

before('deploy', 'slack:notify');
after('success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');
