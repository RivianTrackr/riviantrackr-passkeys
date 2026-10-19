/*!
 * RivianTrackr Passkeys — member account page.
 * Tab switching (URL hash), avatar upload, profile save, password change
 * or reset link, sign out other devices, delete review / remove favourite,
 * data export and account deletion.
 */
(function (window, document) {
	'use strict';

	var core = window.rtpkCore;
	var cfg = window.rtpkAccount || {};
	var i18n = cfg.i18n || {};

	if (!core) {
		return;
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-rtpk-account]'), setup);
	}

	function setup(root) {
		var msg = root.querySelector('[data-rtpk-msg]');
		var tabs = toArray(root.querySelectorAll('[data-rtpk-tab]'));
		var panels = toArray(root.querySelectorAll('[data-rtpk-panel]'));

		/* ---------------------------------------------------------- tabs */

		function ids() {
			return tabs.map(function (t) { return t.getAttribute('data-rtpk-tab'); });
		}

		function activate(id, focus) {
			if (ids().indexOf(id) === -1) {
				return false;
			}
			tabs.forEach(function (tab) {
				var on = tab.getAttribute('data-rtpk-tab') === id;
				tab.classList.toggle('is-active', on);
				tab.setAttribute('aria-selected', on ? 'true' : 'false');
				tab.setAttribute('tabindex', on ? '0' : '-1');
				if (on && focus) {
					tab.focus({ preventScroll: true });
				}
			});
			panels.forEach(function (panel) {
				panel.hidden = panel.getAttribute('data-rtpk-panel') !== id;
			});
			showMsg(msg, '');
			return true;
		}

		function fromHash() {
			var id = (window.location.hash || '').replace(/^#/, '');
			if (!id || !activate(id, false)) {
				activate(tabs.length ? tabs[0].getAttribute('data-rtpk-tab') : '', false);
			}
		}

		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function (event) {
				event.preventDefault();
				var id = tab.getAttribute('data-rtpk-tab');
				if (window.history && window.history.replaceState) {
					window.history.replaceState(null, '', '#' + id);
				} else {
					window.location.hash = id;
				}
				activate(id, false);
			});
			tab.addEventListener('keydown', function (event) {
				var next = null;
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					next = tabs[(index + 1) % tabs.length];
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					next = tabs[(index - 1 + tabs.length) % tabs.length];
				} else if (event.key === 'Home') {
					next = tabs[0];
				} else if (event.key === 'End') {
					next = tabs[tabs.length - 1];
				}
				if (next) {
					event.preventDefault();
					next.click();
					next.focus();
				}
			});
		});

		root.addEventListener('click', function (event) {
			var link = event.target.closest('[data-rtpk-goto]');
			if (!link || !root.contains(link)) {
				return;
			}
			event.preventDefault();
			var id = link.getAttribute('data-rtpk-goto');
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#' + id);
			}
			activate(id, true);
			root.scrollIntoView({ block: 'start', behavior: 'smooth' });
		});

		window.addEventListener('hashchange', fromHash);
		fromHash();

		/* ------------------------------------------------------- profile */

		var profileForm = root.querySelector('[data-rtpk-profile-form]');
		if (profileForm) {
			profileForm.addEventListener('submit', function (event) {
				event.preventDefault();
				submitForm(profileForm, 'account/profile', function (result) {
					var nameEl = root.querySelector('.rtpk-account__name');
					if (nameEl && result.profile && result.profile.displayName) {
						nameEl.textContent = result.profile.displayName;
					}
					var emailInput = profileForm.querySelector('[name="email"]');
					if (emailInput && result.profile && result.profile.email) {
						emailInput.value = result.profile.email;
					}
					setPendingEmail(root, result.pendingEmail || '');
				});
			});
		}

		var cancelEmail = root.querySelector('[data-rtpk-cancel-email]');
		if (cancelEmail) {
			cancelEmail.addEventListener('click', function () {
				cancelEmail.disabled = true;
				api('account/email/cancel', {})
					.then(function (result) {
						setPendingEmail(root, '');
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						showMsg(msg, describe(err), 'error');
					})
					.then(function () {
						cancelEmail.disabled = false;
					});
			});
		}

		/* ------------------------------------------------------ password */

		var passwordForm = root.querySelector('[data-rtpk-password-form]');
		if (passwordForm) {
			passwordForm.addEventListener('submit', function (event) {
				event.preventDefault();
				submitForm(passwordForm, 'account/password', function () {
					passwordForm.reset();
				});
			});
		}

		/* -------------------------------------------------------- avatar */

		var avatarCard = root.querySelector('[data-rtpk-avatar]');
		if (avatarCard) {
			var avatarInput = avatarCard.querySelector('[data-rtpk-avatar-input]');
			var avatarRemove = avatarCard.querySelector('[data-rtpk-avatar-remove]');
			var avatarMsg = avatarCard.querySelector('[data-rtpk-avatar-msg]');
			var avatarPick = avatarCard.querySelector('[data-rtpk-avatar-pick]');
			var limits = cfg.avatar || {};

			var applyAvatar = function (result) {
				if (result.url) {
					toArray(root.querySelectorAll('img.rtpk-account__avatar')).forEach(function (img) {
						img.src = result.url;
						img.removeAttribute('srcset');
					});
				}
				avatarCard.setAttribute('data-custom', result.custom ? '1' : '0');
				if (avatarRemove) {
					avatarRemove.hidden = !result.custom;
				}
				var label = avatarPick ? avatarPick.querySelector('span') : null;
				if (label) {
					label.textContent = result.custom ? (i18n.changePhoto || 'Change photo') : (i18n.uploadPhoto || 'Upload a photo');
				}
			};

			if (avatarInput) {
				avatarInput.addEventListener('change', function () {
					var file = avatarInput.files && avatarInput.files[0];
					if (!file) {
						return;
					}
					if (limits.maxBytes && file.size > limits.maxBytes) {
						setInline(avatarMsg, i18n.avatarTooLarge || 'That photo is too large.', 'error');
						avatarInput.value = '';
						return;
					}
					if (limits.types && limits.types.length && file.type && limits.types.indexOf(file.type) === -1) {
						setInline(avatarMsg, i18n.avatarType || 'Use a JPG, PNG, GIF or WebP image.', 'error');
						avatarInput.value = '';
						return;
					}
					var body = new window.FormData();
					body.append('avatar', file, file.name);
					avatarCard.classList.add('is-busy');
					setInline(avatarMsg, i18n.uploading || 'Uploading…', '');
					upload('account/avatar', body)
						.then(function (result) {
							applyAvatar(result);
							setInline(avatarMsg, result.message || '', 'success');
						})
						.catch(function (err) {
							setInline(avatarMsg, describe(err), 'error');
						})
						.then(function () {
							avatarCard.classList.remove('is-busy');
							avatarInput.value = '';
						});
				});
			}

			if (avatarRemove) {
				avatarRemove.addEventListener('click', function () {
					if (!window.confirm(i18n.confirmRemovePhoto || 'Remove your photo?')) {
						return;
					}
					setBusy(avatarRemove, true);
					api('account/avatar', {}, 'DELETE')
						.then(function (result) {
							applyAvatar(result);
							setInline(avatarMsg, result.message || '', 'success');
						})
						.catch(function (err) {
							setInline(avatarMsg, describe(err), 'error');
						})
						.then(function () {
							setBusy(avatarRemove, false);
						});
				});
			}
		}

		/* ------------------------------------------- password reset link */

		toArray(root.querySelectorAll('[data-rtpk-password-link]')).forEach(function (btn) {
			btn.addEventListener('click', function () {
				setBusy(btn, true);
				api('account/password/link', {})
					.then(function (result) {
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						showMsg(msg, describe(err), 'error');
					})
					.then(function () {
						setBusy(btn, false);
					});
			});
		});

		/* ------------------------------------------------------ sessions */

		var signOutOthers = root.querySelector('[data-rtpk-signout-others]');
		if (signOutOthers) {
			signOutOthers.addEventListener('click', function () {
				if (!window.confirm(i18n.confirmSignOutOthers || 'Sign out of every other device?')) {
					return;
				}
				signOutOthers.disabled = true;
				api('account/sessions/others', {})
					.then(function (result) {
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						showMsg(msg, describe(err), 'error');
					})
					.then(function () {
						signOutOthers.disabled = false;
					});
			});
		}

		/* ------------------------------------------------- your data */

		var exportBtn = root.querySelector('[data-rtpk-export-data]');
		if (exportBtn) {
			exportBtn.addEventListener('click', function () {
				setBusy(exportBtn, true);
				api('account/data/export', {})
					.then(function (result) {
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						showMsg(msg, describe(err), 'error');
					})
					.then(function () {
						setBusy(exportBtn, false);
					});
			});
		}

		var deleteForm = root.querySelector('[data-rtpk-delete-form]');
		if (deleteForm) {
			var deleteInput = deleteForm.querySelector('input[name="confirm"]');
			var deleteBtn = deleteForm.querySelector('[type="submit"]');
			var username = deleteForm.getAttribute('data-username') || '';
			if (deleteInput && deleteBtn) {
				deleteInput.addEventListener('input', function () {
					deleteBtn.disabled = !confirmMatches(deleteInput.value, username);
				});
			}
			deleteForm.addEventListener('submit', function (event) {
				event.preventDefault();
				if (!confirmMatches(deleteInput ? deleteInput.value : '', username)) {
					return;
				}
				if (!window.confirm(i18n.confirmDeleteAccount || 'Delete your account? This cannot be undone.')) {
					return;
				}
				submitForm(deleteForm, 'account', function (result) {
					if (result.deleted && result.redirect) {
						window.location.assign(result.redirect);
						return true;
					}
					deleteForm.reset();
					return false; // keep the button disabled until the username is typed again
				}, 'DELETE');
			});
		}

		/* ---------------------------------------------- reviews & favs */

		root.addEventListener('click', function (event) {
			var btn = event.target.closest('button');
			if (!btn || !root.contains(btn)) {
				return;
			}
			if (btn.hasAttribute('data-rtpk-delete-review')) {
				removeItem(btn, 'account/reviews/', i18n.confirmDeleteReview, i18n.noReviews);
			} else if (btn.hasAttribute('data-rtpk-remove-favorite')) {
				removeItem(btn, 'account/favorites/', i18n.confirmRemoveFav, i18n.noFavorites);
			}
		});

		function removeItem(btn, path, confirmText, emptyText) {
			var item = btn.closest('[data-rtpk-item]');
			if (!item || !window.confirm(confirmText || 'Are you sure?')) {
				return;
			}
			var list = item.parentNode;
			var tireId = item.getAttribute('data-tire-id');
			item.classList.add('is-busy');
			api(path + encodeURIComponent(tireId), {}, 'DELETE')
				.then(function (result) {
					item.remove();
					var empty = list.parentNode.querySelector('[data-rtpk-empty]');
					if (empty) {
						empty.hidden = list.querySelector('[data-rtpk-item]') !== null;
						if (emptyText && !empty.hidden) {
							empty.textContent = emptyText;
						}
					}
					updateStat(root, list.getAttribute('data-rtpk-list'), -1);
					showMsg(msg, result.message, 'success');
				})
				.catch(function (err) {
					item.classList.remove('is-busy');
					showMsg(msg, describe(err), 'error');
				});
		}

		/* --------------------------------------------------------- forms */

		/**
		 * Post a form's fields as JSON. onSuccess may return false to leave
		 * the submit button disabled afterwards.
		 */
		function submitForm(form, path, onSuccess, method) {
			var submit = form.querySelector('[type="submit"]');
			var inline = form.querySelector('[data-rtpk-form-msg]');
			var body = {};
			var stayDisabled = false;
			toArray(form.querySelectorAll('input[name], textarea[name]')).forEach(function (field) {
				body[field.name] = field.value;
			});
			clearErrors(form);
			setBusy(submit, true);
			setInline(inline, i18n.saving || 'Saving…', '');

			api(path, body, method)
				.then(function (result) {
					setInline(inline, result.message || '', 'success');
					showMsg(msg, '');
					if (onSuccess) {
						stayDisabled = onSuccess(result) === false;
					}
				})
				.catch(function (err) {
					setInline(inline, '', '');
					if (err && err.data && err.data.fields) {
						showErrors(form, err.data.fields);
					}
					showMsg(msg, describe(err), 'error');
				})
				.then(function () {
					setBusy(submit, false);
					if (stayDisabled && submit) {
						submit.disabled = true;
					}
				});
		}
	}

	/* ------------------------------------------------------------- helpers */

	function confirmMatches(typed, username) {
		return !!username && String(typed || '').trim().toLowerCase() === String(username).toLowerCase();
	}

	function setPendingEmail(root, email) {
		var box = root.querySelector('[data-rtpk-pending-email]');
		var text = root.querySelector('[data-rtpk-pending-text]');
		if (!box) {
			return;
		}
		if (!email) {
			box.hidden = true;
			return;
		}
		if (text) {
			var parts = String(i18n.pendingEmail || 'A confirmation link was sent to %s. Your email changes once you click it.').split('%s');
			var strong = document.createElement('strong');
			strong.textContent = email;
			text.textContent = '';
			text.appendChild(document.createTextNode(parts[0] || ''));
			text.appendChild(strong);
			text.appendChild(document.createTextNode(parts[1] || ''));
		}
		box.hidden = false;
	}

	function updateStat(root, tab, delta) {
		var stat = root.querySelector('[data-rtpk-goto="' + tab + '"] .rtpk-account__stat-value');
		if (!stat) {
			return;
		}
		var n = parseInt(stat.textContent.replace(/[^\d]/g, ''), 10);
		if (!isNaN(n)) {
			stat.textContent = String(Math.max(0, n + delta));
		}
	}

	function clearErrors(form) {
		toArray(form.querySelectorAll('[data-rtpk-field]')).forEach(function (field) {
			field.classList.remove('has-error');
			var err = field.querySelector('[data-rtpk-error]');
			if (err) {
				err.textContent = '';
				err.hidden = true;
			}
		});
	}

	function showErrors(form, fields) {
		var first = null;
		Object.keys(fields).forEach(function (key) {
			var wrap = form.querySelector('[data-rtpk-field="' + key + '"]');
			if (!wrap) {
				return;
			}
			wrap.classList.add('has-error');
			var err = wrap.querySelector('[data-rtpk-error]');
			if (err) {
				err.textContent = fields[key];
				err.hidden = false;
			}
			if (!first) {
				first = wrap.querySelector('input, textarea');
			}
		});
		if (first) {
			first.focus();
		}
	}

	function api(path, body, method) {
		return core.request(cfg.restUrl, path, body, cfg.nonce, method || 'POST');
	}

	/**
	 * Multipart POST (file uploads); same error shape as core.request().
	 */
	function upload(path, formData) {
		var url = String(cfg.restUrl).replace(/\/+$/, '') + '/' + String(path).replace(/^\/+/, '');
		return window.fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: formData
		}).then(function (res) {
			return res.text().then(function (text) {
				var data = null;
				try {
					data = text ? JSON.parse(text) : null;
				} catch (e) {
					data = null;
				}
				if (!res.ok) {
					var err = new Error((data && data.message) || ('Request failed (' + res.status + ')'));
					err.code = data && data.code;
					err.status = res.status;
					err.data = (data && data.data) || null;
					throw err;
				}
				return data || {};
			});
		});
	}

	function describe(err) {
		if (err && err.status === 400 && err.data && err.data.fields) {
			return err.message;
		}
		return core.describeError(err, i18n);
	}

	function setBusy(btn, busy) {
		if (!btn) {
			return;
		}
		btn.disabled = !!busy;
		btn.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function setInline(node, text, type) {
		if (!node) {
			return;
		}
		node.textContent = text || '';
		node.className = 'rtpk-account__inline-msg' + (type ? ' is-' + type : '');
	}

	function showMsg(node, text, type) {
		if (!node) {
			return;
		}
		node.textContent = text || '';
		node.className = 'rtpk-account__msg' + (type ? ' is-' + type : '');
		node.hidden = !text;
	}

	function toArray(list) {
		return Array.prototype.slice.call(list);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
