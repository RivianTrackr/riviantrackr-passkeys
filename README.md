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
- **Your own login pages** — `[riviantrackr_login]`, `[riviantrackr_password_reset]` and
  `[riviantrackr_passkey_setup]` on pages of your choosing, with wp-login.php's login, register,
  password reset and passkey setup screens redirected there, the emails WordPress sends around
  sign-in (password reset, welcome, email change) in the same branded template, and whatever is
  left on wp-login.php reskinned in the site's colours with your logo.
- **Comment form sign-ups** — a "Join the conversation" card on the WordPress comment form asks
  signed-out visitors to create an account or sign in, and brings them back to the comments
  afterwards, through passkey setup for new members. See [Comment form](#comment-form).
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
| `[riviantrackr_password_reset theme="dark"]` | Password reset in the same card: "Reset your password" (username or email, sends the standard WordPress reset email) and, from the emailed link, "Choose a new password". Plain forms, no JavaScript. See [Custom login pages](#custom-login-pages). |
| `[riviantrackr_passkey_setup theme="dark"]` | The one-tap "Set up a passkey" screen in the same card: pitch, create button, done panel, skip link. Invitation links and new signups land here once signed in; signed-out visitors get a Sign in button that brings them back. See [Custom login pages](#custom-login-pages). |
| `[riviantrackr_account theme="dark"]` | Tabbed member account page: Overview, Profile (photo, names, email, bio), Garage, Security, Reviews and Saved tires (tire guide), Comments, Your data. Dark by default, `theme="light"` available. Signed-out visitors get a sign-in link (and "Create a free account" when a join page is set). See [Member account page](#member-account-page). |
| `[riviantrackr_garage_cta theme="dark"]` | Asks visitors to save their Rivian: signed out, "Save your Rivian" with **Create a free account** (join page) and **Sign in**; signed in with an empty garage, "Add your Rivian" linking to the Garage tab; nothing once the garage has a vehicle. See [My Garage](#my-garage). |
| `[riviantrackr_signup theme="dark" redirect=""]` | Verify-first signup form (email + optional name). Dark by default, `theme="light"` available. Requires **Allow signups** under Settings → Passkeys. Signed-in visitors see a "Continue" note instead. |

## Custom login pages

Replace the stock `wp-login.php` login, registration, password reset and passkey setup screens
with pages of your own while logout, post passwords and the token-checking landing URLs keep
working on `wp-login.php`.

1. Create a page (for example `/sign-in/`) containing `[riviantrackr_login]`, a page (for example
   `/reset-password/`) containing `[riviantrackr_password_reset]`, a page (for example `/passkey/`)
   containing `[riviantrackr_passkey_setup]` and, if you use open signup, a page (for example
   `/join/`) containing `[riviantrackr_signup]`. Publish them.
2. Under **Settings → Passkeys → Pages** pick them as **Sign-in page**, **Password reset page**,
   **Passkey setup page** and **Join page**. The setup page takes effect as soon as it is chosen
   (no redirect toggle needed): `InviteService::setup_url()` points at it, so the invitation
   landing URL, the signup landing URL and the sign-in page's "set up your passkey" hint all end
   there, and `wp-login.php?action=passkey-setup` forwards signed-in visitors to it after its token
   work. Expired or failed signup links land on the join page with a notice instead of a
   wp-login.php screen, as soon as a join page is chosen.
3. Turn on **Send wp-login.php visitors to these pages**. From now on:
   - `wp_login_url()` (and so `auth_redirect()`, "Log in" links, the logout confirmation) points at
     the sign-in page, keeping `redirect_to`; `wp_registration_url()` points at the join page;
     `wp_lostpassword_url()` (every "Lost your password?" link) points at the reset page.
   - A GET request for `wp-login.php` with no action, `action=login` or `action=register` is
     redirected to the matching page. `redirect_to`, `login`, `loggedout`, `checkemail`,
     `rtpk_invite` and `rtpk_signup` are carried over so the page can show the right message.
   - A GET request for `action=lostpassword`, `retrievepassword`, `rp` or `resetpass` is redirected
     to the reset page. The emailed link's `key` and `login` ride along (so the standard reset email
     needs no change), `redirect_to` is kept, and wp-login.php's own `error=expiredkey|invalidkey`
     bounce becomes the page's "link expired" notice. A screen whose page is not chosen stays on
     wp-login.php.
   - A wrong password or an empty username/password sends the visitor back to the sign-in page with
     `?login=failed` or `?login=empty` instead of rendering `wp-login.php`.
   - Never redirected by the router: POST requests, `interim-login`, `reauth=1`, signed-in
     visitors (wp-login.php already forwards them), and `postpass`, `logout`, `confirmaction`,
     `passkey-setup`, `passkey-signup` (the last two check their tokens on wp-login.php, then
     forward to your pages themselves).
   - If the chosen page is unpublished or deleted the redirect switches itself off. The stock form
     is always reachable at `wp-login.php?reauth=1`.
4. Optionally turn on **Brand the remaining wp-login.php screens** to restyle logout and whatever
   screen has no page of its own yet (password reset, passkey setup, the expired-signup page) in
   the dark "ink & brass" palette with a gold button and your logo (the invitations logo, else the
   theme's custom logo, else the site icon, else the site name).

`[riviantrackr_login theme="dark|light" redirect=""]` renders `wp_login_form()` in the plugin's card:
passkey button on top, "or", username, password, "Keep me signed in", "Sign in", then "Lost your
password?" and (while signups are allowed and a join page is set) "Create an account". It shows a
message for `?login=failed`, `?login=empty`, `?loggedout=true`, `?checkemail=confirm`,
`?rtpk_invite=expired` and `?rtpk_signup=exists`, and the "sign in once with your password" hint when
`redirect_to` leads to passkey setup. After signing in the member goes to `redirect_to` if present,
else the `redirect` attribute, else the home page. Signed-in visitors see "You're signed in as …"
with a Continue link.

`[riviantrackr_password_reset theme="dark|light"]` renders the reset flow in the same card, as plain
forms that post back to the page (no JavaScript needed):

- **Reset your password:** username or email, "Send reset link". It calls `retrieve_password()`, so
  the standard WordPress flow (and the `lostpassword_post`, `allow_password_reset` hooks) applies
  and the email is the branded one described under [Emails](#emails). Whether or not an account
  matches, the visitor sees "Check your inbox", like open signup: nothing reveals who is registered
  (core's `invalidcombo` and `invalid_email` both read as sent). Empty input, an account with
  reset disabled and a mail failure show an inline error. Reset emails are limited to 5 per hour
  per IP (`RTPK_RESET_RATE_LIMIT`); the limit is applied inside `retrieve_password()` through the
  `lostpassword_post` action, so it covers wp-login.php and other plugins' forms too.
- **Choose a new password:** reached from the emailed link. Like wp-login.php, the key is moved from
  the URL into a cookie scoped to the page before anything renders, so it never leaks through the
  referrer. The form asks for the password twice (at least 8 characters, no backslash),
  `check_password_reset_key()` verifies the key, `validate_password_reset` runs, then
  `reset_password()` saves it and "Password updated" offers a **Sign in** button
  (`wp_login_url()`, so it lands on your sign-in page). An expired or spent key shows "This reset
  link has expired" above the request form.
- Signed-in visitors are pointed at the account page (or `profile.php`) to change their password,
  with a "Not you? Sign out" link.
- Every outcome is a redirect back to the page with `?rtpk_reset=<code>`, so a refresh never
  resubmits a form.

`[riviantrackr_passkey_setup theme="dark|light"]` renders the one-tap setup screen in the same card:
the pitch and three benefits, the embedded passkey manager with a single **Create a passkey**
button, a done panel with **Continue to {site}** (the `redirect_to` query arg, else wp-admin for
editors, else the account page, else home) and **Skip for now**. Signed-out visitors see a
**Sign in** button whose `redirect_to` brings them back, and the sign-in page shows its "sign in
once with your password" hint for that URL. `[riviantrackr_signup]` shows an "expired link" or
"couldn't create your account" notice above the form when the landing page sends a visitor back
with `?rtpk_signup=expired|failed`.

### Emails

With the pages above, the plain-text emails WordPress sends around sign-in would stand out, so
`BrandedEmails` puts them in the invitation template (logo, palette and from address from
**Users → Passkey invitations**, HTML with a plain-text fallback link):

| Email | Hook used | What changes |
|---|---|---|
| Password reset | `retrieve_password_notification_email` | "Reset your {site} password", who asked, a **Choose a new password** button to the very link core built (so the router still catches it), the link lifetime and the requesting IP. |
| New member welcome (`wp_new_user_notification`) | `wp_new_user_notification_email` | "Welcome to {site}" with the set-password link lifted from core's message. |
| New member notice to the admin | `wp_new_user_notification_email_admin` | "New member on {site}" with username, email and a **View member** button. |
| Email-change confirmation (account page) | `riviantrackr_passkeys_email_change_mail` | "Confirm your new {site} email address" with a **Confirm new email** button. |

`add_filter( 'riviantrackr_passkeys_brand_core_emails', '__return_false' )` keeps WordPress's own
text for all four. The subject and body copy are translatable strings, not settings.

**Caching caveat:** the sign-in, reset, setup and join pages must not be served from a full-page
cache. The forms carry the current `redirect_to` and a nonce, the messages depend on the query
string, and the signed-in and reset states depend on the visitor. Exclude the URLs in your cache
plugin or CDN (most ignore requests with query strings, but the bare pages must be excluded too),
or the cached copy will show the wrong state.

## Member account page

Subscribers have no business on the wp-admin dashboard. `[riviantrackr_account]` renders a
tabbed "My Account" page in the plugin's dark "ink & brass" theme (`theme="light"` for light
backgrounds; a left tab rail on desktop that becomes a stacked list under 600px; inline SVG icons,
no icon font):

| Tab | What it shows |
|---|---|
| **Overview** | Avatar, display name, email, member since, counts of passkeys / vehicles / reviews / saved tires / comments (each a link to its tab), quick links. While the garage is empty an **Add your Rivian** card sits above the counts. |
| **Profile** | **Your photo**: upload a JPG, PNG, GIF or WebP (2 MB by default, `riviantrackr_passkeys_avatar_max_bytes` filter); it is cropped to a centred square of at most 512px, stored in the media library as a private attachment owned by the member, and served everywhere WordPress asks for that member's avatar (header, comments, author boxes, wp-admin) through `pre_get_avatar_data`. **Remove photo** deletes the attachment and goes back to Gravatar; deleting the account deletes the photo with the member's other posts. Then display name, first and last name, email, website, bio. Saved over REST with inline field errors. Changing the email does not apply immediately: a branded confirmation link is sent to the new address (same `_new_email` meta as the profile screen), the pending address is shown on the page and can be cancelled. The link lands on the account page (`?newuseremail=…`), which applies the change the way `user-edit.php` does and shows "Your email address has been updated" on this tab; signed-out visitors are sent to sign in and brought back. Without an account page the link goes to `profile.php` as in core. |
| **Garage** | The member's Rivians: model, model year, generation (set from the year, can be changed), an optional nickname and up to four wheel sets each (a wheel from the Tire Guide with one of its sizes, or any wheel with the tire size typed in, plus a label like "Winter"). Up to five vehicles; one is the default. Add, edit, remove and **Make default** over REST with inline field errors. An empty garage opens the form straight away. See [My Garage](#my-garage). |
| **Security** | The passkey manager (add, rename, remove, provider badge from the AAGUID), a change-password form (current password required; this browser stays signed in, every other device is signed out) with a **Forgot your current password? Email me a reset link** action, and **Sign out other devices** (`WP_Session_Tokens::destroy_others()`). Accounts created by open signup have a random password nobody has seen, so they get a **Set a password** card (emails the reset link) instead of the change form until a password is set; the flag is `riviantrackr_passkeys_no_password` user meta, cleared by the core `wp_set_password` action. |
| **Reviews** | Only while the Rivian Tire Guide is active (helper methods are feature-detected; older builds are read through `wp_rtg_ratings` directly). The member's approved reviews (`RTG_Database::get_user_reviews()`) plus pending/rejected ones from `wp_rtg_ratings`, with tire name linked to the tire page, stars, title, excerpt, status badge, date, **Edit** (opens the guide's review page with the review loaded, `RTG_Tire_Review::url()`) and **Delete** (`RTG_Database::delete_user_rating()`). |
| **Saved tires** | Only while the tire guide is active (2.14.0+ hearts; older builds are read through `wp_rtg_saved_tires`). `RTG_Database::get_saved_tires()` with name, size, image, today's price, the move since the save and the save date, a link to the guide's saved tires page, **View** and **Remove** (`RTG_Database::unsave_tire()`). The tab id and REST path stay `favorites`. |
| **Comments** | The member's 20 most recent approved comments with post title links. |
| **Your data** | **Request my data** files a core personal-data export request for the member and marks it confirmed (they are signed in, so core's confirmation email is skipped); you fulfil it under **Tools → Export Personal Data**. **Delete your account** asks the member to type their username: members without `edit_posts`, `delete_users` or `manage_options` are deleted on the spot (`wp_delete_user()`, so their passkeys go through `deleted_user`; comments and tire reviews stay on the site without a linked account) and signed out; editors, administrators, and everyone when **Let members delete their own account** is off, get a confirmed erasure request under **Tools → Erase Personal Data** instead. |

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
   - **Let members delete their own account** (on by default) controls whether the Your data tab
     deletes immediately or files an erasure request for you to approve.
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
`overview`, `profile`, `garage`, `security`, `reviews`, `favorites` (saved tires; the last two only with the tire guide),
`comments` and `data`; remove or reorder them freely.

## My Garage

Members keep the Rivians they drive on their account so every RivianTrackr plugin can show what
applies to them. The vocabulary is shared (`Vehicles`) and maps onto the slugs the other plugins
already use:

| Model | `label` (Tire Guide reviews and wheels) | `platform` (Software Updates) | `group` (Tire Guide filter) | Generations |
|---|---|---|---|---|
| `r1t` | R1T | `r1` | R1 | `gen1` 2022–2024, `gen2` 2025+ |
| `r1s` | R1S | `r1` | R1 | `gen1` 2022–2024, `gen2` 2025+ |
| `r2` | R2 | `r2` | R2 | `gen1` 2026+ |

Years run from a model's first year to next year. Add R3 (or a generation) with the
`riviantrackr_garage_models` filter; entries missing a label, platform, group or a sensible year
range are dropped.

The garage is one user meta entry (`riviantrackr_passkeys_garage`). Wheels picked from the Tire
Guide are read fresh each time, so a renamed wheel follows the guide, and a size the guide drops
falls back to the wheel's stock size. Typed sizes are normalized (`275/65R18`, `LT285/70R17`,
`35x12.50R17`).

**Reading it from another plugin** (no dependency on this one; `null` when it is not active):

```php
$garage = apply_filters( 'riviantrackr_garage', null, get_current_user_id() );
// [ 'vehicles' => [ … ], 'default_id' => 'a1b2c3d4', 'default' => [ … ] | null ]
// Each vehicle: id, model, model_label, year, generation, generation_label, nickname,
// label ("2025 R1S"), platform, group, wheels [ wheel_id, name, size, label, third_party ],
// sizes (every tire size it wears), is_default.
```

**In the browser**, every front-end page gets `window.riviantrackrGarage` in the head (script
handle `riviantrackr-garage`, so list it as a dependency): `signedIn`, `vehicles`, `defaultId`,
`garageUrl` (the Garage tab), `joinUrl`, `signInUrl` and `maxVehicles`. Visitors who are signed
out all get the same data, so cached pages stay correct. The account page fires a
`riviantrackr:garage` event on `document` (the new garage as `detail`) after every change.
`add_filter( 'riviantrackr_passkeys_garage_script', '__return_false' )` stops printing it.

The garage is included in personal-data exports and removed by erasure requests and account
deletion.

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

## Comment form

On by default (**Settings → Passkeys → Pages → Invite commenters to sign in or join**). Works
with any theme that prints its comment form with core's `comment_form()`.

- **Anyone may comment:** signed-out visitors see a "Join the conversation" card at the top of the
  form (`comment_form_top`) above the name and email fields.
- **Comments need an account** (Settings → Discussion → "Users must be registered and logged in to
  comment"): the card ("Sign in to comment") replaces core's "You must be logged in to post a
  comment" line.
- **Create a free account** links to the join page with `redirect_to` set to the post's
  `#respond` anchor while open signup is on and a join page is set. With "Anyone can register" on
  instead, it links to core registration (which cannot carry a return address). With neither, it
  is left out and **Sign in** becomes the gold button.
- **Sign in** is `wp_login_url()` with the same return address, so it reaches your sign-in page
  when the login redirect is on.
- Signed-in members see the plain form. The stylesheet (`rtpk-comments`) loads in the head of
  singular posts with open comments, for signed-out visitors only.
- Full-page caches are fine: cached copies are only served to signed-out visitors, who are the
  ones meant to see the card.

```php
// Hide it on a post (or change the markup).
add_filter( 'riviantrackr_passkeys_comment_prompt', fn( string $html, int $post_id, bool $required ) => 42 === $post_id ? '' : $html, 10, 3 );

// Light colours for a light theme.
add_filter( 'riviantrackr_passkeys_comment_prompt_theme', fn() => 'light' );
```

## Open signup (verify-first)

Turn it on under **Settings → Passkeys → Open signup** and put `[riviantrackr_signup]` on a page.
WordPress's own "Anyone can register" option can stay off.

```
Visitor                                  Plugin
───────                                  ──────
submits email (+ name)        ──►  POST signup/request
                                   ├ honeypot filled?            → fake success, nothing stored
                                   ├ form token < 3 s old → "too quick"; forged / > 12 h → "expired"
                                   │  (the form swaps in a fresh token from GET signup/token on load,
                                   │   so a page served from a full-page cache still works)
                                   ├ Turnstile (if keys set)     → siteverify
                                   ├ disposable / blocked domain → error
                                   ├ riviantrackr_passkeys_signup_allowed filter
                                   ├ address already registered  → "you already have an account" email
                                   └ otherwise: pending row (SHA-256 of a 32-byte token) + branded email
clicks "Confirm and set up      ──►  wp-login.php?action=passkey-signup&token=…
my passkey"                        ├ token unknown / expired / used → join page with the "expired" notice
                                   │  (or a friendly wp-login.php page while no join page is chosen)
                                   └ create user (role from settings, random password, username from
                                     the email's local part, de-duplicated), fire user_register,
                                     delete the row, sign in, redirect to passkey setup → the page
                                     the visitor joined from, else the continue URL
```

- **Back to where they started.** When the join page is opened with `?redirect_to=` (the comment
  form card does this, and so does "Create an account" on the sign-in page), the form sends it
  along, the plugin keeps it on the pending row when it stays on this site, and passkey setup
  continues there instead of the continue URL. The script reads it from the address bar, so a
  cached copy of the join page cannot hand one visitor's return address to another. "Already a
  member? Sign in" carries it too.

- **Nothing is created until the click.** Unverified requests live only in
  `wp_riviantrackr_pending_signups` (email, display name, hashed token, hashed IP, return address, expiry) and are
  purged on every signup request and by a daily WP-Cron event (`riviantrackr_passkeys_purge_signups`,
  scheduled on activation, cleared on deactivation). Pending count is shown on the settings screen.
- **New members have no password they know.** The account is created with a random 32-character
  password and flagged (`riviantrackr_passkeys_no_password`), so the account page offers **Set a
  password** (an emailed reset link) instead of a change form until one is chosen.
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
| GET | `/signup/token` | public | fresh `ts`/`sig` form token, `Cache-Control: no-store`; the form fetches it on load so cached join pages keep working |
| POST | `/signup/request` | public | 5/hour per IP; `email`, `name`, `website` (honeypot), `ts`, `sig`, `turnstile`, `redirect_to` (kept when on this site); `rtpk_signup_form` when sent too soon, `rtpk_signup_form_expired` when the token is forged or over 12 hours old |
| GET | `/credentials` | user | own list, or `?user_id=` with `edit_user` capability |
| POST | `/credentials/{id}` | user | rename (own only) |
| DELETE | `/credentials/{id}` | user | own, or `edit_user` capability |
| POST | `/account/profile` | user | `display_name`, `first_name`, `last_name`, `email`, `url`, `description`; field errors in `data.fields`; an email change returns `pendingEmail` |
| POST | `/account/email/cancel` | user | drop a pending email change |
| POST | `/account/password` | user | 5/min per user; `current`, `new`, `confirm`; re-issues this browser's cookie, signs out other devices |
| POST | `/account/password/link` | user | 3/hour per user (plus the per-IP reset limit); emails the standard reset link ("Set a password" / "Forgot your current password?") |
| POST | `/account/avatar` | user | multipart field `avatar`; 10/hour per user; type, size and dimensions checked server-side; returns `url` |
| DELETE | `/account/avatar` | user | remove the photo (back to Gravatar); returns the Gravatar `url` |
| POST | `/account/data/export` | user | files a confirmed personal-data export request; 409 when one is already open |
| DELETE | `/account` | user | `confirm` = username; deletes the account (`deleted: true`, `redirect`) or files a confirmed erasure request (`deleted: false`) depending on role and the setting |
| POST | `/account/sessions/others` | user | sign out every other browser/device |
| DELETE | `/account/reviews/{tire_id}` | user | delete own tire review (tire guide active) |
| DELETE | `/account/favorites/{tire_id}` | user | take a tire off the saved list (tire guide active) |
| GET | `/account/garage` | user | the member's garage |
| POST | `/account/garage/vehicles` | user | 30/min per user; `model`, `year`, `generation` (optional, from the year), `nickname`, `wheels[]` (`wheel_id`, `size`, `name`, `label`), `make_default`; field errors in `data.fields` (`wheels.1.size`); 409 when the garage holds five |
| POST | `/account/garage/vehicles/{id}` | user | edit a vehicle (same fields) |
| DELETE | `/account/garage/vehicles/{id}` | user | remove a vehicle; the next one becomes the default |
| POST | `/account/garage/default` | user | `id`; make a vehicle the default |

## Hooks

```php
// Deny or allow a verified passkey login (return false or WP_Error to deny).
add_filter( 'riviantrackr_passkeys_pre_login', fn( $allowed, WP_User $user, array $credential ) => $allowed, 10, 3 );

// Accept additional origins (normalised "scheme://host[:port]").
add_filter( 'riviantrackr_passkeys_allowed_origins', fn( array $origins ) => $origins );

// The comment form card (see "Comment form"): change or hide it, or switch it to light colours.
add_filter( 'riviantrackr_passkeys_comment_prompt', fn( string $html, int $post_id, bool $required ) => $html, 10, 3 );
add_filter( 'riviantrackr_passkeys_comment_prompt_theme', fn( string $theme, int $post_id ) => $theme, 10, 2 );

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

// Keep WordPress's plain-text password reset / welcome / email-change emails.
add_filter( 'riviantrackr_passkeys_brand_core_emails', '__return_false' );

// Change the branded email-change confirmation before it is sent (to, subject, message, headers).
add_filter( 'riviantrackr_passkeys_email_change_mail', fn( array $mail, WP_User $user, string $new_email, string $link ) => $mail, 10, 4 );

// Name more authenticators (AAGUID => label) for default passkey names and the provider badge.
add_filter( 'riviantrackr_passkeys_authenticator_names', fn( array $names ) => $names + [ '12345678-1234-1234-1234-123456789abc' => 'Acme Key' ] );

do_action( 'riviantrackr_passkeys_account_deleting', WP_User $user ); // just before a member deletes their own account
do_action( 'riviantrackr_passkeys_avatar_updated', WP_User $user, int $attachment_id ); // a member uploaded a new photo

// Allow bigger avatar uploads (bytes; never below 100 KB).
add_filter( 'riviantrackr_passkeys_avatar_max_bytes', fn() => 5 * 1024 * 1024 );

// The garage (see My Garage).
$garage = apply_filters( 'riviantrackr_garage', null, $user_id );
add_filter( 'riviantrackr_garage_models', fn( array $models ) => $models + [ 'r3' => [ 'label' => 'R3', 'platform' => 'r3', 'group' => 'R3', 'generations' => [ 'gen1' => [ 2027, 0 ] ] ] ] );
do_action( 'riviantrackr_garage_updated', int $user_id, array $garage ); // a vehicle was added, edited, removed or made the default
add_filter( 'riviantrackr_passkeys_garage_script', '__return_false' ); // stop printing window.riviantrackrGarage
```

## Development

```bash
composer install          # PHPUnit + the library source
vendor/bin/phpunit        # unit suite: WordPress stubs, a software P-256 authenticator driving real verification
npm install && npm test   # JS tests (node:test + jsdom): core helpers, login, signup, manager and account scripts
npm run build             # esbuild → assets/*.min.{js,css}
phpcs --standard=.phpcs.xml
```

The integration suite runs the plugin inside a real WordPress install with a real database through
WordPress's own PHPUnit test library (`WP_UnitTestCase`): the REST ceremonies end to end, the
wp-login.php routing against real pages and permalinks, the password reset flow from email to new
password, the account routes (password link, export, deletion, email confirmation), open signup and
the installer. It needs MySQL or MariaDB and PHPUnit 9 (WordPress's library does not run on 10), kept
in its own `tests/integration/composer.json`:

```bash
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest   # WordPress + test library (WP_TESTS_DIR, WP_CORE_DIR to relocate)
composer install -d tests/integration                              # PHPUnit 9 + Yoast polyfills
tests/integration/vendor/bin/phpunit -c phpunit.integration.xml
```

Where wordpress.org and its Subversion server are unreachable, `WP_FROM_GITHUB=true` makes the
install script take core and the test library from a shallow clone of `WordPress/wordpress-develop`
instead. CI runs the suite against the latest WordPress on PHP 8.2 and 8.4 with a MariaDB service.

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
