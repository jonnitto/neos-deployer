# Deployment to a proServer

## 1. Create a proServer

Create a proServer on your Self-Service-proServer-Plattform

## 2. Create deployment files

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
- Add the `slack_webhook`. You can register it [here](https://slack.com/oauth/authorize?&client_id=113734341365.225973502034&scope=incoming-webhook)
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
  # flow_context: Production/Live

  sshOptions:
    UserKnownHostsFile: /dev/null
    StrictHostKeyChecking: no
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

## 3. Start installation

Enter `dep install` and follow the screen instructions

## 4. Further steps

For a list of all available commands enter `dep` in the command line

### Configure the server to force a specific domain

Enter `domain:force` and follow the screen instructions

### Add Let's Encrypt SSL certificte

Enter `domain:ssl` and follow the screen instructions
