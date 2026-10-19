import { test } from 'node:test';
import assert from 'node:assert/strict';
import { page, mockFetch, flush } from './dom.mjs';

const REST = 'https://example.test/wp-json/riviantrackr-passkeys/v1';
const i18n = {
	saving: 'Saving…',
	genericError: 'Oops',
	garageDefault: 'Default',
	garageMakeDefault: 'Make default',
	garageEdit: 'Edit',
	garageRemove: 'Remove',
	garageConfirmRemove: 'Remove?',
	garageAddTitle: 'Add a vehicle',
	garageEditTitle: 'Edit vehicle',
	garageNoWheels: 'No wheels added yet.',
	garageOtherWheel: 'Another wheel',
	garageYearsOpen: '%d+',
};

const models = {
	r1t: { label: 'R1T', group: 'R1', years: [2027, 2026, 2025, 2024, 2023, 2022], generations: [{ slug: 'gen1', label: 'Gen 1', from: 2022, to: 2024 }, { slug: 'gen2', label: 'Gen 2', from: 2025, to: 0 }] },
	r1s: { label: 'R1S', group: 'R1', years: [2027, 2026, 2025, 2024, 2023, 2022], generations: [{ slug: 'gen1', label: 'Gen 1', from: 2022, to: 2024 }, { slug: 'gen2', label: 'Gen 2', from: 2025, to: 0 }] },
	r2: { label: 'R2', group: 'R2', years: [2027, 2026], generations: [{ slug: 'gen1', label: 'Gen 1', from: 2026, to: 0 }] },
};

const wheels = [
	{ id: 3, name: '22" Sport Dark', sizes: ['275/50R22'], vehicles: ['R1T', 'R1S'], third_party: false },
	{ id: 4, name: '20" All-Terrain', sizes: ['275/60R20', '275/55R20'], vehicles: ['R1T', 'R1S'], third_party: false },
	{ id: 7, name: '21" Sport', sizes: ['255/45R21'], vehicles: ['R2'], third_party: false },
	{ id: 9, name: 'Method 703', sizes: ['35x12.50R18'], vehicles: ['R1T'], third_party: true },
	{ id: 11, name: 'Old entry', sizes: ['275/65R20'], vehicles: ['R1'], third_party: false },
];

function vehicle(over = {}) {
	return {
		id: 'aaaa1111',
		model: 'r1s',
		model_label: 'R1S',
		year: 2025,
		generation: 'gen2',
		generation_label: 'Gen 2',
		nickname: 'Big Blue',
		label: '2025 R1S',
		platform: 'r1',
		group: 'R1',
		wheels: [{ wheel_id: 3, name: '22" Sport Dark', size: '275/50R22', label: 'Summer', third_party: false }],
		sizes: ['275/50R22'],
		is_default: true,
		...over,
	};
}

function markup() {
	const options = Object.keys(models).map((slug) => `<option value="${slug}">${models[slug].label}</option>`).join('');
	return `
		<div class="rtpk-account" data-rtpk-account>
			<nav role="tablist"><a role="tab" data-rtpk-tab="overview" href="#overview">o</a><a role="tab" data-rtpk-tab="garage" href="#garage">g</a></nav>
			<p data-rtpk-msg hidden></p>
			<section data-rtpk-panel="overview">
				<div data-rtpk-garage-nudge><a href="#garage" data-rtpk-goto="garage">Add a vehicle</a></div>
				<a data-rtpk-goto="garage" href="#garage"><span class="rtpk-account__stat-value">0</span></a>
			</section>
			<section data-rtpk-panel="garage" hidden>
				<div data-rtpk-garage>
					<ul data-rtpk-garage-list></ul>
					<p data-rtpk-garage-empty hidden>No vehicles yet.</p>
					<div><button type="button" data-rtpk-garage-add>Add a vehicle</button><span data-rtpk-garage-full hidden>Full</span></div>
					<form data-rtpk-garage-form novalidate hidden>
						<h4 data-rtpk-garage-form-title>Add a vehicle</h4>
						<div data-rtpk-field="model"><label for="m">Model</label><select id="m" name="model"><option value="">Choose a model</option>${options}</select><p data-rtpk-error hidden></p></div>
						<div data-rtpk-field="year"><label for="y">Year</label><select id="y" name="year" disabled><option value="">Choose a year</option></select><p data-rtpk-error hidden></p></div>
						<div data-rtpk-field="generation"><label for="g">Generation</label><select id="g" name="generation" disabled></select><p data-rtpk-error hidden></p></div>
						<div data-rtpk-field="nickname"><label for="n">Nickname</label><input id="n" name="nickname"><p data-rtpk-error hidden></p></div>
						<fieldset>
							<div data-rtpk-garage-wheels></div>
							<div data-rtpk-field="wheels"><p data-rtpk-error hidden></p></div>
							<button type="button" data-rtpk-garage-add-wheel>Add a wheel set</button>
						</fieldset>
						<label><input type="checkbox" name="make_default" data-rtpk-garage-default> Default</label>
						<button type="submit">Save vehicle</button>
						<button type="button" data-rtpk-garage-cancel>Cancel</button>
						<span data-rtpk-form-msg></span>
					</form>
				</div>
			</section>
		</div>`;
}

function globals(vehicles = [], extra = {}) {
	return {
		rtpkAccount: { restUrl: REST, nonce: 'n0nce', i18n, garage: { vehicles, defaultId: vehicles.length ? vehicles[0].id : '', models, wheels, max: 5, maxWheels: 4, icon: '<svg></svg>' } },
		riviantrackrGarage: { signedIn: true, vehicles, defaultId: vehicles.length ? vehicles[0].id : '' },
		...extra,
	};
}

function choose(select, value) {
	select.value = value;
	select.dispatchEvent(new select.ownerDocument.defaultView.Event('change', { bubbles: true }));
}

function submit(form) {
	form.dispatchEvent(new form.ownerDocument.defaultView.Event('submit', { bubbles: true, cancelable: true }));
}

function wheelRows(document) {
	return Array.from(document.querySelectorAll('[data-rtpk-wheel-row]'));
}

test('an empty garage opens the form, with the first vehicle made the default', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals: globals() });
	const form = document.querySelector('[data-rtpk-garage-form]');

	assert.equal(form.hidden, false);
	assert.equal(document.querySelector('[data-rtpk-garage-add]').hidden, true);
	assert.equal(document.querySelector('[data-rtpk-garage-default]').checked, true);
	assert.equal(document.querySelector('[data-rtpk-garage-default]').disabled, true);
	assert.equal(wheelRows(document).length, 1, 'one blank wheel row to start');
	assert.equal(form.querySelector('[name="year"]').disabled, true, 'no year before a model');
});

test('picking a model fills the years, and the year picks the generation', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals: globals() });
	const form = document.querySelector('[data-rtpk-garage-form]');
	const year = form.querySelector('[name="year"]');
	const gen = form.querySelector('[name="generation"]');

	choose(form.querySelector('[name="model"]'), 'r1t');
	assert.equal(year.disabled, false);
	assert.deepEqual(Array.from(year.options).map((o) => o.value), ['', '2027', '2026', '2025', '2024', '2023', '2022']);
	assert.deepEqual(Array.from(gen.options).map((o) => o.textContent), ['Pick the year first', 'Gen 1 (2022–2024)', 'Gen 2 (2025+)']);
	assert.equal(gen.value, '', 'no generation until there is a year');
	assert.equal(gen.disabled, true);

	choose(year, '2023');
	assert.equal(gen.value, 'gen1');
	assert.equal(gen.disabled, false);
	assert.equal(gen.options.length, 2, 'the placeholder goes once a year is picked');
	choose(year, '2026');
	assert.equal(gen.value, 'gen2');
});

test('wheel choices follow the model: factory, aftermarket, then another wheel', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals: globals() });
	const form = document.querySelector('[data-rtpk-garage-form]');
	const pick = () => wheelRows(document)[0].querySelector('[data-rtpk-wheel-pick]');

	choose(form.querySelector('[name="model"]'), 'r1t');
	const groups = Array.from(pick().querySelectorAll('optgroup')).map((g) => [g.label, Array.from(g.children).map((o) => o.value)]);
	assert.deepEqual(groups, [['Rivian wheels', ['3', '4', '11']], ['Aftermarket', ['9']]], 'a wheel listed for the R1 group counts');
	assert.equal(pick().lastElementChild.value, '0');

	choose(form.querySelector('[name="model"]'), 'r2');
	assert.deepEqual(Array.from(pick().querySelectorAll('optgroup option')).map((o) => o.value), ['7']);
});

test('a guide wheel offers its sizes, another wheel asks for the size and a name', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals: globals() });
	choose(document.querySelector('[name="model"]'), 'r1s');
	const row = wheelRows(document)[0];
	const sizePick = row.querySelector('[data-rtpk-wheel-size-pick]');
	const sizeInput = row.querySelector('[data-rtpk-wheel-size-input]');
	const nameWrap = row.querySelector('[data-rtpk-wheel-field="name"]');

	assert.equal(row.querySelector('[data-rtpk-wheel-field="size"]').hidden, true, 'nothing to size before a wheel');

	choose(row.querySelector('[data-rtpk-wheel-pick]'), '4');
	assert.deepEqual(Array.from(sizePick.options).map((o) => o.value), ['275/60R20', '275/55R20']);
	assert.equal(sizePick.hidden, false);
	assert.equal(sizeInput.hidden, true);
	assert.equal(nameWrap.hidden, true);
	assert.equal(row.querySelector('[data-rtpk-wheel-field="size"] label').getAttribute('for'), sizePick.id);

	choose(row.querySelector('[data-rtpk-wheel-pick]'), '0');
	assert.equal(sizePick.hidden, true);
	assert.equal(sizeInput.hidden, false);
	assert.equal(nameWrap.hidden, false);
	assert.equal(row.querySelector('[data-rtpk-wheel-field="size"] label').getAttribute('for'), sizeInput.id, 'the label follows the visible control');
});

test('saving posts the vehicle with every wheel set and draws the list', async () => {
	const saved = vehicle({ wheels: [vehicle().wheels[0], { wheel_id: 0, name: 'Steelies', size: '275/65R18', label: 'Winter', third_party: false }] });
	const fetch = mockFetch({ 'POST account/garage/vehicles': { message: 'Added to your garage.', id: 'aaaa1111', garage: { vehicles: [saved], default_id: 'aaaa1111', default: saved } } });
	const events = [];
	const { document, window } = page(markup(), ['passkeys-account'], { fetch, globals: globals() });
	document.addEventListener('riviantrackr:garage', (e) => events.push(e.detail));
	const form = document.querySelector('[data-rtpk-garage-form]');

	choose(form.querySelector('[name="model"]'), 'r1s');
	choose(form.querySelector('[name="year"]'), '2025');
	form.querySelector('[name="nickname"]').value = 'Big Blue';
	let row = wheelRows(document)[0];
	choose(row.querySelector('[data-rtpk-wheel-pick]'), '3');
	row.querySelector('[data-rtpk-wheel-label]').value = 'Summer';
	document.querySelector('[data-rtpk-garage-add-wheel]').click();
	row = wheelRows(document)[1];
	choose(row.querySelector('[data-rtpk-wheel-pick]'), '0');
	row.querySelector('[data-rtpk-wheel-size-input]').value = '275/65r18';
	row.querySelector('[data-rtpk-wheel-field="name"] input').value = 'Steelies';
	row.querySelector('[data-rtpk-wheel-label]').value = 'Winter';
	submit(form);
	await flush();

	assert.equal(fetch.calls[0].path, 'account/garage/vehicles');
	assert.deepEqual(fetch.calls[0].body, {
		model: 'r1s',
		year: '2025',
		generation: 'gen2',
		nickname: 'Big Blue',
		wheels: [
			{ wheel_id: 3, size: '275/50R22', name: '', label: 'Summer' },
			{ wheel_id: 0, size: '275/65r18', name: 'Steelies', label: 'Winter' },
		],
		make_default: true,
	});
	assert.equal(form.hidden, true);
	const items = document.querySelectorAll('[data-rtpk-vehicle]');
	assert.equal(items.length, 1);
	assert.equal(items[0].querySelector('.rtpk-account__item-name').textContent, 'Big Blue');
	assert.equal(items[0].querySelector('.rtpk-account__item-meta').textContent, '2025 R1S · Gen 2');
	assert.equal(items[0].querySelector('.rtpk-badge').textContent, 'Default');
	assert.deepEqual(Array.from(items[0].querySelectorAll('.rtpk-garage__chip')).map((c) => c.textContent), ['Summer22" Sport Dark · 275/50R22', 'WinterSteelies · 275/65R18']);
	assert.equal(document.querySelector('[data-rtpk-garage-nudge]').hidden, true, 'the overview nudge goes away');
	assert.equal(document.querySelector('[data-rtpk-goto="garage"] .rtpk-account__stat-value').textContent, '1');
	assert.equal(window.riviantrackrGarage.defaultId, 'aaaa1111', 'the page-wide garage follows');
	assert.equal(events.length, 1);
	assert.equal(document.querySelector('[data-rtpk-msg]').textContent, 'Added to your garage.');
});

test('server field errors land on the right wheel row', async () => {
	const fetch = mockFetch({ 'POST account/garage/vehicles': { status: 400, body: { code: 'rtpk_invalid_vehicle', message: 'Please fix the highlighted fields.', data: { status: 400, fields: { year: 'Choose a model year.', 'wheels.1.size': 'Enter the size printed on the tire.' } } } } });
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals: globals() });
	const form = document.querySelector('[data-rtpk-garage-form]');
	choose(form.querySelector('[name="model"]'), 'r1t');
	document.querySelector('[data-rtpk-garage-add-wheel]').click();
	choose(wheelRows(document)[1].querySelector('[data-rtpk-wheel-pick]'), '0');
	submit(form);
	await flush();

	assert.equal(form.querySelector('[data-rtpk-field="year"] [data-rtpk-error]').textContent, 'Choose a model year.');
	const second = wheelRows(document)[1].querySelector('[data-rtpk-field="wheels.1.size"]');
	assert.ok(second, 'rows are indexed in order');
	assert.equal(second.querySelector('[data-rtpk-error]').textContent, 'Enter the size printed on the tire.');
	assert.equal(form.hidden, false);
});

test('wheel rows stop at four and removing the last one leaves a blank row', () => {
	const { document } = page(markup(), ['passkeys-account'], { globals: globals() });
	const add = document.querySelector('[data-rtpk-garage-add-wheel]');
	add.click();
	add.click();
	add.click();
	assert.equal(wheelRows(document).length, 4);
	assert.equal(add.hidden, true);

	wheelRows(document)[3].querySelector('[data-rtpk-garage-remove-wheel]').click();
	assert.equal(add.hidden, false);
	assert.equal(wheelRows(document)[2].querySelector('[data-rtpk-wheel-field="wheel"]').getAttribute('data-rtpk-field'), 'wheels.2.wheel');

	wheelRows(document).forEach((row) => row.querySelector('[data-rtpk-garage-remove-wheel]').click());
	assert.equal(wheelRows(document).length, 1);
});

test('a stored garage is listed; edit fills the form and saves to the vehicle', async () => {
	const other = vehicle({ id: 'bbbb2222', model: 'r1t', model_label: 'R1T', label: '2023 R1T', year: 2023, generation: 'gen1', generation_label: 'Gen 1', nickname: '', is_default: false, wheels: [{ wheel_id: 0, name: '', size: '35x12.50R17', label: '', third_party: false }] });
	const edited = { ...other, nickname: 'Trail' };
	const fetch = mockFetch({ 'POST account/garage/vehicles/bbbb2222': { message: 'Vehicle saved.', id: 'bbbb2222', garage: { vehicles: [vehicle(), edited], default_id: 'aaaa1111' } } });
	const { document } = page(markup(), ['passkeys-account'], { fetch, globals: globals([vehicle(), other]) });
	const form = document.querySelector('[data-rtpk-garage-form]');

	assert.equal(form.hidden, true);
	const items = document.querySelectorAll('[data-rtpk-vehicle]');
	assert.equal(items.length, 2);
	assert.equal(items[1].querySelector('.rtpk-account__item-name').textContent, '2023 R1T', 'no nickname: the year and model');
	assert.ok(items[1].querySelector('[data-rtpk-garage-make-default]'));
	assert.equal(items[0].querySelector('[data-rtpk-garage-make-default]'), null, 'the default has no "make default"');

	items[1].querySelector('[data-rtpk-garage-edit]').click();
	assert.equal(form.hidden, false);
	assert.equal(document.querySelector('[data-rtpk-garage-form-title]').textContent, 'Edit vehicle');
	assert.equal(form.querySelector('[name="model"]').value, 'r1t');
	assert.equal(form.querySelector('[name="year"]').value, '2023');
	assert.equal(form.querySelector('[name="generation"]').value, 'gen1');
	assert.equal(document.querySelector('[data-rtpk-garage-default]').checked, false);
	assert.equal(document.querySelector('[data-rtpk-garage-default]').disabled, false);
	const row = wheelRows(document)[0];
	assert.equal(row.querySelector('[data-rtpk-wheel-pick]').value, '0', 'a typed wheel reopens as another wheel');
	assert.equal(row.querySelector('[data-rtpk-wheel-size-input]').value, '35x12.50R17');

	form.querySelector('[name="nickname"]').value = 'Trail';
	submit(form);
	await flush();

	assert.equal(fetch.calls[0].path, 'account/garage/vehicles/bbbb2222');
	assert.equal(fetch.calls[0].body.make_default, false);
	assert.equal(document.querySelectorAll('[data-rtpk-vehicle]')[1].querySelector('.rtpk-account__item-name').textContent, 'Trail');
});

test('make default and remove go through REST and redraw', async () => {
	const other = vehicle({ id: 'bbbb2222', nickname: 'Truck', is_default: false });
	const fetch = mockFetch({
		'POST account/garage/default': (body) => ({ message: 'Default vehicle updated.', garage: { vehicles: [vehicle({ is_default: false }), { ...other, is_default: true }], default_id: body.id } }),
		'DELETE account/garage/vehicles/aaaa1111': { message: 'Removed from your garage.', garage: { vehicles: [{ ...other, is_default: true }], default_id: 'bbbb2222' } },
	});
	const { document, window } = page(markup(), ['passkeys-account'], { fetch, globals: globals([vehicle(), other]) });
	window.confirm = () => true;

	document.querySelector('[data-rtpk-vehicle="bbbb2222"] [data-rtpk-garage-make-default]').click();
	await flush();
	assert.deepEqual(fetch.calls[0].body, { id: 'bbbb2222' });
	assert.ok(document.querySelector('[data-rtpk-vehicle="bbbb2222"] .rtpk-badge'));

	document.querySelector('[data-rtpk-vehicle="aaaa1111"] [data-rtpk-garage-remove]').click();
	await flush();
	assert.equal(fetch.calls[1].method, 'DELETE');
	assert.equal(document.querySelectorAll('[data-rtpk-vehicle]').length, 1);
	assert.equal(window.riviantrackrGarage.defaultId, 'bbbb2222');
});

test('a full garage disables Add', () => {
	const five = ['a', 'b', 'c', 'd', 'e'].map((c, i) => vehicle({ id: c.repeat(8), is_default: i === 0 }));
	const { document } = page(markup(), ['passkeys-account'], { globals: globals(five) });
	assert.equal(document.querySelector('[data-rtpk-garage-add]').disabled, true);
	assert.equal(document.querySelector('[data-rtpk-garage-full]').hidden, false);
});
