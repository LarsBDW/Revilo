# Add image removal to the live website

The product editor now shows **Verwijderen** below every photo. Click it and confirm to remove that photo immediately. This also works for individual laptops under **Laptopvoorraad**. The next photo becomes the main image when you remove the first one; a product without photos shows the existing placeholder.

This update has no database migrations or new dependencies. It updates the editor, controller, routes and built frontend assets. It preserves your server `.env`, accounts, products, orders and uploads. Unused uploaded photos are deleted when removed; files still used by another product, laptop or past order stay available.

## 1. Upload from your PC

Open **Windows PowerShell on your PC** and run:

```powershell
scp "C:\Users\eajde\OneDrive\Documenten\ChatGPT\Refurbished website\dist\laptivo-image-removal-update.zip" larsbdw@ssh-larsbdw.alwaysdata.net:/home/larsbdw/
```

Enter your alwaysdata **SSH password** when prompted. The upload should reach 100%.

## 2. Connect to the server

In that same PowerShell window, run:

```powershell
ssh larsbdw@ssh-larsbdw.alwaysdata.net
```

Enter your SSH password. You are now connected to the server.

## 3. Pause the scheduled task

In the **alwaysdata dashboard → Advanced → Scheduled tasks**, disable the webshop task temporarily. Let any current run finish before continuing.

## 4. Back up the files being updated

In the **connected server terminal**, run each line in order:

```sh
cd /home/larsbdw/laptivo
tar -czf /home/larsbdw/image-removal-backup-$(date +%Y%m%d-%H%M%S).tar.gz app/Http/Controllers/AdminController.php routes/web.php resources/views/admin/form.blade.php resources/css/app.css public/build
php artisan down
```

Continue only if the backup completes without errors and Laravel confirms maintenance mode is enabled. The backup is outside the public website folder.

## 5. Apply the update

Still in the **connected server terminal**, run these lines one at a time:

```sh
unzip -o /home/larsbdw/laptivo-image-removal-update.zip -d /home/larsbdw/laptivo
php artisan route:clear
php artisan view:cache
php artisan up
```

Continue to each next line only if the previous command succeeds. If any command fails, keep maintenance mode enabled and use the error to resolve it before running `php artisan up`.

## 6. Check the editor

1. Open [the admin page](https://larsbdw.alwaysdata.net/admin) and sign in.
2. Go to **Producten**, then edit a product.
3. Scroll down to **Modelafbeeldingen**. Each photo should have a **Verwijderen** button.
4. Check removal using an unwanted photo. Deletion takes effect after you confirm; you do not need to click **Opslaan**.
5. Check the product on the storefront.
6. Re-enable the scheduled task in alwaysdata.

If the editor still looks old, reload with **Ctrl+F5**. If the update needs to be reverted, keep maintenance mode enabled, extract the file backup into `/home/larsbdw/laptivo`, run `php artisan route:clear` and `php artisan view:cache`, then bring the site back up. The code backup does not restore photos deliberately removed through the editor; use your normal uploaded-file backups for those.
