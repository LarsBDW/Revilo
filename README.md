# Revilo — Laravel + MySQL webshop

A real database-backed refurbished laptop shop using Laravel 12, PHP, MySQL 8.4, Blade, Tailwind 4, Vite and a small JavaScript frontend. Dutch is the default language; English and German use the same application and inventory.

The supplied Revilo logo, icon and homepage banner are included unchanged. Inter is self-hosted with its OFL licence. See [asset sources](docs/assets.md).

## Try the prepared local shop

Open **http://127.0.0.1:8000/nl**. Admin is **/admin**.

A private development administrator was generated. Open **storage/app/private/local-admin.txt** locally for its login. This file and all environment secrets are Git-ignored. There is no shared default password.

If the local processes have stopped, run from this directory:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1
```

This starts only project-owned localhost processes and checks the database and login page before reporting success. It does not alter XAMPP or Windows services. MySQL uses port **3307**, the webshop **8000**. The project-local portable MySQL runtime is ignored by Git and is not part of a deployment. Run this again after restarting Windows or when the local processes have stopped.

## What works

- Database product models with separate physical laptop units, encrypted private serial numbers, conditions, defects, battery records, tests, photos and movement history.
- Supported RAM, SSD, OS, grades, keyboards, batteries, chargers and warranty configuration. Fulfillable native or compatible component upgrades only. Server pricing, live compatibility controls and per-unit price snapshots.
- Combined stock-aware filters, sorting, autocomplete, pagination, quick view, wishlist, comparison, recently viewed products and a laptop finder.
- Database carts, a 30-day encrypted guest cart cookie, account cart merging, coupons and NL/BE/DE shipping. Germany costs €9.95; delivery is free from €250. Shipping rates remain editable in admin Settings.
- Local test checkout with atomic row locking, expiring reservations, idempotent order submission, immutable product/price snapshots and secure guest confirmation links.
- Account registration, password login/reset, verification links, saved addresses, order history and return requests. Checkout asks customers whether to use one of their saved addresses, with editable delivery fields. Google Socialite OAuth is implemented and configured for this local environment.
- Admin product, physical-unit, component, discount, customer, review/report, order, return, inquiry and content management. Role checks protect every action.
- CSV upload/mapping/preview, row errors, duplicate serial/ID checks, explicit skip/update confirmation, import history and CSV exports.
- Safe HTML pro forma documents with browser Print / Save as PDF. Test documents are never presented as paid tax invoices.
- Transactional email records/previews, double opt-in subscriptions, stock notices, confirmed cart reminders and scheduled newsletter campaigns. Marketing excludes unconfirmed or unsubscribed recipients.
- Server-rendered localized metadata, canonicals, hreflang, product structured data, sitemap and robots.
- Financial snapshots, actual order-cost entry, paid-order reports and product/daily/monthly performance. Test/unpaid orders never enter revenue.

The seed contains **6 models and 18 clearly labelled demo units** plus compatible sample parts. Photographs are manufacturer model references, not photographs of these demo units. No reviews, certifications or passed tests are fabricated.

## Fresh installation

For the prepared alwaysdata Free preview package and dashboard steps, follow [alwaysdata setup](docs/alwaysdata-setup.md). Run `scripts/package-alwaysdata.ps1` to build an upload ZIP with frontend assets and without local secrets or data. Server credentials and Google callbacks are configured separately after upload.

Requirements: PHP 8.2+ with PDO MySQL, OpenSSL, mbstring, fileinfo, curl, DOM/XML and GD; Composer 2; Node 20.19+; MySQL 8.0+ (8.4 used here). Use a supported patched PHP runtime for deployment.

```sh
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Configure a dedicated MySQL database and restricted application user in .env. Use a separate **revilo_testing** database for tests. Never use the MySQL root account in the app.

With Docker, set DB_PASSWORD and MYSQL_ROOT_PASSWORD to strong private values, then:

```sh
docker compose up -d mysql
php artisan migrate
php artisan db:seed
php artisan storage:link
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Alternatively, use your own MySQL server and adjust host/port. The prepared Windows runtime already has isolated revilo and revilo_testing databases. scripts/initialize-mysql.php is a one-time bootstrap for a freshly initialized insecure portable instance; do not rerun it against an existing secured server.

Run these in separate terminals for background jobs:

```sh
php artisan queue:work --tries=3
php artisan schedule:work
```

The build uses public assets directly, so Vite may report that /assets/hero-warm.png and the Inter font will resolve at runtime. Both files exist in public/assets.

## Administrator setup

```sh
php artisan revilo:admin your-admin@example.com
```

The command prompts privately for a password. It refuses to elevate or reset an existing account. On local development only, **php artisan revilo:admin --local** generates a random password into the private login file. Production has no automatic admin seed.

Log in through /nl/login, then open /admin. Administrators can view customer orders; customers can access only their own data. Guest orders need a signed temporary link.

## Inventory workflow

1. Create a product model, supported sizes/OS options, localized description and SEO data. Keep it unpublished while preparing it.
2. Register each physical laptop independently, with its inventory ID, private serial, specs, grade, costs, VAT treatment and condition photos.
3. Record the 16-point test checklist honestly. Unknown measurements remain unknown. A failed test is displayed as a failure.
4. Add actual compatible RAM kits, SSDs, batteries and chargers under Components, including stock, cost, upgrade price and model IDs.
5. Mark an inspected laptop available, then publish its model. Unpublish models to archive public listings; use archived for retired units.
6. Reserved/sold units cannot be edited through the ordinary inventory form. Order cancellation releases active reservations; inspected returns go to awaiting_inspection for retesting.
7. Changes to parts already reserved cannot invalidate their type/capacity/compatibility.

A RAM component represents a complete compatible replacement kit of the specified capacity. Compatibility is explicitly maintained per model; verify actual part characteristics before making real stock available.

The online allocation limit is 50 physical units per order. Larger orders use business inquiries. Allocation assigns constrained configurations first and backtracks up to 10,000 choices; complex batches can be reviewed through the business workflow. All writes use transactions and deterministic stock locks.

## CSV import

Use /admin/inventory/import and download the template. Files must be UTF-8, comma or semicolon delimited, up to 5 MB / 5,000 rows. European decimal commas and thousand separators are supported for prices. Map any custom headers, recalculate the preview, inspect errors and explicitly choose duplicate handling.

Existing reserved/sold units cannot be overwritten. Duplicate serials belonging to different units are rejected. Invalid rows are reported and skipped; each valid row imports transactionally. Missing models are grouped by brand/model slug and remain unpublished.

## Google login

Follow the [step-by-step Google Cloud setup guide](docs/google-setup.md) to create a Google OAuth web client and configure GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI. The local callback is:

**http://127.0.0.1:8000/auth/google/callback**

Both login and registration have dedicated Google buttons. New users confirm the Revilo terms before a verified customer account is created; returning users sign in directly. Existing password accounts can explicitly link the matching Google identity in their profile after password login. Google signup does not subscribe anyone to marketing or grant admin access.

Register the exact production HTTPS callback separately. Socialite checks OAuth state and PKCE, and only a verified Google email is accepted. Pending signup identity data expires after ten minutes; Google tokens are not stored. When credentials are missing, the UI explains that setup is pending and provides the email alternative. The prepared local environment has private Google credentials configured; a full human Google sign-in round trip still needs to be completed.

## Email and consent

MAIL_MAILER=log or array creates database previews under /admin/emails and **does not send email**. Verification and reset links can be opened from these previews.

For SMTP, configure host, port, scheme, username/password and verified sender details, then run the queue worker. Delivery records show queued, sent or failed; sent means the configured transport accepted the message, not confirmed inbox delivery. Provider bounce/open analytics are not fabricated.

Newsletters and cart reminders require an explicit consent form followed by a signed confirmation. Campaigns can be drafted or scheduled for all confirmed subscribers or a language segment. Each recipient gets an unsubscribe link. Stock notices match requested RAM/SSD/grade and are recorded only once per subscription. Cart reminders are staged at 30 minutes, 24 hours and 72 hours, skip unavailable carts and use idempotency keys. Restoring a cart does not reserve it.

```sh
php artisan revilo:expire
php artisan revilo:marketing
```

The scheduler runs expiry each minute and marketing every five minutes, with overlap protection. Do not enable a real mail transport until sender identity, consent records and provider handling are verified.

## Money, VAT and reporting

All persisted money uses integer cents. Product discounts and coupons are revalidated on the server. Shipping and warranty tariffs are editable under admin Settings. Changing current products or rates does not alter prior order snapshots.

Each unit uses standard or margin VAT treatment. The development calculation estimates the VAT component using 21/121 of VAT-inclusive consideration; margin calculations use the positive sale-minus-purchase margin. It is an estimate for this prototype, not formal accounting or a determination of eligibility. Cross-border VAT, deductible input VAT, margin-scheme eligibility, invoice wording and treatment of upgrades/services must be reviewed with your accountant before commercial use. [Dutch Tax Administration: margin scheme](https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/btw/btw_berekenen_aan_uw_klanten/margeregeling/).

Actual packaging, shipping and payment fees can be recorded per order in cents. Reports subtract recorded unit costs and VAT and exclude unpaid/test/cancelled/refunded orders. General overhead and provider reconciliation need your accounting integration.

## Tests

```sh
php artisan test
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
```

The standard suite uses isolated in-memory SQLite. The MySQL suite uses **revilo_testing**, reads its private password from .env, and may reset that testing database. It never resets revilo.

The MySQL concurrency test launches six independent PHP processes behind a shared start gate, all attempting to reserve one physical laptop and one component. One must succeed, five must be rejected, and reserved stock must remain one.

Integration tests cover multilingual pages, admin authorization, private data, compatibility, filtering, cart totals, price tampering, coupons, snapshot preservation, signed order access, complete HTTP purchase/fulfillment, returns, marketing consent and unsubscribe. Browser QA is recorded in docs/verification.md.

## Production deployment and pending external setup

For a €0 public preview on Oracle's Always Free server, use the [step-by-step Oracle guide](docs/oracle-setup.md). The container setup provides automatic HTTPS, private MySQL, persistent storage, a queue worker, scheduler, configuration checks and a backup command. It creates separate server credentials and keeps email, checkout and search indexing in preview mode. The guide identifies the free limits and the account/VM steps that still require your own setup.

Deploy on a PHP-FPM/Nginx or Apache host, with the web root set to **public/**. Cloudflare Workers cannot execute this PHP application. Build assets in CI, install Composer dependencies with --no-dev --optimize-autoloader, provide secrets outside Git and run migrations against the intended database.

Use APP_ENV=production, APP_DEBUG=false, HTTPS APP_URL, SESSION_SECURE_COOKIE=true and TEST_CHECKOUT_ENABLED=false. Configure trusted proxies for your actual host, ensure only storage/framework and storage/logs plus private/public upload directories are writable, run queue workers with a process supervisor and run **php artisan schedule:run** each minute. Back up the database, private invoice/email data, images and APP_KEY securely. Changing APP_KEY loses access to encrypted serials/sessions.

After setting the environment:

```sh
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Before opening:
- Replace demonstration inventory with genuinely inspected units and verified compatible parts, licensed software and actual unit photos.
- Supply company identity, address, registration/VAT number, support contacts, social links and reliable delivery terms.
- Review the editable legal drafts and withdrawal/return instructions for NL/BE/DE. Commercial warranty does not replace statutory rights. [EU consumer information](https://europa.eu/youreurope/citizens/consumers/shopping/guarantees/index_en.htm).
- Configure and verify Google credentials and a real mail provider; establish bounce/suppression handling, retention and consent procedures.
- Complete production VAT/accounting configuration and formal invoices.
- Implement the chosen payment provider with a signed, idempotent webhook and server-side amount/currency matching. Only a verified provider event may set paid_at, provider_confirmed or real paid revenue. Never copy the local simulation into a live payment confirmation.
- Connect real shipping/refund provider operations, monitoring and backups. Current tracking/refunds are recorded by admins, not carrier or payment API calls.
- Perform production security/accessibility and operational review on the real hosting setup.

**No live payments are implemented or accepted.** Local test fulfillment deliberately leaves provider_confirmed=false and paid_at empty.

## Marketplacebeheer

De admin bevat nu Marketplaces op /admin/marketplaces. Nieuwe laptops worden tegelijk in de Revilo-catalogus en fysieke voorraad opgeslagen. Bestaande voorraad kan zonder duplicaten gekoppeld worden. Eigen OAuth-gegevens beheer je in de admin. Zie [docs/marketplaces.md](docs/marketplaces.md) voor API-toegang, beperkingen en deployment. Na het pullen: voer de nieuwe migratie uit en bouw de Vite-assets opnieuw.
