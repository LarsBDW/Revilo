# Verification — 9 October 2026

Verified on the prepared Windows environment with Laravel 12.69.3, PHP 8.2.12, MySQL 8.4.11, Node 24.11.1 and Vite 7.3.7. The application uses a restricted database user; all destructive test migrations run against **revilo_testing**, separate from the preview database **revilo**.

## Automated checks

- Final MySQL suite after Google authentication: **54 tests passed, 421 assertions**, including a real race between six independent PHP processes for one laptop and one compatible RAM kit. Exactly one reservation succeeds and five attempts are rejected. Run: `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`.
- Earlier SQLite suite: 34 tests passed, 238 assertions; the MySQL process-race test was skipped as intended. The subsequently added capacity-search test and latest translated storefront changes also passed focused checks on SQLite and MySQL.
- Coverage includes all public pages in Dutch, English and German, inventory compatibility, combined cart allocation, price tampering, coupons, authorization and private serial data, signed guest orders, immutable snapshots, checkout and fulfillment, inspected returns, CSV errors/duplicate choices, consent, unsubscribe and scheduled marketing.
- `npm run build` passed. Runtime references to the supplied banner and self-hosted Inter font resolve from `public/assets`.
- Laravel Pint formatted the PHP source. `scripts/health.php` confirmed the configured MySQL connection. `scripts/start-local.ps1` was executed successfully.
- Environment secrets, the private administrator login, database files and application logs are ignored by Git.

## Browser checks

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

A real Google authorization round trip was not performed because OAuth client credentials are still empty. Follow [Google setup](google-setup.md) to activate it; no fake provider is exposed by the application.

## Remaining launch setup

This is a working local webshop with demonstration inventory and test checkout. Google OAuth awaits client credentials. Real email delivery awaits a configured mail transport; local messages appear in admin previews. Live payment, carrier and refund provider APIs, production accounting/invoices, real inspected stock and company/legal details remain launch setup work described in [README](../README.md). Test orders deliberately never become provider-confirmed revenue.

These checks verify the implemented local flows. They do not claim third-party delivery, production hosting, a full accessibility audit or a production security assessment.
