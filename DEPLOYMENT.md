# DMD Resort Deployment

## Production
- Frontend: React + Vite → Vercel
- Backend: Laravel → Railway
- Database: PostgreSQL → Railway
- Uploaded files: Laravel persistent storage / Railway Volume

## Deployment Rules
- Never replace the production database for normal code updates.
- Use Laravel migrations for schema changes.
- Do not run `migrate:fresh` in production.
- Uploaded accommodation and branding images must use persistent storage.
- Frontend must communicate with the Railway Laravel API.

## Local Development
- Backend: D:\dmdresort\backend
- Frontend: D:\dmdresort\frontend
- Database: dmdresort

## Production Database
- Database name: railway
- Never store passwords or secrets in this Markdown file.

## Production uploaded-file storage

Laravel writes all accommodation, branding, hero, logo, About image, and other public uploads to the `public` disk at `storage/app/public`. Attach the Railway Volume to the Laravel service with this exact mount path:

```text
/app/storage/app/public
```

Do not mount the Volume at `/app/storage`, because that can hide Laravel's framework storage directories. Do not mount it at `/storage` or `/app/public/storage`; those paths will not be the disk root used by the application.

Set these production variables (values are environment-specific):

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-railway-backend-domain>
FILESYSTEM_DISK=public
```

`APP_URL` must be the public HTTPS URL of the Laravel Railway service. It is used to generate `/storage/...` URLs for the Vercel frontend.

Use this as the Railway service Start Command, or prepend the first two commands to the existing start command:

```sh
mkdir -p storage/app/public && php artisan storage:link --force && php artisan serve --host=0.0.0.0 --port="$PORT"
```

The Volume is mounted at runtime, so `storage:link` must run at startup rather than only during the build or pre-deploy step.

To copy the existing local public files, from PowerShell at the repository root create an archive containing the *contents* of the local disk root:

```powershell
tar -czf .\dmd-public-storage.tar.gz -C .\backend\storage\app\public .
```

Upload that archive to the attached Volume using Railway's Volume file manager/CLI, then open a shell for the Laravel service and extract it into the mounted directory:

```sh
tar -xzf /app/storage/app/public/dmd-public-storage.tar.gz -C /app/storage/app/public
php artisan storage:link --force
```

The archive should contain `accommodations/`, `branding/`, and any other public-storage directories directly at its root. Do not copy the local `.env` file or any secrets.

## Production environment configuration

Production domains:

```text
Frontend: https://frontend-dmd20.vercel.app
Backend:  https://dmd-production-5759.up.railway.app
```

For the public capstone demo, disable Vercel Deployment Protection/SSO for the production deployment (or use a public production alias). During the audit, the supplied Vercel URL redirected to Vercel SSO before serving the React app, so browser-based demo testing cannot succeed while that protection is enabled.

### Required Railway variables

Set or verify these on the Laravel Railway service. Secret values are intentionally omitted here.

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dmd-production-5759.up.railway.app
FRONTEND_URL=https://frontend-dmd20.vercel.app
FRONTEND_URLS=https://frontend-dmd20.vercel.app
FILESYSTEM_DISK=public

SANCTUM_STATEFUL_DOMAINS=frontend-dmd20.vercel.app
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
SESSION_DOMAIN=

GOOGLE_CLIENT_ID=<Google OAuth web client ID>
GOOGLE_CLIENT_SECRET=<Google OAuth web client secret>
GOOGLE_REDIRECT_URI=https://dmd-production-5759.up.railway.app/auth/google/callback

MAIL_MAILER=<smtp provider>
MAIL_HOST=<smtp host>
MAIL_PORT=<smtp port>
MAIL_USERNAME=<smtp username>
MAIL_PASSWORD=<smtp password>
MAIL_SCHEME=<tls or smtps, as required by provider>
MAIL_FROM_ADDRESS=<verified sender address>
MAIL_FROM_NAME=DMD Resort
MAIL_EHLO_DOMAIN=dmd-production-5759.up.railway.app

PAYMONGO_PUBLIC_KEY=<PayMongo test public key>
PAYMONGO_SECRET_KEY=<PayMongo test secret key>
PAYMONGO_WEBHOOK_SECRET=<PayMongo webhook signing secret, if webhooks are enabled>
PAYMONGO_PAYMENT_METHOD_TYPES=card

# Safe capstone fallback when no Reverb service is deployed:
BROADCAST_CONNECTION=null

# Leave unset unless a reachable local bridge/tunnel is intentionally configured:
IOT_BRIDGE_URL=
IOT_BRIDGE_CONTROL_KEY=
```

Keep the existing database, `APP_KEY`, and other application secrets unchanged. Do not paste secret values into this file or commit them.

For cross-site cookie authentication between Vercel and Railway, `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true` are required. The session cookie remains host-only on the Railway backend; do not set `SESSION_DOMAIN` to either unrelated domain.

### Required Vercel variables

Set these for the Production environment and redeploy:

```text
VITE_API_BASE_URL=/api
VITE_ASSET_BASE_URL=https://dmd-production-5759.up.railway.app
```

Production browser API, CSRF, and OAuth requests must use the Vercel-relative paths. `frontend/vercel.json` proxies `/api/*`, `/sanctum/*`, and `/auth/*` to Railway before applying the React SPA fallback. Keep `VITE_API_BASE_URL=/api`; do not set it to the Railway origin.

No Google client secret or PayMongo secret belongs in Vercel. The Google OAuth flow is server-side; PayMongo checkout sessions are created by Laravel.

If realtime is deployed, also set these Vercel variables to the reachable Reverb host and matching public client key:

```text
VITE_REVERB_APP_KEY=<public Reverb app key>
VITE_REVERB_HOST=<reachable Reverb hostname>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

If realtime is not deployed, remove those four Vercel variables and keep `BROADCAST_CONNECTION=null` on Railway. The attendance page will use polling/manual refresh and will not fail because of an unavailable local WebSocket.

## Google OAuth setup

The existing routes are:

```text
GET https://dmd-production-5759.up.railway.app/auth/google/redirect
GET https://dmd-production-5759.up.railway.app/auth/google/callback
```

Set the exact Google Cloud Authorized Redirect URI to:

```text
https://dmd-production-5759.up.railway.app/auth/google/callback
```

If Google Cloud asks for an Authorized JavaScript Origin, use:

```text
https://frontend-dmd20.vercel.app
```

The current Socialite callback safely rejects non-customer roles, links an existing customer by normalized email, rejects conflicting Google identities, verifies new Google accounts, and redirects successful login to `https://frontend-dmd20.vercel.app/`.

For local development, keep the existing local variables and use:

```text
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

Add that local callback separately in Google Cloud if local OAuth testing is needed.

## Email verification and mail

Registration creates an unverified customer, sends a six-digit verification code, and supports resend and cancellation. Verification, resend, and cancellation are API routes protected by throttles and do not expose the code in API responses. Successful verification returns the user to the frontend verification flow.

Configure a real SMTP/API mail transport on Railway using `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. The sender address must be verified by the provider. `MAIL_MAILER=log` is local-only and is not sufficient for the production registration demo.

## PayMongo test payments

Use PayMongo test keys on Railway only. The backend builds success and cancellation URLs from `FRONTEND_URL`:

```text
Success: https://frontend-dmd20.vercel.app/booking/payment/success
Cancel:  https://frontend-dmd20.vercel.app/booking/payment/cancelled
```

PayMongo redirects append the reservation identifiers. The frontend then verifies the payment status through Railway and exits its loading state on paid, failed, cancelled, expired, or timeout outcomes. Configure the PayMongo webhook, when used, to call:

```text
https://dmd-production-5759.up.railway.app/api/webhooks/paymongo
```

Do not put `PAYMONGO_SECRET_KEY` or `PAYMONGO_WEBHOOK_SECRET` in Vercel.

## Migration and production update workflow

Never run `migrate:fresh`, replace the database, or auto-seed demo data in production. For a normal release:

```sh
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan storage:link --force
```

Run migrations only after reviewing them for backward compatibility. Since the Volume is available at runtime, keep `storage:link --force` in the Railway start command. Redeploy the Railway service, verify `/api/health`, then redeploy Vercel after changing Vercel variables.

After changing Railway variables, clear cached Laravel configuration from the Railway service shell:

```sh
php artisan optimize:clear
```

After changing Vercel variables, trigger a new Production deployment; Vite variables are embedded at build time.

## Final capstone production checklist

- Open the Vercel home page and confirm branding, logo, hero, About image, and accommodation images load.
- Register a new customer, receive the email, verify the code, sign in, refresh, and sign out.
- Try an invalid login and confirm it does not disclose account details.
- Test Google customer sign-in and confirm return to the Vercel home page.
- Confirm admin, manager, front-desk, and customer accounts land on their own dashboards; confirm unauthorized role URLs are denied/redirected.
- Browse accommodations, filter availability, create a guest booking, and verify overlap protection.
- Start a PayMongo test checkout; test success, cancel, failure, and timeout/retry behavior.
- Confirm customer booking history and cancellation request flow.
- As staff, approve/deny cancellation, process check-in/check-out, and confirm accommodation status changes.
- Create/update housekeeping tasks through Manager and confirm Front Desk visibility; do not create a housekeeping login role.
- Open reports and download Excel/CSV exports.
- Verify announcements, support/chat fallback, and admin settings uploads.
- Open Admin attendance with no IoT bridge; confirm the page remains usable and shows an unavailable-bridge response only when an operation is attempted.
- Verify a public asset URL directly under `/storage/...` and confirm a newly uploaded file survives a Railway redeploy.

## Rollback notes and known external dependencies

Rollback application code through Railway/Vercel deployment history, preserving the PostgreSQL database and Volume. Do not roll back by dropping tables or deleting files. If a migration is not backward-compatible, stop the release and use a forward corrective migration.

Google OAuth requires the exact callback registration, mail requires a verified sender and working SMTP/API credentials, PayMongo requires valid test credentials/webhooks, and realtime requires a separately reachable Reverb server. The fingerprint bridge cannot reach a device on a developer machine's `127.0.0.1` or LAN from Railway; connect it through a secure public/private tunnel or run attendance manually. The web application remains functional when the bridge is unavailable.
