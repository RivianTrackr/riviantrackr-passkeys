# Changelog

All notable changes to RivianTrackr Passkeys will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-10-18

### Fixed
- **Fatal error on the account page with an older Rivian Tire Guide build** (`Call to undefined method RTG_Database::get_user_favorites()`), which also broke the block editor's preload when editing the page. The tire guide bridge now feature-detects every `RTG_Database` helper (`get_user_reviews`, `delete_user_rating`, `get_user_favorites`, `remove_favorite`, `get_tire`) and falls back to the `wp_rtg_ratings`, `wp_rtg_favorites` and `wp_rtg_tires` tables when a helper is missing. The Reviews and Favorites tabs, their overview counts and their REST routes are now enabled independently (`TireGuide::supports_reviews()` / `supports_favorites()`): a tab is hidden only when neither the helper nor the table exists. `AccountPage::default_tab_ids()` takes the two flags separately.

## [1.2.0] - 2026-10-18

### Added
- **Member account page.** New `[riviantrackr_account theme="dark|light"]` shortcode renders a tabbed "My Account" page in the dark "ink & brass" theme (left tab rail on desktop, stacked list under 600px, no icon-font dependency): **Overview** (avatar, name, email, member since, passkey/review/favorite/comment counts, quick links), **Profile** (display name, first/last name, email, website, bio; an email change stores WordPress's `_new_email` meta and emails the standard confirmation link, so it completes exactly like the profile screen and can be cancelled from the page), **Security** (the passkey manager, a change-password form that requires the current password and keeps this browser signed in while signing out every other device, and a "Sign out other devices" button), **Reviews** and **Favorites** (only while the Rivian Tire Guide is active: the member's approved and pending tire reviews with stars, status badge, excerpt, Edit link to the tire page and Delete; favorite tires with View and Remove) and **Comments** (recent approved comments with post links). Signed-out visitors get a sign-in link.
- **Tabs filter.** `riviantrackr_passkeys_account_tabs` lets other plugins add, remove or reorder sections (`id`, `label`, optional inline-SVG `icon`, `render` callable receiving the `WP_User` and a context array). Invalid entries are dropped, ids are slugified and de-duplicated.
- **REST routes** under `riviantrackr-passkeys/v1` (logged in, cookie nonce): `POST account/profile`, `POST account/email/cancel`, `POST account/password` (5/min per user), `POST account/sessions/others`, `DELETE account/reviews/{tire_id}`, `DELETE account/favorites/{tire_id}`. Field-level validation errors come back in `data.fields` and are shown inline.
- **Settings → Passkeys → Pages: Account page** (published page only). When set, members without `edit_posts` land on it after any login (password, passkey, invitation, signup) instead of the dashboard, both through the core `login_redirect` filter and the passkey REST login's redirect resolver (shared `AccountRedirect::target()` rule: an explicit front-end `redirect_to` is honoured, anything pointing at wp-admin goes to the account page); it becomes the default destination after passkey setup and after signup when those fields are blank. New toggles **Hide the admin bar for members without editing rights** (on by default, only while an account page is set) and **Add an "Account" link to the main menu** (off by default; appends to the theme's first registered menu location for signed-in members).
- **Hooks:** `riviantrackr_passkeys_profile_updated` (`$user, $data`) and `riviantrackr_passkeys_password_changed` (`$user`) actions.
- `rtpkCore.request()` now exposes the REST error's `data` on the thrown error (`err.data`), so callers can read field-level errors.

## [1.1.1] - 2026-10-16

### Fixed
- **Passkey invitations never sent: "This send job has expired. Start again."** The batch job ID was generated with `wp_generate_password()` (mixed case) but every follow-up request ran it through `sanitize_key()` (lowercase), so the job transient was never found. Job IDs are now lowercase hex.

## [1.1.0] - 2026-10-15

### Added
- **Custom login pages.** New `[riviantrackr_login theme="dark|light" redirect=""]` shortcode renders `wp_login_form()` in the plugin's card: passkey button first, "or", username, password, "Keep me signed in", "Sign in", "Lost your password?" and (while signups are allowed and a join page is set) "Create an account". It shows messages for `?login=failed`, `?login=empty`, `?loggedout=true`, `?checkemail=confirm`, `?rtpk_invite=expired` and `?rtpk_signup=exists`, the "sign in once with your password" hint when `redirect_to` leads to passkey setup, and "You're signed in as …" with a Continue link for signed-in visitors.
- **Settings → Passkeys → Pages:** pick a **Sign-in page** and a **Join page** (published pages only), **Send wp-login.php visitors to these pages** and **Brand the remaining wp-login.php screens**.
- **wp-login.php routing** (`LoginRouter`, when the redirect toggle is on and a sign-in page is set): `login_url` points at the sign-in page (keeping `redirect_to`; forced reauth stays on wp-login.php), `register_url` at the join page; GET requests for the login or register screen are redirected with `redirect_to`, `login`, `loggedout`, `checkemail`, `rtpk_invite` and `rtpk_signup` carried over; `wp_login_failed` and empty-credential `authenticate` results send the visitor back with `?login=failed|empty`. POST requests, `interim-login`, `reauth`, signed-in visitors and the `postpass`, `logout`, `lostpassword`, `retrievepassword`, `resetpass`, `rp`, `confirmaction`, `passkey-setup` and `passkey-signup` actions are never redirected. The decision is a pure `LoginRouter::resolve()` covered by tests.
- **wp-login.php skin** (`LoginBranding`, `assets/passkeys-wp-login.css`): dark `#121418` page, `#16191e` card, `#3a3e45` inputs and borders, warm-neutral text, gold `#fba919` primary with `#15130e` text, gold links and checkbox; the WordPress logo is replaced by the invitations logo → theme custom logo → site icon → site name, and the header links to the home page. `passkeys-setup.css` gains a `body.rtpk-branded` variant so the passkey setup and signup landing pages (and the manager/signup form embedded in them) match.

- **Open signup without "Anyone can register".** New `[riviantrackr_signup]` shortcode (dark "ink & brass" by default, `theme="light"`) with email and optional name. Requests go to `POST riviantrackr-passkeys/v1/signup/request`; **no WordPress user is created** until the visitor clicks the emailed link. Pending requests live in a new `wp_riviantrackr_pending_signups` table (SHA-256 of a 32-byte single-use token, hashed IP, configurable expiry, default 24 hours) and are purged as they expire.
- **Spam defences on signup:** hidden honeypot field, signed minimum-time check (3 s), 5 requests/hour per IP and 3/hour per address, a built-in list of ~170 disposable-email domains (subdomains included) plus an admin-editable list, and optional Cloudflare Turnstile (site/secret keys; verified server-side via `siteverify`, skipped when the keys are blank). The response is always "check your inbox"; an address that already has an account gets a sign-in reminder instead of a signup link, so nothing reveals who is registered.
- **Verification landing page** at `wp-login.php?action=passkey-signup&token=…`: creates the user (role from settings, never Administrator; random password; username derived from the email's local part and de-duplicated), fires `user_register`, signs the member in and redirects to the existing passkey setup page with the configured continue URL. Expired or used tokens show a friendly page with a "send me a new link" form.
- **Settings → Passkeys → Open signup:** allow toggle with pending-link count, role, link expiry, continue URL, Turnstile keys, extra blocked domains, verification email subject/message, admin notification toggle.
- **Hooks:** `riviantrackr_passkeys_signup_allowed` filter (`$allowed, $email, $input`), `riviantrackr_passkeys_signup_email` and `riviantrackr_passkeys_blocked_domains` filters; `riviantrackr_passkeys_signup_requested`, `_signup_verified` and `_signup_login` actions. Constants `RTPK_SIGNUP_IP_RATE_LIMIT`, `RTPK_SIGNUP_EMAIL_RATE_LIMIT`, `RTPK_SIGNUP_MIN_SECONDS`.
- **Passkey invitations.** New **Users → Passkey invitations** screen: see how many members have a passkey, filter by role and previous invitations, edit the email (plain text with `{name}`, `{site}`, `{link}`, `{expires}`, `{login_url}` placeholders, wrapped in a branded HTML template), send yourself a test, then send in batches of 20 with a live progress log. Only members without a passkey are ever emailed.
- **Users list integration.** A "Passkeys" column (count, or "Invited N ago"), a "Send passkey invite" row action, and a "Send passkey invitation" bulk action.
- **One-tap setup page** at `wp-login.php?action=passkey-setup`: a focused screen with the pitch, a single "Create a passkey" button, a "Continue" button on success and "Skip for now". Invitation links carry a single-use token (SHA-256 hash stored in user meta, expiry configurable, default 7 days). With "Link signs the member in" enabled (default), a valid token sets the auth cookie and opens setup directly — the same trust level as a password-reset email; otherwise, or once the token is spent, the normal login form shows a hint and redirects to setup after sign-in.
- **Hooks:** `riviantrackr_passkeys_invite_email` filter; `riviantrackr_passkeys_invite_sent` and `riviantrackr_passkeys_invite_login` actions.
- Settings → Passkeys links to the invitations screen.
- **Email branding.** The invitation is rendered in the site's dark "ink & brass" palette (deep `#121418` background, `#16191e` card, warm-neutral text, gold button with dark text) or a light variant, with your logo in the header (media-library picker; defaults to the theme's custom logo, then the site icon, then the site name). A **Preview** button renders the current draft inline, and **From name / From email** apply to invitation emails only (an SMTP plugin that forces a from address still wins).
- **Bulleted lists in the email.** Lines starting with `*` or `-` render as a gold-bulleted list; the default message now lists what signing in unlocks (commenting, tire favourites and reviews, new-article notifications).
- **Asset cache-busting.** Scripts and styles are now versioned with the file's modification time, so every deploy refreshes browser caches without a version bump.

### Changed
- Invitation, setup and signup links are built from `site_url( 'wp-login.php' )` instead of `wp_login_url()`, so they keep pointing at wp-login.php when `login_url` is filtered. `SetupPage::expired_hint()`, `SetupPage::setup_hint()` and `SignupPage::exists_hint()` expose the hint copy shared with the sign-in page.
- `ProfileManager::markup()` is now public and accepts title/description/button/setup overrides so other screens can embed the manager.
- `InviteService::render_branded()` and `InviteService::deliver()` expose the branded email template and from-address handling so other emails (signup verification) share them. Database schema version is now 2.
- Uninstall also removes the pending-signups table and the invitation/signup option rows (unless "Keep passkeys when the plugin is deleted" is on).

## [1.0.1] - 2026-10-15

### Changed
- **Passkey button now leads the login form.** On `wp-login.php` and forms built with `wp_login_form()`, the "Sign in with a passkey" block is inserted at the top of the form with an "or" divider beneath it, and the username/password fields follow, so the passwordless path is the primary action. Previously the block was appended after the floated Log In row, which left the divider sitting beside the submit button.
- **Button restyled as a filled brand-gold primary** (`#fba919` with dark text) on both the light `wp-login.php` page and dark front-end forms; `.is-dark` now only adjusts the divider and status-message colours. The shortcode `[riviantrackr_passkey_login]` renders the same button.

### Fixed
- **CI: PHPCS job failed on a clean codebase.** The progress output printed before the checkstyle XML broke the `cs2pr` annotator; `phpcs` now runs with `-q` in the workflow.

## [1.0.0] - 2026-10-15

### Added
- **Passkey (WebAuthn / FIDO2) sign-in.** "Sign in with a passkey" on `wp-login.php` and `wp_login_form()` forms, plus browser autofill (conditional UI) so saved passkeys appear in the username field. Successful logins set the normal auth cookie and fire `wp_login`.
- **Self-service passkey manager** on the WordPress profile screen (add, rename, remove) and via the `[riviantrackr_passkeys]` shortcode for front-end account pages (dark "ink & brass" theme, `theme="light"` available). Administrators can remove passkeys from other users' profiles.
- **Settings → Passkeys** screen: relying party name and ID, user-verification policy (preferred/required), additional allowed origins, prompt timeout, login button and autofill toggles, keep-data-on-uninstall.
- **REST API** under `riviantrackr-passkeys/v1`: `register/options`, `register/verify`, `login/options`, `login/verify`, `credentials`, `credentials/{id}` (rename, delete).
- **Security model:** single-use challenges that expire after five minutes, exact-origin matching on `clientDataJSON.origin` ahead of the library's suffix check, signature-counter regression detection for cloned authenticators, per-IP rate limits on login endpoints and per-user on registration, opaque 32-byte user handles, and a cap of 10 passkeys per account.
- **Hooks:** `riviantrackr_passkeys_pre_login` (deny with `false` or `WP_Error`), `riviantrackr_passkeys_allowed_origins`, `riviantrackr_passkeys_client_ip`; actions `riviantrackr_passkeys_registered`, `_login`, `_deleted`, `_counter_mismatch`.
- **Bundled `lbuchs/webauthn` v2.2** (MIT) under `lib/`, autoloaded by the plugin; no Composer required on the server.
- **Tooling:** PHPUnit suite with a software P-256 authenticator that drives real registration and assertion verification, `node:test` JS tests, PHPCS (WordPress standard), esbuild minification, and GitHub Actions CI on PHP 8.1–8.4.

[Unreleased]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/RivianTrackr/riviantrackr-passkeys/releases/tag/v1.0.0
