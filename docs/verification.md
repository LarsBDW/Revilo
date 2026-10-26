# Verification — 10 October 2026

Verified on the prepared Windows environment with Laravel 12.69.3, PHP 8.2.12, MySQL 8.4.11, Node 24.11.1 and Vite 7.3.7. The application uses a restricted database user; all destructive test migrations run against **revilo_testing**, separate from the preview database **revilo**.

## Automated checks

- Latest MySQL suite after the product configurator redesign: **80 tests passed, 690 assertions**, including a real race between six independent PHP processes for one laptop and one compatible RAM kit. Exactly one reservation succeeds and five attempts are rejected. Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`.
- Earlier SQLite suite: 34 tests passed, 238 assertions; the MySQL process-race test was skipped as intended. The subsequently added capacity-search test and latest translated storefront changes also passed focused checks on SQLite and MySQL.
- Coverage includes all public pages in Dutch, English and German, inventory compatibility, combined cart allocation, price tampering, coupons, authorization and private serial data, signed guest orders, immutable snapshots, checkout and fulfillment, inspected returns, CSV errors/duplicate choices, consent, unsubscribe and scheduled marketing.
- `npm run build` passed. Runtime references to the supplied banner and self-hosted Inter font resolve from `public/assets`.
- Laravel Pint formatted the PHP source. `scripts/health.php` confirmed the configured MySQL connection. `scripts/start-local.ps1` was executed successfully.
- Environment secrets, the private administrator login, database files and application logs are ignored by Git.

## Admin product image removal

The product and individual laptop editors now offer an accessible removal form per photo, using the existing JavaScript confirmation handler. Requests require an authenticated administrator and the normal CSRF token. Images are identified by their stored URL, so retrying a stale form cannot remove a different photo. Uploads and removals lock the edited record, and ordinary product saves preserve the current images after acquiring that lock.

Deleting the first image promotes the next photo; deleting the last uses the existing storefront placeholder. Only unreferenced uploads under the public disk's `photos` directory can be physically deleted. References from other models, inventory units and historical order snapshots protect shared files; bundled assets, external URLs and traversal paths cannot trigger filesystem deletion.

- Eight new MySQL feature tests passed with 83 assertions; the complete suite passed with **78 tests and 616 assertions**.
- PHP syntax checks, formatting of the new tests, whitespace checks and the production frontend build passed. Inter and the warm banner are present at the runtime paths referenced by the build.
- Prepared `dist/laptivo-image-removal-update.zip` with the changed source, built assets and an update guide. It excludes environments, uploads, database contents and dependencies. No schema change or new dependency is required.
- The live host was not updated: the read-only SSH connection could not authenticate with the available keys. Follow [the image-removal update guide](image-removal-update.md) to upload using the account's SSH password.

## Earlier browser checks

Used the Codex in-app browser against **http://127.0.0.1:8000/nl**. Desktop layout was checked at 1440 × 960 and mobile at 390 × 844.

- Homepage shows the supplied Revilo brand, banner and icon. The computed body font is Inter. No broken `<img>` assets or captured browser warnings/errors were found during the checked flow.
- Mobile menu exposes the catalogue and account link. The mobile filter drawer accepts 32 GB RAM and returns six currently fulfillable model configurations, each displaying 32 GB RAM. Checked homepage and catalogue widths have no horizontal overflow.
- Selecting 32 GB RAM and a 1 TB SSD updates the product price to **€247.76**. The cart retains the same configuration and server price.
- Checkout changes shipping from the Netherlands fee (€6.95) to the Belgium fee (€9.95), producing a total of **€257.71** for that configuration.
- Submitted a local test order with fictional details: **RV-261009-QKQJAG**. The confirmation displays the configuration, total, reservation deadline and awaiting-payment status. No real charge or outbound email was made. Reservations expire through the project scheduler.

Screenshots: [desktop homepage](homepage-desktop.jpg), [mobile homepage](homepage-mobile.jpg), [test order confirmation](test-order.jpg).

## Google authentication follow-up

Added distinct localized Google login and registration buttons, a signup confirmation with server-verified identity and recorded terms acceptance, and explicit Google linking in the account profile. New Google accounts remain customers. Returning users preserve their intended destination and cart.

Eighteen Google tests use the real Socialite Google provider with fictional Guzzle responses. They cover OAuth state/PKCE, consent, returning customers, linking, matching email requirements, expired/cancelled flows, disabled/unverified identities, concurrent signup changes and missing credentials. The final complete MySQL suite above includes these tests. Google routes suppress referrers and private authentication pages use no-store caching.

Browser checks confirmed login and registration buttons, official Google icon, Inter font, the missing-credentials alternative, and no mobile horizontal overflow at 390 × 844. No browser warnings/errors were captured. Screenshots: [Google login](google-login.jpg), [Google registration](google-register.jpg), [mobile registration](google-register-mobile.jpg).

A Google web client is now configured privately in the local environment. The official authorization page identifies Revilo, and the token endpoint accepts the client credentials (a deliberately invalid authorization code returns invalid_grant rather than invalid_client). A full human Google sign-in round trip was not automated. Follow [Google setup](google-setup.md) for a fresh or production environment; no fake provider is exposed by the application.

## Germany and saved delivery addresses

Germany is available in account addresses, cart, checkout and business inquiries, with €9.95 shipping and the existing €250 free-shipping threshold. Admin Settings can change the German fee. Shared postcode validation accepts German five-digit codes including leading zeroes, and validates Dutch and Belgian formats.

Checkout shows only the signed-in customer's saved addresses and asks whether to use one. Selecting an address fills the editable delivery fields and recalculates shipping; choosing a different address clears those fields. Editing an order address does not change the saved account address. Address selection also works through server forms when JavaScript is unavailable.

Eight added MySQL tests cover German delivery, leading-zero postcodes, invalid postcodes, free shipping, changing admin rates, own-address access, cross-account rejection and order/account address separation. The complete suite passed with 62 tests and 502 assertions.

Browser checks exercised Dutch and German saved addresses, contact-field preservation, changing to a different address, live shipping totals and the German language switch. Desktop and 390 × 844 mobile layouts were checked; the mobile checkout had no horizontal overflow and no captured browser warnings/errors. The fictional preview customer, cart and addresses were removed after verification; no browser order was placed in this follow-up.

Screenshots: [saved-address checkout](saved-address-checkout.jpg), [German mobile checkout](saved-address-checkout-mobile.jpg).

## Warmer homepage banner

The user-supplied `homepage banner new.png` replaced the original hero image. The served file is an exact copy, verified with SHA-256. The homepage and default social share image use the new asset URL, and mobile readability overlays use matching cream tones. The asset build passed; the homepage and image both returned HTTP 200. The desktop preview shows the new image, and a 390-pixel mobile layout check confirmed the warm overlay and no horizontal overflow. The normal viewport was restored afterward.

Screenshot: [warmer homepage](homepage-warm-desktop.jpg).

## Local server recovery

On 10 October, the login URL was unavailable because neither the Laravel web server nor the project database was running. The existing local start script restored MySQL on 3307 and the webshop on 8000. It now checks the login page before reporting success. The restored login page was verified in the browser. The separate XAMPP database was left running. These are local development processes and must be started again after a Windows restart.

## Oracle Always Free preparation

Added an ARM-compatible PHP-FPM/MySQL/Caddy deployment kit, queue worker, scheduler, persistent volumes, private server credential initialization, update maintenance handling and a backup command. The server configuration disables debug output, requires HTTPS and secure encrypted sessions, keeps checkout disabled, and uses email previews. Public preview responses use noindex headers and a matching Laravel robots route. The old static robots file was removed so the web server cannot bypass these dynamic rules; the local HTTP endpoint returned the expected Laravel sitemap/rules afterward.

- The complete isolated MySQL suite passed with 67 tests and 523 assertions. Five new tests cover preview indexing, server configuration checks, Google callback consistency and guarded demo seeding. A focused repeat after removing the static robots file passed all four deployment tests.
- PHP formatting, all five Linux shell syntax checks and Docker Compose configuration validation passed. Server initialization checks covered invalid hostnames, fresh independent encryption/database credentials and refusing to overwrite existing credentials. No credential values were printed.
- The Windows packager excludes local environments, databases, private storage, dependency folders and caches, and normalizes Linux shell scripts to LF. Its source archive and checksum are created in the ignored `dist` folder.
- A container build and runtime smoke test could not run locally: Docker Desktop did not start, and the existing WSL distribution refers to a missing disk. Windows/WSL configuration was not changed to repair that separate issue. These checks remain required on the new Oracle server.

No Oracle account, cloud VM, DNS record, public certificate or public deployment was created during this preparation. The first server build, external HTTPS and hosted Google sign-in still need verification after the user completes signup and creates the server. Follow [Oracle setup](oracle-setup.md).

## alwaysdata preparation

Added a server environment template for `larsbdw.alwaysdata.net`, installation helper, a finite locked scheduler/queue task and a step-by-step dashboard guide. The separate upload packager builds frontend assets locally, includes their manifest, and excludes local credentials, databases and private storage. The application accepts a configurable hosting proxy for HTTPS recognition and trusts only the forwarded protocol header. Local requests retain an empty proxy setting.

- All 70 isolated MySQL tests passed with 533 assertions, including three new checks for direct requests, HTTPS behind the hosting proxy and refusing forwarded host/IP changes. The seven focused deployment tests, PHP formatting/syntax, both Linux shell syntax checks and the frontend build also passed.
- The upload archive was checked for compiled asset references, expected public images/fonts, current source contents, LF shell scripts, checksum consistency and absence of private environments, local databases, credentials and customer storage.
- MariaDB compatibility was reviewed in the application queries/configuration, but the application has not been executed against alwaysdata's managed database. Server Composer installation, migrations, HTTPS, Google sign-in and scheduled execution remain hosted verification work.

No account configuration or upload was performed on alwaysdata. Follow [alwaysdata setup](alwaysdata-setup.md). The existing design and branding were not changed.

## Remaining launch setup

This is a working local webshop with demonstration inventory and test checkout. Production Google OAuth requires its own approved callback and configuration; the local client is configured. Real email delivery awaits a configured mail transport; local messages appear in admin previews. Live payment, carrier and refund provider APIs, production accounting/invoices, real inspected stock and company/legal details remain launch setup work described in [README](../README.md). Test orders deliberately never become provider-confirmed revenue.

These checks verify the implemented local flows. They do not claim third-party delivery, production hosting, a full accessibility audit or a production security assessment.

## Product configurator redesign

Replaced condition, storage, RAM and warranty dropdowns with accessible radio cards styled with the existing cream background, brown accent and Inter font. Operating system and keyboard remain dropdowns; additional battery and charger choices are checkboxes. The layout follows the supplied reference while keeping the existing supported capacities and 12/24-month commercial warranties.

Option prices are calculated from real candidate inventory and compatible components. Condition cards show configuration totals; other cards show the difference from the cheapest currently fulfillable option in that group. Availability and displayed prices refresh together, including failed configuration responses. Server validation and cart pricing remain authoritative.

Two new MySQL tests verify every option's price against an actual quote, unavailable RAM, warranty pricing and selected radio-card rendering in Dutch, English and German. The full suite passed with **80 tests and 690 assertions**. The production asset build and PHP formatting passed.

Browser checks exercised 32 GB RAM and a 1 TB SSD: the displayed total changed from €137.76 to €192.76 and then €247.76. The 390 × 844 mobile layout had no horizontal overflow, and all choice cards stayed within the viewport. The viewport was restored. Screenshots: [desktop product](product-options-desktop.png), [mobile options](product-options-mobile.png).

Prepared and verified `dist/laptivo-product-update.zip`, containing the product redesign and earlier admin image-removal change. All 16 archive entries match their source files and the built manifest references included assets. No server environment, database contents, uploads or dependencies are included. The live site still requires uploading the package using [the updated guide](product-layout-update.md).
