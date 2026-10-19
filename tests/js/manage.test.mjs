import { test } from 'node:test';
import assert from 'node:assert/strict';
import { page, mockFetch, flush, fakeCredential } from './dom.mjs';

const REST = 'https://example.test/wp-json/riviantrackr-passkeys/v1';
const i18n = { working: 'Working…', added: 'Passkey added', removed: 'Removed', renamed: 'Renamed', confirmDelete: 'Remove?', unsupported: 'No passkeys here', security: 'Needs HTTPS', genericError: 'Oops', added_on: 'Added %s', never_used: 'Never used', synced: 'Synced' };

function markup({ items = [], setup = false } = {}) {
	const list = items.map((c) => `
		<li class="rtpk-item" data-rtpk-item data-id="${c.id}">
			<div class="rtpk-item__body"><div class="rtpk-item__name-row"><span class="rtpk-item__name" data-rtpk-name>${c.name}</span></div></div>
			<div class="rtpk-item__actions"><button type="button" data-rtpk-rename>Rename</button><button type="button" data-rtpk-delete>Remove</button></div>
		</li>`).join('');
	return `
		<div class="rtpk-manager is-owner" data-rtpk-manager data-user-id="7" data-owner="1" ${setup ? 'data-rtpk-setup' : ''}>
			<button type="button" data-rtpk-add><svg class="rtpk-icon"></svg><span>Add</span></button>
			<p data-rtpk-msg hidden></p>
			<ul data-rtpk-list>${list}</ul>
			<p data-rtpk-empty ${items.length ? 'hidden' : ''}>No passkeys yet.</p>
		</div>
		${setup ? '<div data-rtpk-done hidden><a href="/account/">Continue</a></div><p data-rtpk-skip><a href="/">Skip</a></p>' : ''}`;
}

const globals = { rtpkManage: { restUrl: REST, nonce: 'n0nce', i18n } };

test('without WebAuthn the add button is disabled with an explanation', () => {
	const { document } = page(markup(), ['passkeys-manage'], { webauthn: false, globals });
	assert.equal(document.querySelector('[data-rtpk-add]').disabled, true);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'No passkeys here');
});

test('registering runs options → create → verify, renders the new row and sends the nonce', async () => {
	let created = null;
	const fetch = mockFetch({
		'POST register/options': { challengeId: 'ch-r', publicKey: { challenge: 'AQID', user: { id: 'BAUG', name: 'jose', displayName: 'Jose' }, rp: { id: 'example.test' } } },
		'POST register/verify': (body) => ({ credential: { id: 42, name: body.name, provider: 'iCloud Keychain', backedUp: true, createdAt: 'Oct 18, 2026', lastUsedAt: '' } }),
	});
	const ctx = page(markup(), ['passkeys-manage'], {
		fetch,
		globals,
		create: (request) => {
			created = request;
			return Promise.resolve(fakeCredential(ctx.window));
		},
	});
	const { document } = ctx;
	document.querySelector('[data-rtpk-add]').click();
	await flush();

	assert.deepEqual(new Uint8Array(created.publicKey.user.id), new Uint8Array([4, 5, 6]));
	assert.equal(fetch.calls[0].headers['X-WP-Nonce'], 'n0nce');
	assert.equal(fetch.calls[1].path, 'register/verify');
	assert.equal(fetch.calls[1].body.challengeId, 'ch-r');
	assert.deepEqual(fetch.calls[1].body.credential.response.transports, ['internal']);
	assert.ok(fetch.calls[1].body.name, 'a device label is proposed as the name');

	const item = document.querySelector('[data-rtpk-item]');
	assert.equal(item.getAttribute('data-id'), '42');
	assert.equal(item.querySelector('[data-rtpk-name]').textContent, fetch.calls[1].body.name);
	const badges = Array.from(item.querySelectorAll('.rtpk-badge')).map((b) => b.textContent);
	assert.deepEqual(badges, ['iCloud Keychain', 'Synced']);
	assert.match(item.querySelector('[data-rtpk-meta]').textContent, /Added Oct 18, 2026 · Never used/);
	assert.equal(document.querySelector('[data-rtpk-empty]').hidden, true);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Passkey added');
});

test('on the setup page a successful registration reveals the done panel and hides skip', async () => {
	const fetch = mockFetch({
		'POST register/options': { challengeId: 'ch-r', publicKey: { challenge: 'AQID', user: { id: 'BAUG', name: 'jose', displayName: 'Jose' } } },
		'POST register/verify': { credential: { id: 1, name: 'Mac', provider: '', backedUp: false, createdAt: '', lastUsedAt: '' } },
	});
	const ctx = page(markup({ setup: true }), ['passkeys-manage'], { fetch, globals, create: () => Promise.resolve(fakeCredential(ctx.window)) });
	ctx.document.querySelector('[data-rtpk-add]').click();
	await flush();
	assert.equal(ctx.document.querySelector('[data-rtpk-done]').hidden, false);
	assert.equal(ctx.document.querySelector('[data-rtpk-skip]').hidden, true);
	assert.equal(ctx.document.activeElement, ctx.document.querySelector('[data-rtpk-done] a'), 'focus lands on Continue');
});

test('a duplicate authenticator is explained and the button recovers', async () => {
	const fetch = mockFetch({ 'POST register/options': { challengeId: 'ch-r', publicKey: { challenge: 'AQID', user: { id: 'BAUG', name: 'j', displayName: 'J' } } } });
	const err = Object.assign(new Error('dup'), { name: 'InvalidStateError' });
	const { document } = page(markup(), ['passkeys-manage'], { fetch, globals: { rtpkManage: { restUrl: REST, i18n: { ...i18n, alreadyRegistered: 'Already added' } } }, create: () => Promise.reject(err) });
	const btn = document.querySelector('[data-rtpk-add]');
	btn.click();
	await flush();
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Already added');
	assert.equal(btn.disabled, false);
	assert.equal(document.querySelector('[data-rtpk-item]'), null);
});

test('removing asks for confirmation, calls DELETE and shows the empty state again', async () => {
	const fetch = mockFetch({ 'DELETE credentials/5': { success: true } });
	const ctx = page(markup({ items: [{ id: 5, name: 'Old key' }] }), ['passkeys-manage'], { fetch, globals });
	const { document, window } = ctx;

	window.confirm = () => false;
	document.querySelector('[data-rtpk-delete]').click();
	await flush();
	assert.equal(fetch.calls.length, 0, 'declining the confirm does nothing');

	window.confirm = () => true;
	document.querySelector('[data-rtpk-delete]').click();
	await flush();
	assert.equal(fetch.calls[0].method, 'DELETE');
	assert.equal(fetch.calls[0].path, 'credentials/5');
	assert.equal(document.querySelector('[data-rtpk-item]'), null);
	assert.equal(document.querySelector('[data-rtpk-empty]').hidden, false);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Removed');
});

test('renaming edits inline, saves over REST and Escape cancels', async () => {
	const fetch = mockFetch({ 'POST credentials/5': (body) => ({ credential: { id: 5, name: body.name.toUpperCase() } }) });
	const { document, window } = page(markup({ items: [{ id: 5, name: 'Old key' }] }), ['passkeys-manage'], { fetch, globals });
	const item = document.querySelector('[data-rtpk-item]');

	item.querySelector('[data-rtpk-rename]').click();
	const input = item.querySelector('.rtpk-item__edit-input');
	assert.ok(input, 'an inline editor appears');
	assert.equal(input.value, 'Old key');

	input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(item.querySelector('.rtpk-item__edit'), null, 'Escape closes the editor');

	item.querySelector('[data-rtpk-rename]').click();
	const editor = item.querySelector('.rtpk-item__edit');
	editor.querySelector('.rtpk-item__edit-input').value = '  work laptop ';
	editor.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
	await flush();

	assert.deepEqual(fetch.calls[0].body, { name: 'work laptop' });
	assert.equal(item.querySelector('[data-rtpk-name]').textContent, 'WORK LAPTOP', 'the server\'s version of the name is shown');
	assert.equal(item.querySelector('.rtpk-item__edit'), null);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Renamed');
});
