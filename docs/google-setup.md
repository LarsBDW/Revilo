# Enable Google login and registration for Revilo

The webshop code is ready for Google login, registration and explicit account linking. Google must first issue an OAuth **Web application** client ID and client secret for your project. This guide uses Google's current [OAuth web-server documentation](https://developers.google.com/identity/protocols/oauth2/web-server).

## 1. Create the Google project

Open [Google Cloud Console](https://console.cloud.google.com/). Sign in to the Google account that should own Revilo's configuration. Use the project selector to create a project called **Revilo** or select your existing project. Open **Google Auth Platform** and start its setup if prompted.

## 2. Configure the app information

Under **Branding**, enter **Revilo**, your support email and your developer contact email. Choose **External** under **Audience** if customers outside your Google Workspace organisation should be able to sign in. [Google's consent-screen configuration](https://developers.google.com/workspace/guides/configure-oauth-consent).

For local development, keep the app in testing while setting it up. Revilo requests only **openid**, **profile** and **email**. Google's basic-identity exception means users do not need to be added to the test-user list for these scopes alone. No Gmail, Drive, Contacts or Calendar permissions are needed. If you add other scopes later, review the changed audience/testing requirements. [Google's audience and testing rules](https://support.google.com/cloud/answer/15549945?hl=en).

For the public shop, complete the required app information, actual domain, homepage, privacy and terms links, and production audience settings. Follow any verification requirements shown by Google for your configuration. [Google OAuth policies](https://developers.google.com/identity/protocols/oauth2/policies).

## 3. Create the web client

Open **Clients → Create client**. Select **Web application**, name it **Revilo local**, and add this exact **Authorized redirect URI**:

```text
http://127.0.0.1:8000/auth/google/callback
```

Keep the spelling, port and path exactly as shown, with no trailing slash. Google allows HTTP for local loopback addresses. This application uses a server-side redirect flow, so it does not need a browser JavaScript client or extra JavaScript origins. Create the client and save the **Client ID** and **Client secret** when Google displays them. Use Google's secure download if offered; do not put a credentials file in `public/` or commit it to Git. [Google's client creation and redirect rules](https://developers.google.com/identity/protocols/oauth2/web-server#creatingcred).

## 4. Enter the two values locally

Open the project's private **.env** file. Replace the empty Google entries with your values:

```dotenv
GOOGLE_CLIENT_ID="YOUR_CLIENT_ID.apps.googleusercontent.com"
GOOGLE_CLIENT_SECRET="YOUR_CLIENT_SECRET"
GOOGLE_REDIRECT_URI="http://127.0.0.1:8000/auth/google/callback"
```

The example values above are placeholders. Keep the real secret in `.env`, which is ignored by Git, and do not paste it into a public message. The secret stays on the Laravel server; it is never added to the browser bundle.

From a PowerShell terminal in this project directory, reload Laravel's configuration:

```powershell
& 'C:\xampp\php\php.exe' artisan config:clear
```

If you use a different PHP installation, run `php artisan config:clear` instead. Restart any queue workers after changing production configuration. The prepared local web preview reads the updated configuration on its next request.

## 5. Try both flows

Use **http://127.0.0.1:8000/nl** consistently when testing; `localhost` uses a different browser session from `127.0.0.1`.

1. Open **/nl/register → Registreren met Google**. Choose your Google account. A new customer sees their verified name/email and confirms the Revilo terms before account creation. No separate password is required; marketing consent is not enabled by this step.
2. Sign out, then open **/nl/login → Inloggen met Google**. The same identity signs in to the existing account without making a duplicate. Your cart follows the account.
3. If that email already has a password account, sign in with its password first, then use the Google button under **Mijn account → Google-account**. Choose the same email to link it explicitly. Google registration never grants administrator privileges.

Cancel Google consent once to check that the shop returns to the local login/registration page. If `redirect_uri_mismatch` appears, compare the Google client's redirect URI with `GOOGLE_REDIRECT_URI` character for character. An expired session can be restarted with the Google button.

## 6. Configure production separately

Use a separate production OAuth web client and the actual HTTPS callback, such as **https://YOUR-DOMAIN/auth/google/callback**. Set the production client values and HTTPS `APP_URL`, configure secure session cookies, and cache configuration after deployment. The local callback does not become a production callback automatically.

Google OAuth state and PKCE are enabled through [Laravel Socialite](https://laravel.com/framework/docs/12.x/socialite). Only verified Google identities are accepted. Access and refresh tokens are not stored; new signup identity data is held in the server session for ten minutes. Disabled accounts remain disabled, and existing password accounts require explicit linking by their signed-in owner.

Automated tests use fictional Google responses through a Guzzle mock and the real Socialite provider. A real Google round trip still needs your client credentials and your own browser consent.
