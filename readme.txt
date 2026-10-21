=== RivianTrackr Passkeys ===
Contributors: riviantrackr
Tags: passkeys, webauthn, fido2, passwordless, login, security
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless, phishing-resistant sign-in with passkeys (Face ID, Touch ID, Windows Hello, security keys).

== Description ==

Adds passkey (WebAuthn / FIDO2) sign-in to WordPress.

* "Sign in with a passkey" button on wp-login.php and wp_login_form() forms
* Browser autofill (conditional UI) for saved passkeys
* Members add, rename and remove passkeys from their profile or via the [riviantrackr_passkeys] shortcode
* Settings screen for relying party, user verification policy, allowed origins and timeout
* Invite members without a passkey by email: personal one-tap setup link, batch sending by role, per-user and bulk actions on the Users list
* A front-end member account page via [riviantrackr_account]: profile photo, names and bio, email changes confirmed on the page, password changes (or "Set a password" for accounts created by signup), passkeys, sign out other devices, tire reviews and saved tires (with the Rivian Tire Guide), comments, a copy of their data and account deletion; members land there after signing in instead of the dashboard
* Your own sign-in, password reset and passkey setup pages via [riviantrackr_login], [riviantrackr_password_reset] and [riviantrackr_passkey_setup], with wp-login.php's login, registration, reset and setup screens redirected there, WordPress's sign-in emails (password reset, welcome, email change) in your branded template, and the remaining wp-login.php screens restyled in your colours with your logo
* Spam-resistant open signup via [riviantrackr_signup]: no account until the emailed link is clicked; honeypot, timing check, rate limits, disposable-domain blocklist, optional Cloudflare Turnstile
* Single-use challenges, strict origin checks, signature-counter clone detection, rate limiting
* No external dependencies at runtime

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Review Settings → Passkeys (defaults work for most HTTPS sites).
3. Add your first passkey from Users → Profile.

== Frequently Asked Questions ==

= Does this replace passwords? =
No. Passkeys are offered alongside the normal password form. Members who register a passkey can use either.

= Why does the button not show up? =
The site must be served over HTTPS and the browser must support WebAuthn.

= Can visitors sign up without "Anyone can register"? =
Yes. Enable "Allow signups" under Settings → Passkeys and add the [riviantrackr_signup] shortcode to a page. Visitors enter their email, click the link we send, and only then is the account created and passkey setup shown.

= Can I replace wp-login.php with my own page? =
Yes. Put [riviantrackr_login] on a page and [riviantrackr_password_reset] on another, choose them under Settings → Passkeys → Pages and turn on "Send wp-login.php visitors to these pages". "Lost your password?" links and the emailed reset link then lead to your reset page. Logout and the passkey landing pages keep working on wp-login.php, and the standard form stays reachable at wp-login.php?reauth=1. Exclude the pages from full-page caching.

= Can members have an account page instead of the dashboard? =
Yes. Put [riviantrackr_account] on a page, choose it under Settings → Passkeys → Pages as the Account page, and exclude it from full-page caching. Members without editing rights then land there after signing in, the admin bar is hidden for them, and the passkey setup and signup flows end there unless you set another continue URL.

= Can members download their data or delete their account themselves? =
Yes. The account page's "Your data" tab files a confirmed export request for you to fulfil under Tools → Export Personal Data, and lets members without editing rights delete their account immediately after typing their username (passkeys go with it; comments and reviews stay without a linked account). Editors and administrators, or everyone when you turn "Let members delete their own account" off, get an erasure request under Tools → Erase Personal Data instead.

= Can passkeys work across subdomains? =
Yes. Set the relying party ID to the parent domain and list each subdomain origin under "Additional allowed origins". Existing passkeys must be re-registered after changing the RP ID.

== Changelog ==

= 1.7.1 =
* Fix: signing up from a join page served by a full-page cache (Cloudflare APO, WP Rocket and the like) failed with "That was quick…" once the cached copy was over 12 hours old. The form now fetches a fresh token when it loads.
* The signup form tells "too quick" apart from "this page has expired".

= 1.7.0 =
* My Garage: members add the Rivians they drive on a new Garage tab of the account page (model, model year, generation, nickname and up to four wheel sets each, from the Tire Guide's wheels or with the tire size typed in) and pick a default. Other RivianTrackr plugins read it through the riviantrackr_garage filter and window.riviantrackrGarage.
* [riviantrackr_garage_cta] asks visitors who are signed out to create an account and save their Rivian, and members with an empty garage to add one.
* The garage is included in personal-data exports and removed by erasure requests, account deletion and uninstall.

= 1.6.0 =
* Profile photos: members upload a JPG, PNG, GIF or WebP on the account page's Profile tab (up to 2 MB, cropped to a centred square of at most 512px, stored in the media library as a private attachment they own) and it shows wherever WordPress displays their avatar; "Remove photo" goes back to Gravatar, and deleting the account deletes the photo.

= 1.5.0 =
* Passkey setup page: [riviantrackr_passkey_setup] puts the one-tap setup screen in the sign-in card. Pick it under Settings → Passkeys → Pages and invitation links and new signups land there; expired signup links land on the join page with a notice.
* Branded emails: the password reset, welcome, new-member and email-change emails now use the invitation template, logo and from address. Filter riviantrackr_passkeys_brand_core_emails to keep WordPress's text.
* Account page: "Set a password" for accounts created by signup (they had a random password nobody knew), "Forgot your current password?" on the change form, email changes confirmed on the account page instead of wp-admin, and a new "Your data" tab to request a copy of personal data or delete the account (setting: "Let members delete their own account").
* The password reset limit now applies inside retrieve_password(), so posting to wp-login.php directly cannot bypass it; an unknown email reads as "sent" like an unknown username.
* More passkey providers recognised (Proton Pass, KeePassXC, Chrome, YubiKey 5 FIPS and Security Key models); extend with the riviantrackr_passkeys_authenticator_names filter.
* Expired pending signups are purged daily by WP-Cron; uninstall removes invitation and password-flag user meta; CI runs on every branch, adds an integration suite against a real WordPress install, and the JS suite now covers the login, signup, manager and account scripts.

= 1.4.0 =
* Password reset page: the [riviantrackr_password_reset] shortcode (dark/light) puts "Reset your password" and "Choose a new password" in the same card as the sign-in page, as plain forms with no JavaScript. Pick it under Settings → Passkeys → Pages as the Password reset page: "Lost your password?" links and the standard reset email's link then lead there, and wp-login.php's reset screens are redirected while the redirect toggle is on. The request never reveals whether an account exists, is limited to 5 emails per hour per IP, and the emailed key is kept out of the URL like wp-login.php does.

= 1.3.0 =
* The account page's Favorites tab is now Saved tires, fed by the Rivian Tire Guide's heart (guide 2.14.0 or later): each saved tire with today's price, how it has moved since you saved it, the save date, View and Remove, and a link to the guide's saved tires page. The retired favorites table is no longer read.

= 1.2.2 =
* The Edit button on the account page's Reviews tab now opens the tire guide's review page with that review loaded into the form (tire guide 2.14.1 or later; older builds land on the review page with the tire picked).

= 1.2.1 =
* Fixed a fatal error on the account page when an older Rivian Tire Guide build lacks RTG_Database::get_user_favorites() (or other helpers): the bridge now feature-detects each helper and reads the tire guide tables directly, hiding the Reviews or Favorites tab only when there is no data source at all.

= 1.2.0 =
* Member account page: [riviantrackr_account] shortcode (dark/light) with Overview, Profile (name, email with confirmation, website, bio), Security (passkeys, change password, sign out other devices), Reviews and Favorites from the Rivian Tire Guide, and Comments. Other plugins add tabs with the riviantrackr_passkeys_account_tabs filter.
* Settings → Passkeys → Pages: Account page picker. Members without editing rights land there after any login (password or passkey), passkey setup and signup default to it, the admin bar can be hidden for them, and an optional "Account" menu item is appended to the main menu.

= 1.1.1 =
* Fixed passkey invitations never sending ("This send job has expired") because the batch job ID was lower-cased on follow-up requests.

= 1.1.0 =
* Custom login pages: [riviantrackr_login] shortcode (dark/light), Settings → Passkeys → Pages to pick a sign-in and join page, redirect wp-login.php's login/register screens there (wp_login_url and wp_registration_url follow; failed or empty logins return with a message; reset, logout and passkey pages untouched), and an optional ink & brass skin with your logo for the remaining wp-login.php screens.
* Open signup: [riviantrackr_signup] form that creates no account until the emailed verification link is clicked. Honeypot, minimum-time check, per-IP and per-address rate limits, built-in and custom blocked email domains, optional Cloudflare Turnstile. New members are signed in and taken straight to passkey setup. Settings → Passkeys → Open signup.
* Passkey invitations: email members without a passkey a personal setup link (Users → Passkey invitations, plus Users-list row and bulk actions) and a focused "Set up a passkey" page at wp-login.php?action=passkey-setup. Branded email with your logo in the site's colours, inline preview, custom from name/address.

= 1.0.1 =
* Passkey button now leads the login form, above an "or" divider, so the passwordless path is the primary action.
* Button restyled as a filled brand-gold primary on light and dark forms.

= 1.0.0 =
* Initial release.
