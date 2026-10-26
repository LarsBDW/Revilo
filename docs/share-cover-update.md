# Update the link-preview image

The cleaned cover image has no small caption at the bottom left. The homepage and other general storefront pages use it in Open Graph and Twitter sharing metadata. Individual product links retain their product photos. The page's visible homepage banner is unchanged.

## On your Windows PC

Open PowerShell. If the prompt shows `larsbdw@ssh2`, run `exit` first so you return to `PS C:\...`.

```powershell
scp "C:\Users\eajde\OneDrive\Documenten\ChatGPT\Refurbished website\dist\laptivo-share-cover-update.zip" larsbdw@ssh-larsbdw.alwaysdata.net:/home/larsbdw/
ssh larsbdw@ssh-larsbdw.alwaysdata.net
```

Enter your SSH password when asked and let the upload finish before connecting.

## Inside the connected server terminal

Run each line separately. Stop if any command fails.

```sh
cd /home/larsbdw/laptivo
cp resources/views/layouts/store.blade.php /home/larsbdw/store-layout-before-share-cover-$(date +%Y%m%d-%H%M%S).blade.php
php artisan down
unzip -o /home/larsbdw/laptivo-share-cover-update.zip -d /home/larsbdw/laptivo
php artisan view:cache
php artisan up
```

The image and sharing metadata must be on the live server before a messaging app can see them. Open `https://larsbdw.alwaysdata.net/assets/share-cover-v1.png` to check the uploaded image, then share the homepage link again. Messaging apps may retain an old preview temporarily. Try a fresh link such as `https://larsbdw.alwaysdata.net/nl?share=cover1` if the normal URL still shows its previous thumbnail; it points to the same homepage. Preview refresh timing and whether a preview is shown are controlled by the messaging app.

This package includes the warm logo and all five updated logo templates as well, so it can be installed after either earlier update. It contains no environments, accounts, database contents or uploaded product photos.
