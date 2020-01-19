# Uberspace

[Uberspace] is an awesome hosting provider from Germany. You can find theirs [complete manual here][uberspace manual].

## Steps to install Neos

### 1. Create your own Uberspace

First, you have to [register your own uberspace]. Then, add your SSh key to the admin-interface. If you don't know what SSH is, you can read more about this in the [SSH section in the uberspace manual][ssh manual on uberspace] or on the [SSH manual on github].

### 2. Create deployment files

Create `deploy.php` with following content:

```php
<?php

namespace Deployer;

require_once 'Packages/Libraries/jonnitto/neos-deployer/recipe/uberspace.php';

inventory('deploy.yaml');
```

Create `deploy.yaml` with following content and edit it following points:

- Replace `domain.tld` with the corresponding domain, **without** `www.`
- Replace `__SERVER__` with corresponding server name. You'll find the infos on the [uberspace dashboard].
- Replace `__USER__` with the corresponding uberspace username

- Replace `__OWNER__/__REPOSITORY` with the corresponding repository
- Add the `slack_webhook`. (optional) [You can register it here][slack webhook]
- If needed set `flow_context`. Defaults to `Production/Live`

```yaml
# Install: dep install
# Deploy: dep deploy

domain.tld:
  hostname: __SERVER__.uberspace.de
  user: __USER__
  repository: git@github.com:__OWNER__/__REPOSITORY__.git
  slack_webhook: https://hooks.slack.com/services/__YOUR/SLACK/WEBHOOK__
```

### 3. Start installation

Enter `dep install` and follow the screen instructions

### 4. Further steps

For a list of all available commands enter `dep` in the command line

#### Add a domain

The add a domain to you uberspace, you can either follow the instructions on the [uberspace manual] or run the command `dep domain:add`.

## DocumentRoot

### Publish

In order for a website to be accessible to visitors, it must be published to the correct directory. The default directory for all requests is `/var/www/virtual/<username>/html`. But you can also host multiple domains on one instance. You can create folders (and symlinks) in the form of `/var/www/virtual/<username>/<domain>`. Make sure your domain is setup and configured correctly. To use RewriteRules, you have to create a `.htaccess` file within the DocumentRoot with the following content: `RewriteBase /`

> **Warning**  
> Do not delete `/var/www/html`. If this folder doesn’t exist, the RewriteRules  
> implementing the additional DocumentRoots don’t work, so all your domains will be unaccessable.

You can use the command `dep install:symlink` to create a correct symlink

### Multi-Domain Setup

In order to be able to have multiple sites on one instance, you have to set some additional config in your `deploy.yaml`:

```yaml
.base: &base
  sshKey: DomainTld
  repository: domain.tld.github.com:gesagtgetan/__REPOSITORY__.git
  database: domain_tld
  deploy_folder: DomainTld
```

| Setting         | Description                                                                                                                                                                                                                                            |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `sshKey`        | This sets the name of the key file, who will be added for the deployment key. A key can be used only for one repo.                                                                                                                                     |
| `repository`    | The create the ability, to use different key for the same service, you can add a custom domain in front of default domain (`github.com`). With this and the `sshKey` setting a special file (`.ssh/config`) with the connection settings gets written. |
| `database`      | As every site need his own database, you have to set this one. The username (`vpro_XXXX_`) gets prefixed automatically                                                                                                                                 |
| `deploy_folder` | Every page need his own place to save the files, so you need to set this also.                                                                                                                                                                         |

You don't have to write the settings like that, but it is recommended to do it like that.

## Going live

### Set the DNS records

To go live the `A` (IPv4) and the `AAAA` (IPv6) Records need to be set in the domain DNS settings. To find out which are the correct IP addresses you take a look at your [uberspace dashboard], or copy the addresses after the `dep domain:add` command.

## Cronjobs

To edit the cronjobs on the server run the command `dep edit:cronjob`. You'll be asked which user should run the cronjob. In the case you have to run a CLI PHP command, it is important to set the full path to the PHP binary.

### Example for a hourly newsletter export

```bash
* */1 * * * /usr/local/bin/php /var/www/Neos/current/flow newsletter:export >> /var/www/newsletter_exports.log
```

[uberspace]: https://uberspace.de/
[uberspace manual]: https://manual.uberspace.de/
[register your own uberspace]: https://dashboard.uberspace.de/register
[ssh manual on uberspace]: https://manual.uberspace.de/basics-ssh.html
[ssh manual on github]: https://help.github.com/en/github/authenticating-to-github/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent
[uberspace dashboard]: https://dashboard.uberspace.de/dashboard/datasheet
[slack webhook]: https://slack.com/oauth/authorize?&client_id=113734341365.225973502034&scope=incoming-webhook
[let's encrypt]: https://letsencrypt.org
