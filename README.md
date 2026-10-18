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
- **Open signup without the spam** — `[riviantrackr_signup]` lets visitors create an account while
  "Anyone can register" stays off. No user exists until they click the emailed link; honeypot,
  timing check, rate limits, a disposable-domain blocklist and optional Cloudflare Turnstile keep
  bots out. New members land straight on passkey setup.
- **Member account page** — `[riviantrackr_account]` gives members a tabbed front-end account
  page (profile, email and password changes, passkeys, sign out other devices, tire reviews and
  saved tires from the Rivian Tire Guide, comments) and sends them there after signing in instead of
  the wp-admin dashboard. Other plugins add tabs through a filter.
- **Your own login pages** — `[riviantrackr_login]` on a page of your choosing, with wp-login.php's
  login/register screens redirected there and the remaining wp-login.php screens (password reset,
  logout, passkey setup) reskinned in the site's colours with your logo.
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
2. Activate **RivianTrackr Passkeys**. The `wp_riviantrackr_passkeys` and
   `wp_riviantrackr_pending_signups` tables are created on activation.
3. Visit **Settings → Passkeys**. The defaults (RP ID = site host, user verification *preferred*,
   login button + autofill on) are right for most sites.
4. Go to **Users → Profile → Passkeys → Add a passkey** and register your first one.
5. Log out and use **Sign in with a passkey** on the login form.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[riviantrackr_passkeys]` | Passkey manager for the signed-in member. Dark "ink & brass" theme by default; `theme="light"` for light backgrounds. Shows a sign-in link when logged out. |
| `[riviantrackr_passkey_login redirect="/my-account/" theme="dark" divider="0"]` | Stand-alone sign-in button for custom login pages. Hidden for signed-in visitors. |
| `[riviantrackr_login theme="dark" redirect=""]` | Full sign-in form for your own login page: passkey button, username/password, "Lost your password?", "Create an account", failure and logout messages. See [Custom login pages](#custom-login-pages). |
| `[riviantrackr_account theme="dark"]` | Tabbed member account page: Overview, Profile, Security, Reviews and Saved tires (tire guide), Comments. Dark by default, `theme="light"` available. Signed-out visitors get a sign-in link. See [Member account page](#member-account-page). |
| `[riviantrackr_signup theme="dark" redirect=""]` | Verify-first signup form (email + optional name). Dark by default, `theme="light"` available. Requires **Allow signups** under Settings → Passkeys. Signed-in visitors see a "Continue" note instead. |

## Custom login pages

Replace the stock `wp-login.php` login and registration screens with pages of your own while
password reset, logout, post passwords and the passkey landing pages keep working on `wp-login.php`.

1. Create a page (for example `/sign-in/`) containing `[riviantrackr_login]` and, if you use open
   signup, a page (for example `/join/`) containing `[riviantrackr_signup]`. Publish both.
2. Under **Settings → Passkeys → Pages** pick them as **Sign-in page** and **Join page**.
3. Turn on **Send wp-login.php visitors to these pages**. From now on:
   - `wp_login_url()` (and so `auth_redirect()`, "Log in" links, the logout confirmation) points at
     the sign-in page, keeping `redirect_to`; `wp_registration_url()` points at the join page.
   - A GET request for `wp-login.php` with no action, `action=login` or `action=register` is
     redirected to the matching page. `redirect_to`, `login`, `loggedout`, `checkemail`,
     `rtpk_invite` and `rtpk_signup` are carried over so the page can show the right message.
   - A wrong password or an empty username/password sends the visitor back to the sign-in page with
     `?login=failed` or `?login=empty` instead of rendering `wp-login.php`.
   - Never redirected: POST requests, `interim-login`, `reauth=1`, signed-in visitors (wp-login.php
     already forwards them), and `postpass`, `logout`, `lostpassword`, `retrievepassword`,
     `resetpass`, `rp`, `confirmaction`, `passkey-setup`, `passkey-signup`.
   - If the chosen page is unpublished or deleted the redirect switches itself off. The stock form
     is always reachable at `wp-login.php?reauth=1`.
4. Optionally turn on **Brand the remaining wp-login.php screens** to restyle password reset, logout
   and the passkey setup/signup pages in the dark "ink & brass" palette with a gold button and your
   logo (the invitations logo, else the theme's custom logo, else the site icon, else the site name).

`[riviantrackr_login theme="dark|light" redirect=""]` renders `wp_login_form()` in the plugin's card:
passkey button on top, "or", username, password, "Keep me signed in", "Sign in", then "Lost your
password?" and (while signups are allowed and a join page is set) "Create an account". It shows a
message for `?login=failed`, `?login=empty`, `?loggedout=true`, `?checkemail=confirm`,
`?rtpk_invite=expired` and `?rtpk_signup=exists`, and the "sign in once with your password" hint when
`redirect_to` leads to passkey setup. After signing in the member goes to `redirect_to` if present,
else the `redirect` attribute, else the home page. Signed-in visitors see "You're signed in as …"
with a Continue link.

**Caching caveat:** the sign-in and join pages must not be served from a full-page cache. The form
carries the current `redirect_to`, the messages depend on the query string, and the signed-in state
depends on the visitor. Exclude both URLs in your cache plugin or CDN (most ignore requests with
query strings, but the bare page must be excluded too), or the cached copy will show the wrong state.

## Member account page

Subscribers have no business on the wp-admin dashboard. `[riviantrackr_account]` renders a
tabbed "My Account" page in the plugin's dark "ink & brass" theme (`theme="light"` for light
backgrounds; a left tab rail on desktop that becomes a stacked list under 600px; inline SVG icons,
no icon font):

| Tab | What it shows |
|---|---|
| **Overview** | Avatar, display name, email, member since, counts of passkeys / reviews / saved tires / comments (each a link to its tab), quick links. |
| **Profile** | Display name, first and last name, email, website, bio. Saved over REST with inline field errors. Changing the email does not apply immediately: the standard WordPress confirmation link is sent to the new address (same `_new_email` meta and `profile.php?newuseremail=…` link as the profile screen), the pending address is shown on the page and can be cancelled. |
| **Security** | The passkey manager (add, rename, remove), a change-password form (current password required; this browser stays signed in, every other device is signed out) and **Sign out other devices** (`WP_Session_Tokens::destroy_others()`). |
| **Reviews** | Only while the Rivian Tire Guide is active (helper methods are feature-detected; older builds are read through `wp_rtg_ratings` directly). The member's approved reviews (`RTG_Database::get_user_reviews()`) plus pending/rejected ones from `wp_rtg_ratings`, with tire name linked to the tire page, stars, title, excerpt, status badge, date, **Edit** (opens the guide's review page with the review loaded, `RTG_Tire_Review::url()`) and **Delete** (`RTG_Database::delete_user_rating()`). |
| **Saved tires** | Only while the tire guide is active (2.14.0+ hearts; older builds are read through `wp_rtg_saved_tires`). `RTG_Database::get_saved_tires()` with name, size, image, today's price, the move since the save and the save date, a link to the guide's saved tires page, **View** and **Remove** (`RTG_Database::unsave_tire()`). The tab id and REST path stay `favorites`. |
| **Comments** | The member's 20 most recent approved comments with post title links. |

A **Sign out** link sits in the header. Tabs are addressed by URL hash (`/account/#security`), so
quick links and the invitation/setup flows can deep-link into a section.

### Setup

1. Create and publish a page (for example `/account/`) containing `[riviantrackr_account]`.
2. Under **Settings → Passkeys → Pages** pick it as the **Account page**. From then on:
   - Members without the `edit_posts` capability land on it after **any** login — password form,
     `[riviantrackr_login]`, passkey button, invitation magic link, signup link — through the
     core `login_redirect` filter and the passkey REST login's redirect resolver, which share
     one rule (`AccountRedirect::target()`): an explicit front-end `redirect_to` is honoured;
     nothing requested, the dashboard, or any `wp-admin` URL goes to the account page. Editors
     and administrators keep WordPress's behaviour.
   - It becomes the default destination after passkey setup and after signup when the
     **After passkey setup, send new members to** field is blank.
   - **Hide the admin bar for members without editing rights** (on by default, only effective
     while an account page is set) removes the toolbar for subscribers on the front end.
   - **Add an "Account" link to the main menu** (off by default) appends an Account item to the
     theme's first registered menu location for signed-in members. If you manage menus yourself,
     leave it off and add the page under Appearance → Menus.
3. **Exclude the page from full-page caching** (cache plugin or CDN). Everything on it depends on
   the signed-in user.

### Adding tabs from another plugin

```php
add_filter( 'riviantrackr_passkeys_account_tabs', function ( array $tabs, ?WP_User $user ) {
	$tabs[] = [
		'id'     => 'orders',                 // slug; duplicates are dropped
		'label'  => __( 'Orders', 'my-plugin' ),
		'icon'   => '<svg …></svg>',          // optional inline SVG, 20×20
		'render' => function ( WP_User $user, array $context ): string {
			// $context: light (bool), account_url, tabs (ids in order). Return escaped HTML.
			return '<h3 class="rtpk-account__title">Orders</h3>…';
		},
	];
	return $tabs;
}, 10, 2 );
```

Entries without an id, label or callable `render` are ignored. The default tab ids are
`overview`, `profile`, `security`, `reviews`, `favorites` (saved tires; the last two only with the tire guide)
and `comments`; remove or reorder them freely.

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

## Open signup (verify-first)

Turn it on under **Settings → Passkeys → Open signup** and put `[riviantrackr_signup]` on a page.
WordPress's own "Anyone can register" option can stay off.

```
Visitor                                  Plugin
───────                                  ──────
submits email (+ name)        ──►  POST signup/request
                                   ├ honeypot filled?            → fake success, nothing stored
                                   ├ form token < 3 s old / forged → error
                                   ├ Turnstile (if keys set)     → siteverify
                                   ├ disposable / blocked domain → error
                                   ├ riviantrackr_passkeys_signup_allowed filter
                                   ├ address already registered  → "you already have an account" email
                                   └ otherwise: pending row (SHA-256 of a 32-byte token) + branded email
clicks "Confirm and set up      ──►  wp-login.php?action=passkey-signup&token=…
my passkey"                        ├ token unknown / expired / used → friendly page with "send me a new link"
                                   └ create user (role from settings, random password, username from
                                     the email's local part, de-duplicated), fire user_register,
                                     delete the row, sign in, redirect to passkey setup → continue URL
```

- **Nothing is created until the click.** Unverified requests live only in
  `wp_riviantrackr_pending_signups` (email, display name, hashed token, hashed IP, expiry) and are
  purged as they expire. Pending count is shown on the settings screen.
- **No account enumeration.** The form always answers "check your inbox". If the address already
  has an account, the inbox owner gets a sign-in reminder instead of a signup link.
- **Rate limits:** 5 requests per hour per IP (HTTP 429), 3 per hour per address (silently dropped).
  `RTPK_SIGNUP_IP_RATE_LIMIT`, `RTPK_SIGNUP_EMAIL_RATE_LIMIT` and `RTPK_SIGNUP_MIN_SECONDS` can be
  defined in `wp-config.php` before the plugin loads to change them.
- **Blocked domains:** ~170 well-known throwaway providers are built in (subdomains included); add
  your own one per line, or use the `riviantrackr_passkeys_blocked_domains` filter.
- **Turnstile:** paste the site key and secret key from the Cloudflare dashboard. Leave both blank
  to run without it; the other defences still apply.
- **Email:** subject and message are editable (same placeholders and bullet rules as the invitation
  email); the logo, colours and from name/address come from Users → Passkey invitations.
- **Settings:** allow signups, role (never Administrator), link expiry in hours (default 24),
  continue URL after setup (must be on this site), Turnstile keys, extra blocked domains, admin
  notification toggle.

Hooks: `riviantrackr_passkeys_signup_allowed` filter (`$allowed, $email, $input`; return `false`
or a `WP_Error` to deny), `riviantrackr_passkeys_signup_email` filter, `riviantrackr_passkeys_blocked_domains`
filter; `riviantrackr_passkeys_signup_requested`, `_signup_verified( $user, $row )` and
`_signup_login( $user )` actions.

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
| POST | `/signup/request` | public | 5/hour per IP; `email`, `name`, `website` (honeypot), `ts`, `sig`, `turnstile` |
| GET | `/credentials` | user | own list, or `?user_id=` with `edit_user` capability |
| POST | `/credentials/{id}` | user | rename (own only) |
| DELETE | `/credentials/{id}` | user | own, or `edit_user` capability |
| POST | `/account/profile` | user | `display_name`, `first_name`, `last_name`, `email`, `url`, `description`; field errors in `data.fields`; an email change returns `pendingEmail` |
| POST | `/account/email/cancel` | user | drop a pending email change |
| POST | `/account/password` | user | 5/min per user; `current`, `new`, `confirm`; re-issues this browser's cookie, signs out other devices |
| POST | `/account/sessions/others` | user | sign out every other browser/device |
| DELETE | `/account/reviews/{tire_id}` | user | delete own tire review (tire guide active) |
| DELETE | `/account/favorites/{tire_id}` | user | take a tire off the saved list (tire guide active) |

## Hooks

```php
// Deny or allow a verified passkey login (return false or WP_Error to deny).
add_filter( 'riviantrackr_passkeys_pre_login', fn( $allowed, WP_User $user, array $credential ) => $allowed, 10, 3 );

// Accept additional origins (normalised "scheme://host[:port]").
add_filter( 'riviantrackr_passkeys_allowed_origins', fn( array $origins ) => $origins );

// Deny a signup request (return false or WP_Error). Runs before anything is stored or sent.
add_filter( 'riviantrackr_passkeys_signup_allowed', fn( $allowed, string $email, array $input ) => $allowed, 10, 3 );

// Behind a trusted proxy, supply the real client IP for rate limiting.
add_filter( 'riviantrackr_passkeys_client_ip', fn( string $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );

do_action( 'riviantrackr_passkeys_registered', array $row, WP_User $user );
do_action( 'riviantrackr_passkeys_login', WP_User $user, array $row );
do_action( 'riviantrackr_passkeys_deleted', array $row );
do_action( 'riviantrackr_passkeys_counter_mismatch', array $row, WP_User $user ); // possible cloned authenticator
do_action( 'riviantrackr_passkeys_signup_requested', string $email );
do_action( 'riviantrackr_passkeys_signup_verified', WP_User $user, array $pending_row );
do_action( 'riviantrackr_passkeys_signup_login', WP_User $user );
do_action( 'riviantrackr_passkeys_profile_updated', WP_User $user, array $data );
do_action( 'riviantrackr_passkeys_password_changed', WP_User $user );

// Add, remove or reorder account page tabs (see "Member account page").
add_filter( 'riviantrackr_passkeys_account_tabs', fn( array $tabs, ?WP_User $user ) => $tabs, 10, 2 );
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
3. Commit, tag `vX.Y.Z`, push with tags, then publish the GitHub release with the changelog section as its notes (`gh release create vX.Y.Z --title X.Y.Z --latest --notes-file …`). CI runs on every push.

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
