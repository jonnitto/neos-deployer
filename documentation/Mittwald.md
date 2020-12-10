# Deployment to Mittwald

## Set up a server

1. Set up a server as usual.
2. Make sure the document root is set to a folder named `neos` below your home.
3. Set the FLOW_CONTEXT environment variable to `Production` for the webserver.
4. Install required software
   1. Install Composer using the configuration manager
   2. Install SSH (see https://www.mittwald.de/faq/tipps-und-tricks/sonstiges/ssh-keygen-installieren-und-verwenden)

## Create deployment files

In your project run the following:

```sh
cp Packages/Libraries/jonnitto/neos-deployer/examples/Mittwald/deploy.* ./
```

Now edit `deploy.yaml` as needed:

- Set SSH host and user
- Set the correct repository
- Set the database connection parameters

Check the other settings and adjust and/or remove what you (don't) need.

If you want to use the `slack_webhook`, you can register it [at slack.com](https://slack.com/oauth/authorize?&client_id=113734341365.225973502034&scope=incoming-webhook)

## Start installation

Enter `bin/dep install` and follow the on-screen instructions.

From now on you can run `bin/dep deploy` to deploy a new release.

## Further steps

For a list of all available commands enter `dep` in the command line
