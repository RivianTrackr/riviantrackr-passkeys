/*!
 * RivianTrackr Passkeys — add / rename / remove passkeys.
 */
(function (window, document) {
	'use strict';

	var core = window.rtpkCore;
	var cfg = window.rtpkManage || {};
	var i18n = cfg.i18n || {};

	if (!core) {
		return;
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-rtpk-manager]'), setup);
	}

	function setup(root) {
		var list = root.querySelector('[data-rtpk-list]');
		var msg = root.querySelector('[data-rtpk-msg]');
		var empty = root.querySelector('[data-rtpk-empty]');
		var addBtn = root.querySelector('[data-rtpk-add]');
		var isOwner = root.getAttribute('data-owner') === '1';
		var iconTemplate = root.querySelector('.rtpk-icon');

		if (addBtn) {
			if (!core.supported() || !core.secureContext()) {
				addBtn.disabled = true;
				showMsg(msg, core.secureContext() ? i18n.unsupported : i18n.security, 'error');
			} else {
				addBtn.addEventListener('click', function () {
					register(root, addBtn, list, msg, empty, isOwner, iconTemplate);
				});
			}
		}

		list.addEventListener('click', function (event) {
			var target = event.target.closest('button');
			if (!target || !list.contains(target)) {
				return;
			}
			var item = target.closest('[data-rtpk-item]');
			if (!item) {
				return;
			}
			if (target.hasAttribute('data-rtpk-delete')) {
				remove(item, msg, empty, list);
			} else if (target.hasAttribute('data-rtpk-rename')) {
				startRename(item, msg);
			}
		});

		updateEmpty(list, empty);
	}

	/* ------------------------------------------------------------ register */

	function register(root, btn, list, msg, empty, isOwner, iconTemplate) {
		setBusy(btn, true);
		showMsg(msg, i18n.working, 'info');

		var challengeId = null;

		api('register/options', {})
			.then(function (opts) {
				challengeId = opts.challengeId;
				return window.navigator.credentials.create({ publicKey: core.prepareCreateOptions(opts.publicKey) });
			})
			.then(function (credential) {
				if (!credential) {
					throw new Error(i18n.genericError);
				}
				return api('register/verify', {
					challengeId: challengeId,
					credential: core.serializeAttestation(credential),
					name: core.deviceLabel()
				});
			})
			.then(function (result) {
				list.appendChild(renderItem(result.credential, isOwner, iconTemplate));
				updateEmpty(list, empty);
				showMsg(msg, i18n.added, 'success');
				if (root.hasAttribute('data-rtpk-setup')) {
					finishSetup();
				}
			})
			.catch(function (err) {
				showMsg(msg, core.describeError(err, i18n), 'error');
			})
			.then(function () {
				setBusy(btn, false);
			});
	}

	/* -------------------------------------------------------------- remove */

	function remove(item, msg, empty, list) {
		if (!window.confirm(i18n.confirmDelete)) {
			return;
		}
		var id = item.getAttribute('data-id');
		item.classList.add('is-busy');

		api('credentials/' + encodeURIComponent(id), {}, 'DELETE')
			.then(function () {
				item.remove();
				updateEmpty(list, empty);
				showMsg(msg, i18n.removed, 'success');
			})
			.catch(function (err) {
				item.classList.remove('is-busy');
				showMsg(msg, core.describeError(err, i18n), 'error');
			});
	}

	/* -------------------------------------------------------------- rename */

	function startRename(item, msg) {
		var nameRow = item.querySelector('.rtpk-item__name-row');
		var nameEl = item.querySelector('[data-rtpk-name]');
		if (!nameRow || !nameEl || item.querySelector('.rtpk-item__edit')) {
			return;
		}

		var editor = el('form', 'rtpk-item__edit');
		var input = el('input', 'rtpk-item__edit-input');
		input.type = 'text';
		input.maxLength = 60;
		input.value = nameEl.textContent;
		input.setAttribute('aria-label', i18n.nameLabel || 'Passkey name');

		var save = el('button', 'rtpk-btn rtpk-btn--primary rtpk-btn--sm', i18n.save || 'Save');
		save.type = 'submit';
		var cancel = el('button', 'rtpk-btn rtpk-btn--ghost rtpk-btn--sm', i18n.cancel || 'Cancel');
		cancel.type = 'button';

		editor.appendChild(input);
		editor.appendChild(save);
		editor.appendChild(cancel);

		nameRow.hidden = true;
		nameRow.insertAdjacentElement('afterend', editor);
		input.focus();
		input.select();

		function close() {
			editor.remove();
			nameRow.hidden = false;
		}

		cancel.addEventListener('click', close);
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				close();
			}
		});

		editor.addEventListener('submit', function (event) {
			event.preventDefault();
			var name = input.value.trim();
			if (!name) {
				input.focus();
				return;
			}
			save.disabled = true;
			api('credentials/' + encodeURIComponent(item.getAttribute('data-id')), { name: name })
				.then(function (result) {
					nameEl.textContent = result.credential.name;
					close();
					showMsg(msg, i18n.renamed, 'success');
				})
				.catch(function (err) {
					save.disabled = false;
					showMsg(msg, core.describeError(err, i18n), 'error');
				});
		});
	}

	/* -------------------------------------------------------------- render */

	/**
	 * Mirrors ProfileManager::item_markup() in PHP.
	 */
	function renderItem(c, isOwner, iconTemplate) {
		var li = el('li', 'rtpk-item');
		li.setAttribute('data-rtpk-item', '');
		li.setAttribute('data-id', String(c.id));

		var icon = el('div', 'rtpk-item__icon');
		if (iconTemplate) {
			icon.appendChild(iconTemplate.cloneNode(true));
		}

		var body = el('div', 'rtpk-item__body');
		var nameRow = el('div', 'rtpk-item__name-row');
		var name = el('span', 'rtpk-item__name', c.name);
		name.setAttribute('data-rtpk-name', '');
		nameRow.appendChild(name);
		if (c.provider && c.provider !== c.name) {
			nameRow.appendChild(el('span', 'rtpk-badge', c.provider));
		}
		if (c.backedUp) {
			nameRow.appendChild(el('span', 'rtpk-badge rtpk-badge--muted', i18n.synced || 'Synced'));
		}

		var metaParts = [];
		if (c.createdAt) {
			metaParts.push(fmt(i18n.added_on || 'Added %s', c.createdAt));
		}
		metaParts.push(c.lastUsedAt ? fmt(i18n.last_used || 'Last used %s', c.lastUsedAt) : (i18n.never_used || 'Never used'));
		var meta = el('div', 'rtpk-item__meta', metaParts.join(' · '));
		meta.setAttribute('data-rtpk-meta', '');

		body.appendChild(nameRow);
		body.appendChild(meta);

		var actions = el('div', 'rtpk-item__actions');
		if (isOwner) {
			var rename = el('button', 'rtpk-btn rtpk-btn--ghost', i18n.rename || 'Rename');
			rename.type = 'button';
			rename.setAttribute('data-rtpk-rename', '');
			actions.appendChild(rename);
		}
		var del = el('button', 'rtpk-btn rtpk-btn--danger', i18n.remove || 'Remove');
		del.type = 'button';
		del.setAttribute('data-rtpk-delete', '');
		actions.appendChild(del);

		li.appendChild(icon);
		li.appendChild(body);
		li.appendChild(actions);
		return li;
	}

	/**
	 * On the "Set up a passkey" page: reveal the done panel and hide "skip".
	 */
	function finishSetup() {
		var done = document.querySelector('[data-rtpk-done]');
		var skip = document.querySelector('[data-rtpk-skip]');
		if (done) {
			done.hidden = false;
			done.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			var cta = done.querySelector('a');
			if (cta) {
				cta.focus({ preventScroll: true });
			}
		}
		if (skip) {
			skip.hidden = true;
		}
	}

	/* ------------------------------------------------------------- helpers */

	function api(path, body, method) {
		return core.request(cfg.restUrl, path, body, cfg.nonce, method || 'POST');
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	function fmt(template, value) {
		return String(template).replace('%s', value);
	}

	function updateEmpty(list, empty) {
		if (empty) {
			empty.hidden = list.querySelector('[data-rtpk-item]') !== null;
		}
	}

	function setBusy(btn, busy) {
		btn.disabled = !!busy;
		btn.classList.toggle('is-busy', !!busy);
		btn.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function showMsg(node, text, type) {
		if (!node) {
			return;
		}
		node.textContent = text || '';
		node.className = 'rtpk-manager__msg' + (type ? ' is-' + type : '');
		node.hidden = !text;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
