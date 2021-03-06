# Deployer tasks for Neos CMS

These deployer scripts are built on top of [Deployer]. Most of the tasks are provided by this library already; this package adds just some optimization for the install process as well as the needed actions for deploying a project. There are also some helper tasks available, who should make your life as a developer a bit easier. Please run the deployer scripts only in your development environment, as Deployer connects automatically to the needed live server.

You can look at the [examples] folder to look how to set up a deployment.
The files should be saved on the root of you project. In the `deploy.yaml` file you will
find explanations for all the available settings.

## Installation

Enter this on the root of your project:

```bash
composer require --dev jonnitto/neos-deployer
```

## Hoster

Currently, there are settings for these hosters:

- [proServer from punkt.de]
- [Uberspace]
- [Mittwald]

## Slack webhook

To get a notification in Slack, you have to set `slack_webhook`.  
[You can register it here][slack webhook]

## Commands for every hoster

Run these tasks with `dep COMMAND`. If you want to list all commands, enter `dep` or `dep list`

**Most important commands:**

| Command                    | Description                                                  | Uberspace 7 | proServer |
| -------------------------- | ------------------------------------------------------------ | :---------: | :-------: |
| `deploy`                   | Deploy your project                                          |      ✓      |     ✓     |
| `frontend`                 | Build frontend files and push them to git                    |      ✓      |     ✓     |
| `install`                  | Initialize installation                                      |      ✓      |     ✓     |
| `install:import`           | Import your local database and persistent resources          |      ✓      |     ✓     |
| `install:symlink`          | Set the symbolic link for this site                          |      ✓      |     ✓     |
| `rollback`                 | Rollback to previous release                                 |      ✓      |     ✓     |
| `ssh`                      | Connect to host through ssh                                  |      ✓      |     ✓     |
| `ssh:key`                  | Create and/or read the deployment key                        |      ✓      |     ✓     |
| `deploy:publish_resources` | Publish resources                                            |      ✓      |     ✓     |
| `deploy:run_migrations`    | Apply database migrations                                    |      ✓      |     ✓     |
| `deploy:tag`               | Create release tag on git                                    |      ✓      |     ✓     |
| `deploy:unlock`            | Unlock deploy                                                |      ✓      |     ✓     |
| `config:current`           | Show current paths                                           |      ✓      |     ✓     |
| `config:dump`              | Print host configuration                                     |      ✓      |     ✓     |
| `config:hosts`             | Print all hosts                                              |      ✓      |     ✓     |
| `node:migrate`             | List and run node migrations                                 |      ✓      |     ✓     |
| `node:repair`              | Repair inconsistent nodes in the content repository          |      ✓      |     ✓     |
| `site:import`              | Import the site from the a package with a xml file           |      ✓      |     ✓     |
| `user:create_admin`        | Create a new administrator                                   |      ✓      |     ✓     |
| `edit:cronjob`             | Edit the cronjobs                                            |      ✓      |     ✓     |
| `edit:settings`            | Edit the Neos Settings.yaml file                             |      ✓      |     ✓     |
| `domain:add`               | Add a domain to uberspace                                    |      ✓      |           |
| `domain:remove`            | Remove a domain from uberspace                               |      ✓      |           |
| `domain:list`              | List all domains and sub-domains                             |      ✓      |           |
| `domain:force`             | Configure the server to force a specific domain (Nginx only) |             |     ✓     |
| `domain:dns`               | Output the IP addresses for the host                         |             |     ✓     |
| `domain:ssl`               | Add Let's Encrypt SSL certificate                            |             |     ✓     |
| `domain:ssl:request`       | Requested the SSl certificate                                |             |     ✓     |
| `domain:ssl:remove`        | Remove domain(s) from Let's Encrypt SSL certificate list     |             |     ✓     |
| `install:sendmail`         | Activate sendmail on the server                              |             |     ✓     |
| `install:redis`            | Activate redis on the server                                 |             |     ✓     |
| `install:elasticsearch`    | Activate Elasticsearch on the server                         |             |     ✓     |
| `install:set_server`       | Set server to Apache or Nginx, based on `deploy.yaml`        |             |     ✓     |
| `install:update`           | Update from an older version of jonnitto/neos-deployer       |      ✓      |     ✓     |
| `restart:server`           | Restart server (Apache or Nginx)                             |             |     ✓     |
| `restart:php`              | Restart PHP                                                  |      ✓      |     ✓     |
| `tunnel`                   | Create a tunnel connection via localhost for a SFTP or MySQL |             |     ✓     |
| `php:version`              | Set the PHP version on the server                            |      ✓      |           |

[deployer]: https://deployer.org
[examples]: examples
[proserver from punkt.de]: documentation/proServer.md
[uberspace]: documentation/Uberspace.md
[Mittwald]: documentation/Mittwald.md

[slack webhook]: https://slack.com/oauth/authorize?&client_id=113734341365.225973502034&scope=incoming-webhook
