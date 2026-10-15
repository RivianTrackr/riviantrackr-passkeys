# Changelog

All notable changes to RivianTrackr Passkeys will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-15

### Changed
- **Passkey button now leads the login form.** On `wp-login.php` and forms built with `wp_login_form()`, the "Sign in with a passkey" block is inserted at the top of the form with an "or" divider beneath it, and the username/password fields follow — the same layout Rivian Roamer uses. Previously the block was appended after the floated Log In row, which left the divider sitting beside the submit button.
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

[Unreleased]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/RivianTrackr/riviantrackr-passkeys/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/RivianTrackr/riviantrackr-passkeys/releases/tag/v1.0.0
