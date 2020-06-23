# proServer from punkt.de

## Update

If you want to update from an older version of gesagtgetan/deployer (<0.6), you can run the command `install:update`. This command will lead you through the process.

## Steps to install Neos

### 1. Create a proServer

Create a proServer on your Self-Service-proServer-Plattform

The proServer can run as Nginx or Apache server. If the `server` variable in `deploy.yaml` is not set, it is automatically set to `nginx`.

### 2. Create deployment files

Create `deploy.php` with following content:

```php
<?php

namespace Deployer;

require_once 'Packages/Libraries/jonnitto/neos-deployer/recipe/proserver.php';

inventory('deploy.yaml');
```

Create `deploy.yaml` with following content and edit it following points:

- Replace `vpro__XXXX__` with the corresponding username
- Replace `__OWNER__/__REPOSITORY` with the corresponding repository
- Replace `webhost@your-domain.tld` with a email address where you as a service provider are reachable. This email address is used to send you error notifications
- Add the `slack_webhook`. (optional) [You can register it here][slack webhook]
- If needed set `flow_context`. Defaults to `Production/Live`
- Replace `domain.tld` with the corresponding domain, **without** `www.`

```yaml
# Install: dep install
# Deploy: dep deploy

.base: &base
  hostname: vpro__XXXX__.proserver.punkt.de
  user: vpro__XXXX__
  repository: git@github.com:__OWNER__/__REPOSITORY__.git
  slack_webhook: https://hooks.slack.com/services/__YOUR/SLACK/WEBHOOK__
  serverEmail: webhost@your-domain.tld

  # Add which caches should be flushed on deployment.
  # Use this setting if you have Redis installed
  # File caches get cleared automatically
  flushCache:
    - Neos_Fusion_Content
    - Flow_Mvc_Routing_Resolve
    - Flow_Mvc_Routing_Route
    - Flow_Session_MetaData
    - Flow_Session_Storage
    - Neos_Media_ImageSize
    - Flow_Security_Cryptography_HashService

  sshOptions:
    ProxyJump: jumping@ssh-jumphost.karlsruhe.punkt.de

domain.tld:
  <<: *base
  user: proserver
  roles: Proserver

root:
  <<: *base
  become: root
  roles: Root
```

### 3. Start installation

Enter `dep install` and follow the screen instructions

### 4. Further steps

For a list of all available commands enter `dep` in the command line

### 5. Going live

#### Set the DNS records

To go live the `A` (IPv4) and the `AAAA` (IPv6) Records need to be set in the domain DNS settings. To find out which are the correct IP addresses you can run the command `dep domain:dns`. This will print the IPv4 and IPv6 addresses into the console. As the IPv6 is unique, there is no need to register a domain on the server.

#### Enable SSL

As soon the domain records point to the server, the [Let's Encrypt] certificate should be requested. This should be done with the command `domain:ssl`. If you set everything set but for some reasons the certification fails you can run `dep domain:ssl:request` to re-run the request of the certification.

#### Configure the server to force a specific domain (Nginx only)

Enter `domain:force` and follow the screen instructions

### Cronjobs

To edit the cronjobs on the server run the command `dep edit:cronjob`. You'll be asked which user should run the cronjob. In the case you have to run a CLI PHP command, it is important to set the full path to the PHP binary.

#### Example for a hourly newsletter export

```bash
* */1 * * * /usr/local/bin/php /var/www/Neos/current/flow newsletter:export >> /var/www/newsletter_exports.log
```

## Installation process

During the installation following files gets changed:

| File                                          | Change                                                             | Nginx | Apache |
| --------------------------------------------- | ------------------------------------------------------------------ | :---: | :----: |
| `/etc/rc.conf`                                | Enable Services (Redis, Elasticsearch & Sendmail and Apache/Nginx) |   ✓   |   ✓    |
| `/etc/rc.local`                               | Flush Redis cache on server reboot                                 |   ✓   |   ✓    |
| `/etc/mail/aliases`                           | Set root e-mail address                                            |   ✓   |   ✓    |
| `/var/www/letsencrypt/domains.txt`            | Add specified domains in from task `domain:ssl`                    |   ✓   |   ✓    |
| `/usr/local/etc/nginx/vhosts/ssl.conf`        | Force to a specific domain and import Neos config                  |   ✓   |        |
| `/usr/local/etc/nginx/include/neos.conf`      | Set web root path, remove robots.txt rule and set `FLOW_CONTEXT`   |   ✓   |        |
| `/usr/local/etc/apache24/Includes/vhost.conf` | vhost config for Apache (DirectoryIndex, domain redirect)          |       |   ✓    |
| `/usr/local/etc/apache24/httpd.conf`          | Add Apache deflate module and set server e-mail address            |       |   ✓    |

> **Note**:  
> On every change of a config file a backup file get automatically generated.  
> It has the syntax `FILENAME.EXT.backup.NUMBER`. Duplicated backup files get deleted.

## DocumentRoot

### Publish

In order for a website to be accessible to visitors, it must be published to the correct directory. The default directory for all requests is `/var/www/html`. But you can also host multiple domains on one proserver. You can create folders (and symlinks) in the form of `/var/www/<domain.tld>`. Make sure your domain is setup and configured correctly. To use RewriteRules, you have to create a `.htaccess` file within the DocumentRoot with the following content: `RewriteBase /`.

> **Warning**  
> Do not delete `/var/www/html`. If this folder doesn’t exist, the RewriteRules  
> implementing the additional DocumentRoots don’t work, so all your domains will be inaccessible.

You can use the command `dep install:symlink` to create a correct symlink

[slack webhook]: https://slack.com/oauth/authorize?&client_id=113734341365.225973502034&scope=incoming-webhook
[let's encrypt]: https://letsencrypt.org
