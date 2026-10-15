# RivianTrackr Passkeys

Passwordless, phishing-resistant sign-in for WordPress using **passkeys** (WebAuthn / FIDO2).
Members register Face ID, Touch ID, Windows Hello, Android biometrics or a hardware security
key once, then sign in with a single tap. Passwords keep working alongside.

- **Login form integration** — "Sign in with a passkey" on `wp-login.php` and any form built with
  `wp_login_form()`, plus browser **autofill (conditional UI)** so saved passkeys appear directly
  in the username field.
- **Self-service management** — add, rename and remove passkeys from the WordPress profile
  screen or a front-end account page via `[riviantrackr_passkeys]`.
- **Admin controls** — relying party name/ID, user-verification policy, allowed origins, timeout,
  autofill toggle, uninstall behaviour. Settings → Passkeys.
- **Invitations** — email members who have no passkey yet with a personal one-tap setup link
  (Users → Passkey invitations), or invite one person from the Users list. The link signs them in
  and lands on a focused "Set up a passkey" page.
- **Hardened by default** — single-use challenges, strict exact-origin matching, signature-counter
  clone detection, per-IP and per-user rate limits, opaque 32-byte user handles, a 10-passkey cap
  per account.
- **Self-contained** — bundles [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn) (MIT) under
  `lib/`; no Composer needed on the server.

## Requirements

| | |
|---|---|
| WordPress | 6.4+ |
| PHP | 8.1+ with `openssl` (and `mbstring` recommended) |
| Site URL | **HTTPS** — browsers refuse WebAuthn on insecure origins (except `localhost`) |
| REST API | Must be reachable for logged-out visitors at `/wp-json/riviantrackr-passkeys/v1/login/*` |

## Install

1. Copy `riviantrackr-passkeys/` to `wp-content/plugins/` (or zip the folder and upload it).
   `vendor/` and `node_modules/` are not required at runtime — only `lib/`, `includes/` and `assets/`.
2. Activate **RivianTrackr Passkeys**. The `wp_riviantrackr_passkeys` table is created on activation.
3. Visit **Settings → Passkeys**. The defaults (RP ID = site host, user verification *preferred*,
   login button + autofill on) are right for most sites.
4. Go to **Users → Profile → Passkeys → Add a passkey** and register your first one.
5. Log out and use **Sign in with a passkey** on the login form.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[riviantrackr_passkeys]` | Passkey manager for the signed-in member. Dark "ink & brass" theme by default; `theme="light"` for light backgrounds. Shows a sign-in link when logged out. |
| `[riviantrackr_passkey_login redirect="/my-account/" theme="dark" divider="0"]` | Stand-alone sign-in button for custom login pages. Hidden for signed-in visitors. |

## Inviting members

**Users → Passkey invitations** shows how many members have a passkey, lets you pick roles and
whether to re-invite people, and sends in batches of 20 with a progress log. Only members without
a passkey are ever emailed. The email is plain text you edit in place (placeholders `{name}`,
`{site}`, `{link}`, `{expires}`, `{login_url}`); the plugin wraps it in an HTML template in the
site's dark "ink & brass" palette (or a light variant) with your logo and a gold "Set up my passkey"
button, and sends it through `wp_mail()`, so whatever SMTP plugin you use applies. You can set the
from name and address (applied only to these emails), pick a logo from the media library (defaults
to the theme's custom logo or the site icon), preview the rendered email inline, and "Send me a
test" mails the current draft to you without counting as an invite.

The **Users** list gains a "Passkeys" column, a "Send passkey invite" row action, and a bulk action.

Each email carries a single-use token (32 random bytes; only its SHA-256 hash is stored in user
meta) that expires after a configurable number of days (default 7). The link opens
`wp-login.php?action=passkey-setup`:

- With **"Link signs the member in"** on (default), a valid token sets the auth cookie, fires
  `wp_login`, retires the token and shows the setup page. This is the same trust level as a
  password-reset email: anyone with the inbox can use it, once, until it expires.
- With it off, or when the token is expired/used, the member sees the normal login form with a
  hint and is redirected to the setup page after signing in.

The setup page is a stripped-down `wp-login.php` screen: a short pitch, one big "Create a passkey"
button (the same manager used on the profile page), a "Continue" button once done, and "Skip for
now". It also works for members who already have a passkey (shows their list, "Add another device").

Hooks: `riviantrackr_passkeys_invite_email` filters the `to/subject/message/headers` array before
sending; `riviantrackr_passkeys_invite_sent` and `riviantrackr_passkeys_invite_login` actions fire
after a send and after a magic-link sign-in.

## How it works

```
Register (logged-in user)                     Sign in (anyone)
─────────────────────────                     ────────────────
POST register/options ─► challenge + token    POST login/options ─► challenge + token
navigator.credentials.create(...)             navigator.credentials.get(...)   (or autofill)
POST register/verify  ─► store public key     POST login/verify  ─► verify sig, set auth cookie
```

- Challenges are 32 random bytes stored in a transient keyed by a random token, valid for
  5 minutes and deleted on first use. The browser never chooses the challenge.
- Registration requests a **discoverable credential** (`residentKey: required`) with
  `attestation: none`, so the login ceremony needs no username and no attestation CA handling.
- `clientDataJSON.origin` must exactly equal the site origin or an admin-listed extra origin.
  The bundled library's own suffix check runs afterwards as a second layer.
- Assertions are checked for RP-ID hash, user-presence, user-verification (when required), the
  ES256/RS256/EdDSA signature over `authenticatorData || SHA-256(clientDataJSON)`, and a
  monotonically increasing signature counter (zero counters from synced passkeys are allowed).
- On success the plugin calls `wp_set_auth_cookie()` and fires the standard `wp_login` action,
  so session-aware plugins (activity logs, limit-login tools, 2FA gates) still see the login.

## REST API

Namespace `riviantrackr-passkeys/v1`. Logged-in routes require the standard REST cookie nonce
(`X-WP-Nonce`), which the bundled scripts send automatically.

| Method | Route | Auth | Notes |
|---|---|---|---|
| POST | `/register/options` | user | 10/min per user; 10 passkeys max |
| POST | `/register/verify` | user | `challengeId`, `credential`, optional `name` |
| POST | `/login/options` | public | 30/min per IP |
| POST | `/login/verify` | public | 10/min per IP; `challengeId`, `credential`, `redirectTo`, `remember` |
| GET | `/credentials` | user | own list, or `?user_id=` with `edit_user` capability |
| POST | `/credentials/{id}` | user | rename (own only) |
| DELETE | `/credentials/{id}` | user | own, or `edit_user` capability |

## Hooks

```php
// Deny or allow a verified passkey login (return false or WP_Error to deny).
add_filter( 'riviantrackr_passkeys_pre_login', fn( $allowed, WP_User $user, array $credential ) => $allowed, 10, 3 );

// Accept additional origins (normalised "scheme://host[:port]").
add_filter( 'riviantrackr_passkeys_allowed_origins', fn( array $origins ) => $origins );

// Behind a trusted proxy, supply the real client IP for rate limiting.
add_filter( 'riviantrackr_passkeys_client_ip', fn( string $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );

do_action( 'riviantrackr_passkeys_registered', array $row, WP_User $user );
do_action( 'riviantrackr_passkeys_login', WP_User $user, array $row );
do_action( 'riviantrackr_passkeys_deleted', array $row );
do_action( 'riviantrackr_passkeys_counter_mismatch', array $row, WP_User $user ); // possible cloned authenticator
```

## Development

```bash
composer install          # PHPUnit + the library source
vendor/bin/phpunit        # PHPUnit incl. a software P-256 authenticator driving real verification
npm install && npm test   # JS helper tests (node:test)
npm run build             # esbuild → assets/*.min.{js,css}
phpcs --standard=.phpcs.xml
```

The bundled library lives in `lib/lbuchs-webauthn/` (copied from `vendor/lbuchs/webauthn/src`).
To upgrade it: `composer update lbuchs/webauthn && cp -R vendor/lbuchs/webauthn/src/. lib/lbuchs-webauthn/`.

## Releasing

1. Add an entry under `[Unreleased]` in `CHANGELOG.md` as you go.
2. To release, move it under a new version heading, bump the version in `riviantrackr-passkeys.php` (header and `RTPK_VERSION`), `readme.txt` (stable tag + changelog) and `package.json`.
3. Commit, tag `vX.Y.Z`, push with tags. CI runs on every push.

## Troubleshooting

- **Button never appears** — the browser lacks WebAuthn, or the page is not HTTPS. Check the
  console for `SecurityError`.
- **"This passkey was created for a different site address"** — the RP ID changed, or the site
  moved hosts. Passkeys are bound to the RP ID; members must re-register.
- **Login works but autofill doesn't** — conditional UI needs Safari 16+/Chrome 108+/Edge 108+ and
  a username field with `autocomplete="username webauthn"` (the plugin sets this on `input[name=log]`).
- **401/403 on `/login/options`** — a security plugin is blocking the REST API for anonymous
  visitors. Allow the `riviantrackr-passkeys/v1` namespace.
- **Cloudflare / proxies** — use the `riviantrackr_passkeys_client_ip` filter so rate limits apply
  per visitor rather than per edge node.

## License

GPL-2.0-or-later. Bundled `lbuchs/WebAuthn` is MIT (see `lib/lbuchs-webauthn/LICENSE`).
