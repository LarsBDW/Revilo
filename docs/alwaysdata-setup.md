# Put your webshop on alwaysdata — step by step

This guide is for your hosting account **larsbdw** and a first installation with sample laptops. Your website address will be **https://larsbdw.alwaysdata.net/nl**.

Follow the steps in order. Paste each command, press **Enter**, and wait for it to finish. Each step tells you whether to use your browser, Windows PowerShell or the connected server terminal. Settings shown for the `.env` editor are file contents, not terminal commands.

The prepared ZIP is already on your PC:

```text
C:\Users\eajde\OneDrive\Documenten\ChatGPT\Refurbished website\dist\laptivo-alwaysdata-upload.zip
```

The required code changes are included. This starts as a preview with sample stock, checkout disabled and email previews. The existing logo and design are included.

## Step 1 — Check the Free plan

**Where: alwaysdata dashboard in your browser.**

1. Select your **larsbdw** hosting account if an account selector is shown.
2. Open **Subscriptions**.
3. Check that the plan says **Free** and **€0/month**.

**Done when:** the account shows Free. If it shows a Plus trial, change to Free before continuing if you want to stay at €0.

Free is advertised for personal use, with 1 GB storage and 256 MB RAM. Use this as a development preview and confirm eligibility with alwaysdata before commercial sales. [Official plans](https://www.alwaysdata.com/en/offers/).

## Step 2 — Select PHP 8.4

**Where: alwaysdata dashboard.**

1. Open **Environment → PHP**.
2. Find the PHP version setting.
3. Select **8.4**.
4. Click **Save**.

If the page has a PHP configuration/options box, add these lines and save:

```ini
memory_limit = 128M
upload_max_filesize = 10M
post_max_size = 12M
display_errors = Off
log_errors = On
```

**Done when:** the saved PHP version is 8.4. [PHP settings](https://help.alwaysdata.com/en/docs/web-hosting/languages/php/configuration/).

## Step 3 — Create the database

**Where: alwaysdata dashboard.**

1. Open **Databases → MySQL**.
2. Find the **Databases** area and click the button to add a database.
3. Set its full name to **larsbdw_shop**. If the form already displays the fixed prefix `larsbdw_`, enter only **shop** in the editable part.
4. Click **Save**.
5. Write down the full saved database name and the MySQL server shown on this page.

**Done when:** the new database appears in the list. The expected name is `larsbdw_shop` and the expected server is `mysql-larsbdw.alwaysdata.net`.

Use the actual saved values if they differ. The dashboard calls this MySQL; the managed database runs MariaDB. [Connection details](https://help.alwaysdata.com/en/docs/web-hosting/databases/mariadb/).

## Step 4 — Create a database user

**Where: alwaysdata dashboard, still under Databases → MySQL.**

1. Find the **Users** area or tab.
2. Click the button to add a MySQL user.
3. Set its full name to **larsbdw_app**. If the form supplies `larsbdw_`, enter only **app**.
4. Choose and save a strong, unique password. A password manager can generate 32 random letters and numbers, which are easy to enter in Step 8.
5. Give this user **all privileges** on **larsbdw_shop** only.
6. Click **Save**.

**Done when:** you have privately saved the database name, database username and database password.

This password is separate from your dashboard or SSH password. If the dashboard assigned different names, use those exact names in Step 8. [Database permissions](https://help.alwaysdata.com/en/docs/web-hosting/databases/mariadb/).

## Step 5 — Enable SSH access

**Where: alwaysdata dashboard.**

1. Open **Remote access → SSH/SFTP**.
2. Find the SSH user named **larsbdw**.
3. Enable it if it is disabled.
4. Set or confirm its SSH password and save.
5. Keep the page open. It shows the host and server fingerprints for the first connection.

**Done when:** an enabled SSH user is listed. The expected host is `ssh-larsbdw.alwaysdata.net`, port **22**.

The following commands assume the SSH username is `larsbdw`. If the dashboard lists another user, replace the part before `@`. [SSH access](https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/).

## Step 6 — Upload the ZIP from your PC

**Where: Windows PowerShell on your own PC.**

1. Open the Windows Start menu.
2. Type **PowerShell** and open it. Administrator access is not needed.
3. Paste this entire command and press Enter:

```powershell
scp "C:\Users\eajde\OneDrive\Documenten\ChatGPT\Refurbished website\dist\laptivo-alwaysdata-upload.zip" larsbdw@ssh-larsbdw.alwaysdata.net:/home/larsbdw/
```

4. On the first connection, it may ask whether to trust the server. Compare the fingerprint with the SSH/SFTP page from Step 5. If it matches, type **yes** and press Enter.
5. Enter your **SSH password from Step 5** when asked. Nothing appears while you type a password; that is normal.
6. Wait for the transfer to finish.

**Done when:** the upload reaches 100% and the PowerShell prompt returns without an error. Keep the ZIP intact; you will extract it on the server.

## Step 7 — Connect and extract the ZIP

**Where: the same Windows PowerShell window.**

Run:

```powershell
ssh larsbdw@ssh-larsbdw.alwaysdata.net
```

Enter the SSH password when asked. After login, **this terminal is connected to alwaysdata**. Commands you type now run on the server.

**Where for the following commands: the connected server terminal.**

Run each line in order:

```sh
mkdir -p /home/larsbdw/laptivo
cd /home/larsbdw/laptivo
unzip /home/larsbdw/laptivo-alwaysdata-upload.zip
ls artisan
```

**Done when:** the last command prints **artisan**. That confirms the project is in the correct folder.

For this first installation the folder should be empty before extraction. If `unzip` asks to replace existing project files, stop: the folder already contains files and may need the update procedure instead. If you reconnect later, run `cd /home/larsbdw/laptivo` before continuing.

## Step 8 — Enter the database password

**Where: the connected server terminal, inside /home/larsbdw/laptivo.**

Run `ls -a` to list the files. For a first installation, **.env should not be listed**. `.env.example` is a different file and is expected.

If `.env` already exists, keep it and run `nano .env`; skip the copy command below. Otherwise, run these lines:

```sh
umask 077
cp deploy/alwaysdata/.env.example .env
chmod 600 .env
 

You are now in the **nano text editor**. Use the arrow keys to move. Check these existing lines:

```dotenv
APP_URL=https://larsbdw.alwaysdata.net
DB_HOST=mysql-larsbdw.alwaysdata.net
DB_PORT=3306
DB_DATABASE=larsbdw_shop
DB_USERNAME=larsbdw_app
DB_PASSWORD=""
```

Put your **database password from Step 4** between the quotation marks:

```dotenv
DB_PASSWORD="paste_your_actual_database_password_here"
```

Do not type the example text literally. Use the exact database/user/server values from your dashboard if they differ. In a double-quoted password, escape any `\`, `"` or `$` with a backslash. A random letters-and-numbers password avoids this extra step.

For this new empty database, leave `APP_KEY` blank: installation generates it. Keep the other prepared production, HTTPS, session, queue and preview settings as supplied. If using an existing database with encrypted records, retain its original APP_KEY instead.

To save and leave nano:

1. Press **Ctrl+O** — hold Ctrl and press the letter O.
2. Press **Enter** to confirm the filename `.env`.
3. Press **Ctrl+X**.

**Done when:** the server terminal prompt is visible again. Keep `.env` and passwords private.

## Step 9 — Install the website

**Where: the connected server terminal.**

Run:

```sh
sh deploy/alwaysdata/install.sh --demo
```

Wait for it to finish. It installs the PHP packages, creates the database tables and adds clearly labelled sample laptops.

**Done when:** the checks say **OK** and the final message begins **Installed**. Warnings about email previews and missing Google credentials are expected now.

If it fails, stop at this step and share the error text without passwords. Do not run the demo command again after a successful installation; the data is already present. If you want an empty catalogue, omit `--demo` on the first install.

## Step 10 — Create your website administrator

**Where: the connected server terminal.**

Run:

```sh
php artisan revilo:admin
```

1. At **Admin email**, type the email you want to use to log into the website and press Enter.
2. At **Password**, enter a new password of at least **12 characters**, with letters and numbers, and press Enter. Characters are hidden while typing.
3. Save that email/password privately.

**Done when:** the terminal says **Administrator created**. This is your website login, separate from your alwaysdata dashboard login.

## Step 11 — Connect the website address to the project

**Where: alwaysdata dashboard in your browser. Keep the server terminal open.**

1. Open **Web → Sites**.
2. Edit the existing site using **larsbdw.alwaysdata.net**. If no site uses it yet, click **Add a site**.
3. Enter or select these values:

| Field | Value |
| --- | --- |
| Name | `Laptivo preview` |
| Addresses | `larsbdw.alwaysdata.net` — without `https://` or `/nl` |
| Type | **PHP** |
| Root directory | `/home/larsbdw/laptivo/public` |
| PHP version | **8.4** |

4. Open the site's **SSL** section and tick **Force HTTPS**.
5. Leave **HTTP cache** disabled for this site if the option is shown.
6. Click **Save**.

**Done when:** the saved root directory ends in **/public**. This is essential: private project configuration stays outside the website. [Site settings](https://help.alwaysdata.com/en/docs/web-hosting/sites/add-a-site/), [HTTPS setting](https://help.alwaysdata.com/en/docs/web-hosting/sites/ssl-tls/redirect-http-to-https/).

## Step 12 — Add the background task

**Where: alwaysdata dashboard.**

1. Open **Advanced → Scheduled tasks**.
2. Click the button to add a task.
3. Select the **Command** type.
4. Paste this into the **Command** field:

```sh
sh /home/larsbdw/laptivo/deploy/alwaysdata/tick.sh
```

5. Set the frequency to **Every minute**. If there are five cron fields instead, enter `*` in each one, giving `* * * * *`.
6. Select your SSH user from Step 5 if asked.
7. Click **Save**.

Check it once manually in the **server terminal**:

```sh
sh deploy/alwaysdata/tick.sh
```

**Done when:** it returns without an error and the task's dashboard log shows a successful run. This runs Laravel's scheduler and processes queued work through a finite task, so no paid background service is needed. [Scheduled tasks](https://help.alwaysdata.com/en/docs/web-hosting/tasks/).

## Step 13 — Open and check your website

**Where: your browser.**

1. Open [your homepage](https://larsbdw.alwaysdata.net/nl).
2. Check that the banner, fonts and sample laptops appear.
3. Open [the login page](https://larsbdw.alwaysdata.net/nl/login).
4. Log in with your **website** email/password from Step 10.
5. Open [the admin page](https://larsbdw.alwaysdata.net/admin).

**Done when:** the homepage loads and your new administrator can access the admin area.

If you see the alwaysdata welcome page, recheck the site address and root directory in Step 11. For a **500** error, check the site's logs and PHP logs in the dashboard. For a database error, check the exact dashboard values against `.env`, then run `php artisan config:cache` after correcting them.

The preview does not accept payments or send emails to inboxes. Those need separate setup. Also check that HTTP redirects to HTTPS, `/up` loads, `/robots.txt` blocks indexing, and `/.env` cannot be downloaded. Hosted database, HTTPS and scheduled execution must still be verified on your account; the local tests do not prove deployment works.

## After the website works — enable Google login

In **Google Cloud Console → Google Auth Platform → Clients**, open your OAuth web client and add this exact **Authorized redirect URI**:

```text
https://larsbdw.alwaysdata.net/auth/google/callback
```

Save it. If Google asks for authorized-domain or consent changes, follow the [Google setup guide](google-setup.md). If it rejects the provider subdomain, resolve that requirement before enabling hosted sign-in.

In the **server terminal**, run `nano .env`, enter the Google client ID and secret in their existing fields, and check the callback matches the URI above. Save with Ctrl+O, Enter, Ctrl+X, then run:

```sh
php artisan config:cache
php artisan revilo:deployment-check
```

Try Google registration and login in your browser. The local Google secret is not included in the ZIP; enter it privately on the server. With `MAIL_MAILER=log`, newsletter and password-reset messages remain previews rather than inbox deliveries.

## Keep for later — backups, updates and Oracle

Download database backups and uploaded files regularly. Keep the server `.env` and its APP_KEY privately off the host. Back up the database, `storage/app` and `.env` together before updates. The Free offer advertises three days of backups. Do not put private backups in `public`.

For updates: back up, disable the scheduled task and let its current run finish, run `php artisan down`, upload the new code while preserving `.env` and storage, run the install script **without --demo**, then run `php artisan up` and re-enable the task after checks pass. Keep maintenance enabled if installation fails.

For the later Oracle move, transfer the source, database, uploads and **the same APP_KEY**. Test the MariaDB export on the new MySQL database before switching traffic. Update the server address, database settings, Google callback and proxy setting for the new host. The Oracle kit remains in `deploy/oracle`.

The template's `TRUSTED_PROXIES="*"` is for alwaysdata's managed proxy, which replaces the forwarded HTTPS protocol. Clear or adjust it when moving hosts. Before adding real email delivery, check CLI support for `pcntl` (`php -m`) for queue job timeouts. [alwaysdata HTTP proxy](https://help.alwaysdata.com/en/docs/web-hosting/sites/http-stack/).

Checked against the official documentation on 10 October 2026. Preparing this guide and ZIP does not itself upload the website or configure your hosting account.
