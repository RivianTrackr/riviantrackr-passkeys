=== RivianTrackr Passkeys ===
Contributors: riviantrackr
Tags: passkeys, webauthn, fido2, passwordless, login, security
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.1
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

= Can passkeys work across subdomains? =
Yes. Set the relying party ID to the parent domain and list each subdomain origin under "Additional allowed origins". Existing passkeys must be re-registered after changing the RP ID.

== Changelog ==

= Unreleased =
* Open signup: [riviantrackr_signup] form that creates no account until the emailed verification link is clicked. Honeypot, minimum-time check, per-IP and per-address rate limits, built-in and custom blocked email domains, optional Cloudflare Turnstile. New members are signed in and taken straight to passkey setup. Settings → Passkeys → Open signup.
* Passkey invitations: email members without a passkey a personal setup link (Users → Passkey invitations, plus Users-list row and bulk actions) and a focused "Set up a passkey" page at wp-login.php?action=passkey-setup. Branded email with your logo in the site's colours, inline preview, custom from name/address.

= 1.0.1 =
* Passkey button now leads the login form, above an "or" divider, so the passwordless path is the primary action.
* Button restyled as a filled brand-gold primary on light and dark forms.

= 1.0.0 =
* Initial release.
