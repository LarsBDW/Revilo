# Install the warm logo on alwaysdata

The local website now uses the new transparent warm logo in the header, footer, admin sidebar, error pages and invoice page. This small update changes the logo image and five Blade templates. No database change, dependency installation or frontend build is needed.

## 1. Upload from your Windows PC

Use **Windows PowerShell**, with a prompt starting `PS C:\...`. If you are connected to alwaysdata and see `larsbdw@ssh2:...`, run `exit` first.

```powershell
scp "C:\Users\eajde\OneDrive\Documenten\ChatGPT\Refurbished website\dist\laptivo-warm-logo-update.zip" larsbdw@ssh-larsbdw.alwaysdata.net:/home/larsbdw/
```

Enter the SSH password and wait for the upload to finish. Then connect:

```powershell
ssh larsbdw@ssh-larsbdw.alwaysdata.net
```

## 2. Apply on the server

The following commands run **inside the SSH session**. Run each line separately and stop if a command fails.

```sh
cd /home/larsbdw/laptivo
tar -czf /home/larsbdw/warm-logo-backup-$(date +%Y%m%d-%H%M%S).tar.gz resources/views/layouts resources/views/errors resources/views/store/invoice.blade.php
php artisan down
unzip -o /home/larsbdw/laptivo-warm-logo-update.zip -d /home/larsbdw/laptivo
php artisan view:cache
php artisan up
```

The original blue logo file is preserved. Server credentials, accounts, products, orders and uploaded photos are not included or overwritten by this package. Scheduled-task code is not changed; the existing task skips new runs while maintenance mode is active.

## 3. Check

Open [the live website](https://larsbdw.alwaysdata.net/nl) and press **Ctrl+F5**. The logo should have warm brown accents and charcoal text. Check the footer and admin sidebar as well.

If you previously paused the scheduled task for the product update, set it back to **Active** after checking the website.
