import { test } from 'node:test';
import assert from 'node:assert/strict';
import { page, mockFetch, flush } from './dom.mjs';

const REST = 'https://example.test/wp-json/riviantrackr-passkeys/v1';
const i18n = { saving: 'Saving…', genericError: 'Oops', confirmSignOutOthers: 'Sign out others?', confirmDeleteAccount: 'Delete?', pendingEmail: 'A confirmation link was sent to %s.' };

function tab(id, active) {
	return `<a role="tab" data-rtpk-tab="${id}" href="#${id}" class="rtpk-account__tab${active ? ' is-active' : ''}" aria-selected="${active}" tabindex="${active ? 0 : -1}">${id}</a>`;
}
function panel(id, active, inner = '') {
	return `<section data-rtpk-panel="${id}" ${active ? '' : 'hidden'}>${inner}</section>`;
}

const profileForm = `
	<form data-rtpk-profile-form novalidate>
		<div data-rtpk-field="display_name"><input name="display_name" value="Jose"><p data-rtpk-error hidden></p></div>
		<div data-rtpk-field="email"><input name="email" value="jose@example.com"><p data-rtpk-error hidden></p></div>
		<div data-rtpk-field="description"><textarea name="description">Hi</textarea><p data-rtpk-error hidden></p></div>
		<button type="submit">Save</button><span data-rtpk-form-msg></span>
	</form>
	<div data-rtpk-pending-email hidden><span data-rtpk-pending-text></span><button type="button" data-rtpk-cancel-email>Cancel</button></div>`;

const securityPanel = `
	<form data-rtpk-password-form novalidate>
		<div data-rtpk-field="current"><input type="password" name="current"><p data-rtpk-error hidden></p></div>
		<div data-rtpk-field="new"><input type="password" name="new"><p data-rtpk-error hidden></p></div>
		<div data-rtpk-field="confirm"><input type="password" name="confirm"><p data-rtpk-error hidden></p></div>
		<button type="submit">Change</button><span data-rtpk-form-msg></span>
	</form>
	<button type="button" data-rtpk-password-link>Email me a reset link</button>
	<button type="button" data-rtpk-signout-others>Sign out others</button>`;

const dataPanel = `
	<button type="button" data-rtpk-export-data>Request my data</button>
	<form data-rtpk-delete-form data-username="jose" novalidate>
		<div data-rtpk-field="confirm"><input name="confirm"><p data-rtpk-error hidden></p></div>
		<button type="submit" disabled>Delete</button><span data-rtpk-form-msg></span>
	</form>`;

function markup() {
	return `
		<div class="rtpk-account" data-rtpk-account>
			<h2 class="rtpk-account__name">Jose</h2>
			<nav role="tablist">${tab('overview', true)}${tab('profile', false)}${tab('security', false)}${tab('data', false)}</nav>
			<p data-rtpk-msg hidden></p>
			${panel('overview', true, '<a data-rtpk-goto="security" href="#security">Passkeys</a>')}
			${panel('profile', false, profileForm)}
			${panel('security', false, securityPanel)}
			${panel('data', false, dataPanel)}
		</div>`;
}

const globals = { rtpkAccount: { restUrl: REST, nonce: 'n0nce', i18n } };

function submit(form) {
	form.dispatchEvent(new form.ownerDocument.defaultView.Event('submit', { bubbles: true, cancelable: true }));
}

function shown(document) {
	return Array.from(document.querySelectorAll('[data-rtpk-panel]')).filter((p) => !p.hidden).map((p) => p.getAttribute('data-rtpk-panel'));
}

test('tabs open from the URL hash, by click, by quick link and by arrow keys', () => {
	const { document, window } = page(markup(), ['passkeys-account'], { globals, url: 'https://example.test/account/#security' });
	assert.deepEqual(shown(document), ['security'], 'the hash picks the first panel');
	assert.equal(document.querySelector('[data-rtpk-tab="security"]').getAttribute('aria-selected'), 'true');

	document.querySelector('[data-rtpk-tab="profile"]').click();
	assert.deepEqual(shown(document), ['profile']);
	assert.equal(document.querySelector('[data-rtpk-tab="security"]').getAttribute('tabindex'), '-1');
	assert.equal(document.querySelector('[data-rtpk-tab="profile"]').getAttribute('tabindex'), '0');

	document.querySelector('[data-rtpk-goto="security"]').click();
	assert.deepEqual(shown(document), ['security']);

	document.querySelector('[data-rtpk-tab="security"]').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
	assert.deepEqual(shown(document), ['data']);
	document.querySelector('[data-rtpk-tab="data"]').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
	assert.deepEqual(shown(document), ['overview'], 'arrows wrap around');
	document.querySelector('[data-rtpk-tab="overview"]').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'End', bubbles: true, cancelable: true }));
	assert.deepEqual(shown(document), ['data']);
});

test('an unknown hash falls back to the first tab', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals, url: 'https://example.test/account/#nope' });
	assert.deepEqual(shown(document), ['overview']);
});

test('saving the profile posts the fields, updates the header and shows the pending email', async () => {
	const fetch = mockFetch({ 'POST account/profile': (body) => ({ message: 'Profile saved.', pendingEmail: body.email, profile: { displayName: body.display_name, email: 'jose@example.com' } }) });
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals });
	const form = document.querySelector('[data-rtpk-profile-form]');
	form.querySelector('[name="display_name"]').value = 'Jose R.';
	form.querySelector('[name="email"]').value = 'new@example.com';
	submit(form);
	await flush();

	assert.deepEqual(fetch.calls[0].body, { display_name: 'Jose R.', email: 'new@example.com', description: 'Hi' });
	assert.equal(fetch.calls[0].headers['X-WP-Nonce'], 'n0nce');
	assert.equal(document.querySelector('.rtpk-account__name').textContent, 'Jose R.');
	assert.equal(form.querySelector('[name="email"]').value, 'jose@example.com', 'the old address stays until confirmed');
	const pending = document.querySelector('[data-rtpk-pending-email]');
	assert.equal(pending.hidden, false);
	assert.match(document.querySelector('[data-rtpk-pending-text]').textContent, /new@example\.com/);
	assert.equal(form.querySelector('[data-rtpk-form-msg]').textContent, 'Profile saved.');
});

test('field errors from a 400 land next to their inputs and clear on the next save', async () => {
	let attempt = 0;
	const fetch = mockFetch({
		'POST account/profile': () => (++attempt === 1
			? { status: 400, body: { code: 'rtpk_invalid_profile', message: 'Please fix the highlighted fields.', data: { status: 400, fields: { email: 'Enter a valid email address.' } } } }
			: { message: 'Profile saved.', pendingEmail: '', profile: {} }),
	});
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals });
	const form = document.querySelector('[data-rtpk-profile-form]');
	submit(form);
	await flush();

	const wrap = form.querySelector('[data-rtpk-field="email"]');
	assert.equal(wrap.querySelector('[data-rtpk-error]').textContent, 'Enter a valid email address.');
	assert.equal(wrap.querySelector('[data-rtpk-error]').hidden, false);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Please fix the highlighted fields.');

	submit(form);
	await flush();
	assert.equal(wrap.querySelector('[data-rtpk-error]').hidden, true);
	assert.equal(document.querySelector('[data-rtpk-msg]').hidden, true);
});

test('changing the password clears the form on success', async () => {
	const fetch = mockFetch({ 'POST account/password': { message: 'Password changed.' } });
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals });
	const form = document.querySelector('[data-rtpk-password-form]');
	form.querySelector('[name="current"]').value = 'old';
	form.querySelector('[name="new"]').value = 'correct horse';
	form.querySelector('[name="confirm"]').value = 'correct horse';
	submit(form);
	await flush();
	assert.deepEqual(fetch.calls[0].body, { current: 'old', new: 'correct horse', confirm: 'correct horse' });
	assert.equal(form.querySelector('[name="new"]').value, '', 'reset after success');
});

test('the reset-link button and sign-out-others report the server message', async () => {
	const fetch = mockFetch({
		'POST account/password/link': { message: 'We emailed a link.' },
		'POST account/sessions/others': { message: 'Signed out everywhere else.' },
	});
	const { document, window } = page(markup(), ['passkeys-account'], { fetch, globals });
	document.querySelector('[data-rtpk-password-link]').click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'We emailed a link.');

	window.confirm = () => true;
	document.querySelector('[data-rtpk-signout-others]').click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Signed out everywhere else.');
	assert.equal(fetch.calls.length, 2);
});

test('data export asks the server and relays the message', async () => {
	const fetch = mockFetch({ 'POST account/data/export': { status: 409, body: { code: 'rtpk_export_failed', message: 'Already requested.' } } });
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals });
	document.querySelector('[data-rtpk-export-data]').click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Already requested.');
	assert.match(document.querySelector('[data-rtpk-msg]').className, /is-error/);
});

test('account deletion needs the username typed, a confirm, then follows the redirect', async () => {
	const fetch = mockFetch({ 'DELETE account': (body) => ({ deleted: true, message: 'Deleted.', redirect: 'https://example.test/?bye=' + body.confirm }) });
	const { document, window, location } = page(markup(), ['passkeys-account'], { fetch, globals });
	const form = document.querySelector('[data-rtpk-delete-form]');
	const input = form.querySelector('[name="confirm"]');
	const button = form.querySelector('[type="submit"]');

	assert.equal(button.disabled, true);
	input.value = 'jos';
	input.dispatchEvent(new window.Event('input', { bubbles: true }));
	assert.equal(button.disabled, true, 'a partial username keeps it disabled');
	input.value = ' JOSE ';
	input.dispatchEvent(new window.Event('input', { bubbles: true }));
	assert.equal(button.disabled, false, 'case and surrounding spaces are forgiven');

	window.confirm = () => false;
	submit(form);
	await flush();
	assert.equal(fetch.calls.length, 0, 'declining the confirm sends nothing');

	window.confirm = () => true;
	submit(form);
	await flush();
	assert.equal(fetch.calls[0].method, 'DELETE');
	assert.equal(fetch.calls[0].path, 'account');
	assert.deepEqual(location.assigned, ['https://example.test/?bye= JOSE ']);
});

test('a deletion that becomes an erasure request stays on the page', async () => {
	const fetch = mockFetch({ 'DELETE account': { deleted: false, message: 'The owner will review it.' } });
	const { document, window, location } = page(markup(), ['passkeys-account'], { fetch, globals });
	const form = document.querySelector('[data-rtpk-delete-form]');
	form.querySelector('[name="confirm"]').value = 'jose';
	window.confirm = () => true;
	submit(form);
	await flush();
	assert.deepEqual(location.assigned, []);
	assert.equal(form.querySelector('[data-rtpk-form-msg]').textContent, 'The owner will review it.');
	assert.equal(form.querySelector('[type="submit"]').disabled, true);
});
