# Revilo on Oracle Always Free

Prepared on 10 October 2026. This guide publishes the existing Laravel/MySQL shop as a public preview. It uses a new server database and administrator; the Windows preview, customer records and private credentials are not copied automatically. Real campaign email and checkout remain disabled. No cloud resources have been created by these files.

## 1. Create your free account

Open [Oracle Cloud Free Tier](https://www.oracle.com/cloud/free/) and create one account. Complete the identity and card verification yourself. Oracle may place a temporary authorization hold; its stated process releases that hold without an actual charge.

Choose an EU home region offered during signup, preferably Amsterdam for this shop, or another nearby EU region. The [home region cannot be changed later](https://blogs.oracle.com/apex/getting-started-with-oracle-cloud-free-tier-always-free-services-including-oracle-autonomous-database). Free server capacity is not guaranteed in any region.

Keep the account on Free Tier. Use only resources within **Always Free** limits. The separate 30-day trial credit does not make a paid resource permanently free. Do not upgrade to Pay As You Go for this €0 setup.

## 2. Create the server

In Oracle, open **Compute → Instances → Create instance**. Use these settings, checking the current Always Free eligibility in your console:

| Setting | Value |
| --- | --- |
| Name | `revilo-preview` |
| Image | Ubuntu 24.04 LTS, ARM-compatible |
| Shape | `VM.Standard.A1.Flex` (Ampere) |
| CPU | 2 OCPUs |
| Memory | 6 GB |
| Boot volume | 50 GB, default/balanced performance |
| Placement | Your home region, any available eligible availability domain |
| Network | Public subnet, internet gateway and public IPv4 |
| SSH | Generate/download a key pair, or upload your own public key |
| Extra services | No additional volumes, load balancer, replicas or paid backup policies |

These settings fit the documented free allowance of 2 OCPUs, 12 GB memory and 200 GB combined boot/block storage. Existing resources also count toward your account's limits. Verify the configuration is within your free allowance before creating it. An estimate may include covered resources during the trial; a zero trial estimate alone is not proof of Always Free eligibility. If capacity is unavailable, pause and retry later rather than selecting a paid shape. See [current limits](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm) and [Oracle's instance guide](https://docs.oracle.com/en-us/iaas/Content/Compute/tutorials/first-linux-instance/overview.htm).

Save the private SSH key securely on your computer; upload only the public key to Oracle. Once the instance says **Running**, note its public IP. The hosted administrator will get a new password, separate from the local development login already shared in this chat.

## 3. Allow web traffic

In the instance's subnet security list or attached network security group, allow inbound TCP **80** and **443** from `0.0.0.0/0`. Restrict TCP **22** to your own public IP with `/32` when practical. The existing SSH rule must still allow your connection.

The deployment publishes only HTTP/HTTPS. MySQL and PHP-FPM have no host ports. If your VM has a host firewall, permit those web ports while retaining SSH access. [Docker's published ports can bypass UFW rules](https://docs.docker.com/engine/install/ubuntu/#firewall-limitations), so Oracle's network rules are the outer access boundary. Do not disable the firewall to troubleshoot.

## 4. Choose a free web address

Sign in to [DuckDNS](https://www.duckdns.org/) and choose an available name, such as `your-revilo-name.duckdns.org`. Set its IPv4 address to the server's public IP. Leave IPv6 unset unless you have deliberately configured a matching IPv6 route. Wait until the address resolves to the correct server. Keep the DuckDNS account/token private; the token is not needed inside the webshop.

Later, a paid domain can point to this same server. The shop code does not need to be rebuilt for that change; update the server hostname and Google callback configuration instead. Automatic HTTPS is handled by [Caddy](https://caddyserver.com/docs/automatic-https) after DNS and inbound web traffic work.

## 5. Transfer the prepared source

The deployment package is built on Windows from the project directory:

```powershell
& .\scripts\package-oracle.ps1
```

Use the newly printed ZIP path. The archive contains application source, supplied public images and the deployment kit, but excludes `.env`, local database files, private storage, saved admin passwords, development dependencies and caches. Assets and PHP dependencies are built on the server. The accompanying `.sha256` file records the archive checksum.

Use your actual key, IP and printed archive filename in the following examples. These are placeholders, not commands ready to run unchanged:

```powershell
ssh -i "C:\path\to\your-private-key.key" ubuntu@YOUR_SERVER_IP
```

Confirm the SSH host fingerprint against the server information through a trusted channel before accepting a new host. In the server terminal:

```sh
mkdir -p ~/revilo
```

From a Windows terminal, transfer the source:

```powershell
scp -i "C:\path\to\your-private-key.key" "C:\path\to\revilo-oracle-TIMESTAMP.zip" ubuntu@YOUR_SERVER_IP:~/revilo/
```

Back in the server terminal, use the actual archive name:

```sh
sudo apt-get update
sudo apt-get install -y unzip
cd ~/revilo
unzip revilo-oracle-TIMESTAMP.zip
sudo sh deploy/oracle/install-docker.sh
```

The installer uses [Docker's official Ubuntu repository](https://docs.docker.com/engine/install/ubuntu/). Commands below use `sudo` for Docker; no extra Docker group permissions are required.

## 6. Initialize and start the public preview

Replace the hostname below with your actual DuckDNS address. Run initialization once:

```sh
cd ~/revilo
sh deploy/oracle/init-env.sh your-revilo-name.duckdns.org
sudo sh deploy/oracle/deploy.sh --demo
```

Initialization creates a private server `.env` with a new application key and separate random MySQL passwords. It refuses to overwrite an existing file, and does not print the values. Deployment builds the image, starts MySQL, applies migrations, loads clearly labelled demo inventory only into an empty preview catalogue, and starts the website, email worker and scheduler. No Windows database or customer data is transferred.

The first build can take several minutes. The site is ready when all deployment checks pass and the web service is healthy. Open `https://your-revilo-name.duckdns.org/nl`. HTTP redirects to HTTPS. Search indexing is disabled for the preview, checkout is disabled, and emails stay in previews. Background services automatically restart after a VM reboot once Docker starts.

Create the new hosted administrator interactively, using an email address you control:

```sh
sudo docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml exec app php artisan revilo:admin your-email@example.com
```

The command asks privately for a new password. Log in at `/nl/login`, then open `/admin`. The Windows development administrator is not included in the package.

## 7. Add Google sign-in after the hostname works

Add the exact hosted callback to your Google OAuth web client in Google Cloud:

`https://your-revilo-name.duckdns.org/auth/google/callback`

Use `https://your-revilo-name.duckdns.org` as the JavaScript origin if needed. Retain the localhost callback if you still use the local preview. Complete any domain verification and OAuth consent-screen requirements Google presents; a free subdomain can have verification constraints. Do not publish a client that is not eligible for your intended users.

Edit the server's private `deploy/oracle/.env` and set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` privately. The callback is derived from the public hostname. Do not put credentials in the source archive or this guide. Apply the new environment:

```sh
sudo docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml up -d --force-recreate app worker scheduler
```

Then complete an actual Google sign-in on the HTTPS site.

## 8. Back up and update

Create a consistent backup before each update and periodically while using the shop:

```sh
sudo sh deploy/oracle/backup.sh
```

This briefly puts the shop in maintenance mode, stops background jobs, exports the database and uploaded/private app files, and saves the server configuration (including the encryption key). It attempts to resume the shop on completion or failure. Copy the private backup to a separate computer using `scp`; a copy on the same VM alone does not protect against VM loss. Backups contain credentials and customer data and must stay private. Watch disk space and retain only the copies you need after storing them safely elsewhere.

For an update, upload/extract a newly prepared archive in `~/revilo`, keeping `deploy/oracle/.env` and Docker volumes. Then:

```sh
sudo sh deploy/oracle/deploy.sh
```

Use `--demo` only for the first empty preview. Updates put a running app into maintenance mode and pause background jobs before migrations. If an update fails during this stage, maintenance remains enabled; fix the reported error and rerun deployment, or restore the backup. Deployment never runs `migrate:fresh`, resets a database or deletes volumes. Keep the application encryption key unchanged when restoring existing data. A fresh replacement VM can use the same source and server environment, then import the database backup and restore `storage/app` before starting workers and the web service. Restoration should be verified privately before changing DNS.

Useful checks:

```sh
sudo docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml ps
sudo docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml logs --tail=50 app web
sudo docker compose --env-file deploy/oracle/.env -f deploy/oracle/compose.yaml exec app php artisan revilo:deployment-check
```

This command checks the application configuration and required database tables; it does not certify the Oracle billing plan, DNS, external OAuth/email delivery or commercial readiness. Keep the public preview flag on until real stock, company details and payment integration are ready. Brevo campaign delivery remains a separate setup requiring an authenticated sending domain; DuckDNS's limited TXT record support is not a suitable assumption for complete branded email authentication. [DuckDNS specification](https://www.duckdns.org/spec.jsp), [Brevo domain setup](https://help.brevo.com/hc/en-us/articles/35852083084178-Domain-setup-for-better-email-deliverability).

Oracle may reclaim idle free servers and free-tier policies can change. Off-server backups and standard PHP/MySQL containers provide a path to recover or move. This guide does not promise permanent free capacity or uninterrupted commercial hosting.
