# Deployer tasks

You can look at the [examples](examples) folder to look how to set up a deployment.
The files should be saved on the root of you project. In the `deploy.yaml` file you will
find explanations for all the available settings.

## Installation

Enter this on the root of your project:

```bash
composer require --dev jonnitto/neos-deployer
```

## Hoster

Currently, there are settings for these hoster:

- [proServer from punkt.de](documentation/proServer.md)
- Uberspace

## Comands for every hoster

Run these taks with `dep COMMAND`. If you want to list all commands, enter `dep` or `dep list`

**Most important commands:**

| Command                    | Description                                         |
| -------------------------- | --------------------------------------------------- |
| `deploy`                   | Deploy your project                                 |
| `frontend`                 | Build frontend files and push them to git           |
| `install`                  | Initialize installation                             |
| `install:import`           | Import your local database and persistent resources |
| `rollback`                 | Rollback to previous release                        |
| `ssh`                      | Connect to host through ssh                         |
| `ssh:key`                  | Create and/or read the deployment key               |
| `deploy:publish_resources` | Publish resources                                   |
| `deploy:run_migrations`    | Apply database migrations                           |
| `deploy:tag`               | Create release tag on git                           |
| `deploy:unlock`            | Unlock deploy                                       |
| `config:current`           | Show current paths                                  |
| `config:dump`              | Print host configuration                            |
| `config:hosts`             | Print all hosts                                     |
| `node:repair`              | Repair inconsistent nodes in the content repository |
| `node:migrate`             | List and run node migrations                        |
| `site:import`              | Import the site from the a package with a xml file  |
| `user:create_admin`        | Create a new administrator                          |

## proServer

**Additional available commands:**

| Command              | Description                                                 |
| -------------------- | ----------------------------------------------------------- |
| `domain:force`       | Configure the server to force a specific domain             |
| `domain:ssl`         | Add Let's Encrypt SSL certificte                            |
| `domain:ssl:request` | Requested the SSl certificte                                |
| `restart:nginx`      | Restart nginx                                               |
| `restart:php`        | Restart PHP                                                 |
| `tunnel:root`        | Create a tunnel connection via localhost with the root user |
| `tunnel:web`         | Create a tunnel connection via localhost with the web user  |

## Uberspace

**Additional available commands:**

| Command         | Description                       |
| --------------- | --------------------------------- |
| `restart:php`   | Restart PHP                       |
| `domain:add`    | Add a domain to uberspace         |
| `domain:remove` | Remove a domain from uberspace    |
| `php:version`   | Set the PHP version on the server |
