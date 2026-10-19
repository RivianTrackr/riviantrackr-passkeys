/*!
 * RivianTrackr Passkeys — member account page.
 * Tab switching (URL hash), avatar upload, profile save, the garage
 * (vehicles and their wheels), password change or reset link, sign out
 * other devices, delete review / remove favourite, data export and account
 * deletion.
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

		setupGarage(root, msg);

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

	/* ---------------------------------------------------------- garage */

	/**
	 * The Garage tab: draws the vehicle list from the garage it was given,
	 * and adds, edits, removes and picks the default vehicle over REST. An
	 * empty garage opens the form straight away.
	 */
	function setupGarage(root, msg) {
		var box = root.querySelector('[data-rtpk-garage]');
		var gcfg = cfg.garage;
		if (!box || !gcfg) {
			return;
		}
		var list = box.querySelector('[data-rtpk-garage-list]');
		var empty = box.querySelector('[data-rtpk-garage-empty]');
		var addBtn = box.querySelector('[data-rtpk-garage-add]');
		var full = box.querySelector('[data-rtpk-garage-full]');
		var form = box.querySelector('[data-rtpk-garage-form]');
		var title = form.querySelector('[data-rtpk-garage-form-title]');
		var modelSel = form.querySelector('[name="model"]');
		var yearSel = form.querySelector('[name="year"]');
		var genSel = form.querySelector('[name="generation"]');
		var nickname = form.querySelector('[name="nickname"]');
		var rows = form.querySelector('[data-rtpk-garage-wheels]');
		var addWheel = form.querySelector('[data-rtpk-garage-add-wheel]');
		var makeDefault = form.querySelector('[data-rtpk-garage-default]');
		var inline = form.querySelector('[data-rtpk-form-msg]');
		var submit = form.querySelector('[type="submit"]');
		var models = gcfg.models || {};
		var wheels = gcfg.wheels || [];
		var maxWheels = gcfg.maxWheels || 4;
		var editing = '';

		var garage = { vehicles: gcfg.vehicles || [], defaultId: gcfg.defaultId || '' };

		/* ---- list */

		function render() {
			list.textContent = '';
			garage.vehicles.forEach(function (vehicle) {
				list.appendChild(item(vehicle));
			});
			var count = garage.vehicles.length;
			empty.hidden = count > 0 || !form.hidden;
			addBtn.hidden = !form.hidden;
			addBtn.disabled = count >= (gcfg.max || 5);
			full.hidden = !addBtn.disabled || !form.hidden;
			setStat(root, 'garage', count);
			var nudge = root.querySelector('[data-rtpk-garage-nudge]');
			if (nudge && count > 0) {
				nudge.hidden = true;
			}
		}

		function item(vehicle) {
			var li = el('li', 'rtpk-account__item rtpk-garage__item');
			li.setAttribute('data-rtpk-vehicle', vehicle.id);

			var thumb = el('span', 'rtpk-account__thumb rtpk-account__thumb--empty');
			thumb.setAttribute('aria-hidden', 'true');
			thumb.innerHTML = gcfg.icon || '';
			li.appendChild(thumb);

			var body = el('div', 'rtpk-account__item-body');
			var head = el('div', 'rtpk-account__item-head');
			head.appendChild(el('span', 'rtpk-account__item-name', vehicle.nickname || vehicle.label));
			if (vehicle.is_default) {
				head.appendChild(el('span', 'rtpk-badge', i18n.garageDefault || 'Default'));
			}
			body.appendChild(head);

			var meta = [vehicle.generation_label];
			if (vehicle.nickname) {
				meta.unshift(vehicle.label);
			}
			body.appendChild(el('p', 'rtpk-account__item-meta', meta.filter(Boolean).join(' · ')));

			if (vehicle.wheels && vehicle.wheels.length) {
				var chips = el('ul', 'rtpk-garage__chips');
				vehicle.wheels.forEach(function (w) {
					var chip = el('li', 'rtpk-garage__chip' + (w.third_party ? ' is-aftermarket' : ''));
					if (w.label) {
						chip.appendChild(el('strong', '', w.label));
					}
					chip.appendChild(document.createTextNode([w.name, w.size].filter(Boolean).join(' · ')));
					chips.appendChild(chip);
				});
				body.appendChild(chips);
			} else {
				body.appendChild(el('p', 'rtpk-account__item-meta', i18n.garageNoWheels || 'No wheels added yet.'));
			}
			li.appendChild(body);

			var actions = el('div', 'rtpk-account__item-actions');
			if (!vehicle.is_default) {
				actions.appendChild(button('rtpk-btn rtpk-btn--ghost rtpk-btn--sm', 'data-rtpk-garage-make-default', i18n.garageMakeDefault || 'Make default'));
			}
			actions.appendChild(button('rtpk-btn rtpk-btn--ghost rtpk-btn--sm', 'data-rtpk-garage-edit', i18n.garageEdit || 'Edit'));
			actions.appendChild(button('rtpk-btn rtpk-btn--danger rtpk-btn--sm', 'data-rtpk-garage-remove', i18n.garageRemove || 'Remove'));
			li.appendChild(actions);
			return li;
		}

		function apply(result) {
			if (result && result.garage) {
				garage = { vehicles: result.garage.vehicles || [], defaultId: result.garage.default_id || '' };
				if (window.riviantrackrGarage) {
					window.riviantrackrGarage.vehicles = garage.vehicles;
					window.riviantrackrGarage.defaultId = garage.defaultId;
				}
				try {
					document.dispatchEvent(new window.CustomEvent('riviantrackr:garage', { detail: result.garage }));
				} catch (e) {
					// Very old browsers: the page still updates.
				}
			}
			render();
		}

		list.addEventListener('click', function (event) {
			var btn = event.target.closest('button');
			var li = btn && btn.closest('[data-rtpk-vehicle]');
			if (!li) {
				return;
			}
			var id = li.getAttribute('data-rtpk-vehicle');
			if (btn.hasAttribute('data-rtpk-garage-edit')) {
				open(find(id));
			} else if (btn.hasAttribute('data-rtpk-garage-make-default')) {
				li.classList.add('is-busy');
				api('account/garage/default', { id: id })
					.then(function (result) {
						apply(result);
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						li.classList.remove('is-busy');
						showMsg(msg, describe(err), 'error');
					});
			} else if (btn.hasAttribute('data-rtpk-garage-remove')) {
				if (!window.confirm(i18n.garageConfirmRemove || 'Remove this vehicle from your garage?')) {
					return;
				}
				li.classList.add('is-busy');
				api('account/garage/vehicles/' + encodeURIComponent(id), {}, 'DELETE')
					.then(function (result) {
						if (editing === id) {
							close();
						}
						apply(result);
						showMsg(msg, result.message, 'success');
					})
					.catch(function (err) {
						li.classList.remove('is-busy');
						showMsg(msg, describe(err), 'error');
					});
			}
		});

		function find(id) {
			for (var i = 0; i < garage.vehicles.length; i++) {
				if (garage.vehicles[i].id === id) {
					return garage.vehicles[i];
				}
			}
			return null;
		}

		/* ---- form */

		function open(vehicle) {
			editing = vehicle ? vehicle.id : '';
			title.textContent = vehicle ? (i18n.garageEditTitle || 'Edit vehicle') : (i18n.garageAddTitle || 'Add a vehicle');
			clearErrors(form);
			setInline(inline, '', '');
			modelSel.value = vehicle ? vehicle.model : '';
			fillYears(vehicle ? vehicle.year : '');
			fillGenerations(vehicle ? vehicle.generation : '');
			nickname.value = vehicle ? vehicle.nickname : '';
			rows.textContent = '';
			(vehicle && vehicle.wheels.length ? vehicle.wheels : [null]).forEach(function (w) {
				rows.appendChild(wheelRow(w));
			});
			reindex();
			var first = garage.vehicles.length === 0 || (vehicle && vehicle.is_default);
			makeDefault.checked = !!first;
			makeDefault.disabled = !!first;
			form.hidden = false;
			render();
			if (vehicle) {
				modelSel.focus();
			}
		}

		function close() {
			editing = '';
			form.hidden = true;
			render();
		}

		addBtn.addEventListener('click', function () {
			open(null);
			modelSel.focus();
		});

		form.querySelector('[data-rtpk-garage-cancel]').addEventListener('click', function () {
			close();
			addBtn.focus();
		});

		modelSel.addEventListener('change', function () {
			fillYears(yearSel.value);
			fillGenerations('');
			toArray(rows.querySelectorAll('[data-rtpk-wheel-row]')).forEach(fillWheelOptions);
		});

		yearSel.addEventListener('change', function () {
			fillGenerations('');
		});

		function fillYears(keep) {
			var model = models[modelSel.value];
			yearSel.textContent = '';
			yearSel.appendChild(option('', i18n.garageChooseYear || 'Choose a year'));
			(model ? model.years : []).forEach(function (year) {
				yearSel.appendChild(option(String(year), String(year)));
			});
			yearSel.disabled = !model;
			yearSel.value = model && model.years.indexOf(Number(keep)) !== -1 ? String(keep) : '';
		}

		/** Generations of the model; with no choice to keep, the one the year falls in. */
		function fillGenerations(keep) {
			var model = models[modelSel.value];
			var year = Number(yearSel.value);
			var pick = keep || '';
			genSel.textContent = '';
			if (!year) {
				genSel.appendChild(option('', i18n.garageYearFirst || 'Pick the year first'));
			}
			(model ? model.generations : []).forEach(function (gen) {
				var years = gen.to ? gen.from + '–' + gen.to : String(i18n.garageYearsOpen || '%d+').replace('%d', gen.from);
				genSel.appendChild(option(gen.slug, gen.label + ' (' + years + ')'));
				if (!keep && year && year >= gen.from && (!gen.to || year <= gen.to)) {
					pick = gen.slug;
				}
			});
			genSel.disabled = !model || !year;
			if (pick) {
				genSel.value = pick;
			}
		}

		/* ---- wheel rows */

		function wheelRow(set) {
			var row = el('div', 'rtpk-garage__wheel');
			row.setAttribute('data-rtpk-wheel-row', '');

			var wheelField = field('wheel', i18n.garageWheel || 'Wheel', el('select', 'rtpk-account__input'));
			wheelField.input.setAttribute('data-rtpk-wheel-pick', '');
			var sizePick = el('select', 'rtpk-account__input');
			sizePick.setAttribute('data-rtpk-wheel-size-pick', '');
			var sizeField = field('size', i18n.garageTireSize || 'Tire size', sizePick);
			var sizeInput = el('input', 'rtpk-account__input');
			sizeInput.type = 'text';
			sizeInput.autocomplete = 'off';
			sizeInput.spellcheck = false;
			sizeInput.placeholder = i18n.garageTireSizeHint || 'e.g. 275/65R18';
			sizeInput.setAttribute('data-rtpk-wheel-size-input', '');
			sizeField.wrap.insertBefore(sizeInput, sizeField.error);
			var nameInput = el('input', 'rtpk-account__input');
			nameInput.type = 'text';
			nameInput.maxLength = 60;
			nameInput.placeholder = i18n.garageWheelNameHint || '';
			var nameField = field('name', i18n.garageWheelName || 'Wheel name (optional)', nameInput);
			var labelInput = el('input', 'rtpk-account__input');
			labelInput.type = 'text';
			labelInput.maxLength = 40;
			labelInput.placeholder = i18n.garageWheelLabelHint || '';
			labelInput.setAttribute('data-rtpk-wheel-label', '');
			var labelField = field('label', i18n.garageWheelLabel || 'Label (optional)', labelInput);

			var remove = button('rtpk-account__textbtn rtpk-garage__remove-wheel', 'data-rtpk-garage-remove-wheel', i18n.garageRemoveWheel || 'Remove this wheel set');

			[wheelField.wrap, sizeField.wrap, nameField.wrap, labelField.wrap, remove].forEach(function (node) {
				row.appendChild(node);
			});

			row._set = set || null;
			fillWheelOptions(row);
			if (set) {
				labelInput.value = set.label || '';
				if (!set.wheel_id) {
					sizeInput.value = set.size || '';
					nameInput.value = set.name || '';
				}
			}

			wheelField.input.addEventListener('change', function () {
				row._set = null;
				syncWheel(row);
			});
			remove.addEventListener('click', function () {
				row.remove();
				if (!rows.querySelector('[data-rtpk-wheel-row]')) {
					rows.appendChild(wheelRow(null));
				}
				reindex();
			});
			return row;
		}

		/** Wheels the guide lists for the chosen model (by label or group), factory first, then aftermarket, then "another wheel". */
		function fillWheelOptions(row) {
			var pick = row.querySelector('[data-rtpk-wheel-pick]');
			var model = models[modelSel.value];
			var label = model ? String(model.label).toUpperCase() : '';
			var group = model ? String(model.group || '').toUpperCase() : '';
			var keep = row._set ? String(row._set.wheel_id || (row._set.size ? '0' : '')) : pick.value;
			var fits = wheels.filter(function (w) {
				return !label || !w.vehicles.length || w.vehicles.indexOf(label) !== -1 || (!!group && w.vehicles.indexOf(group) !== -1);
			});
			pick.textContent = '';
			pick.appendChild(option('', i18n.garageChooseWheel || 'Choose a wheel'));
			[[false, i18n.garageRivianWheels || 'Rivian wheels'], [true, i18n.garageAftermarket || 'Aftermarket']].forEach(function (group) {
				var matches = fits.filter(function (w) { return !!w.third_party === group[0]; });
				if (!matches.length) {
					return;
				}
				var og = document.createElement('optgroup');
				og.label = group[1];
				matches.forEach(function (w) {
					og.appendChild(option(String(w.id), w.name));
				});
				pick.appendChild(og);
			});
			pick.appendChild(option('0', i18n.garageOtherWheel || 'Another wheel (type the tire size)'));
			pick.value = keep;
			if (pick.value !== keep) {
				pick.value = '';
			}
			syncWheel(row);
		}

		/** Show the size list for a guide wheel, or the free size and name fields for another wheel. */
		function syncWheel(row) {
			var pick = row.querySelector('[data-rtpk-wheel-pick]');
			var sizePick = row.querySelector('[data-rtpk-wheel-size-pick]');
			var sizeInput = row.querySelector('[data-rtpk-wheel-size-input]');
			var nameWrap = row.querySelector('[data-rtpk-wheel-field="name"]');
			var wheel = wheelById(pick.value);
			var other = pick.value === '0';
			sizePick.textContent = '';
			if (wheel) {
				wheel.sizes.forEach(function (size) {
					sizePick.appendChild(option(size, size));
				});
				if (row._set && wheel.sizes.indexOf(row._set.size) !== -1) {
					sizePick.value = row._set.size;
				}
			}
			sizePick.hidden = !wheel;
			sizePick.disabled = !wheel || wheel.sizes.length < 2;
			sizeInput.hidden = !other;
			nameWrap.hidden = !other;
			row.querySelector('[data-rtpk-wheel-field="size"]').hidden = !wheel && !other;
			labelFor(row);
		}

		/** Point each label at the control that is showing (the size field has two). */
		function labelFor(row) {
			toArray(row.querySelectorAll('[data-rtpk-wheel-field]')).forEach(function (wrap) {
				var controls = toArray(wrap.querySelectorAll('select, input'));
				var shown = controls.filter(function (c) { return !c.hidden; })[0] || controls[0];
				if (shown && shown.id) {
					wrap.querySelector('label').setAttribute('for', shown.id);
				}
			});
		}

		function wheelById(id) {
			var n = Number(id);
			for (var i = 0; i < wheels.length; i++) {
				if (wheels[i].id === n) {
					return wheels[i];
				}
			}
			return null;
		}

		addWheel.addEventListener('click', function () {
			rows.appendChild(wheelRow(null));
			reindex();
			var last = rows.querySelector('[data-rtpk-wheel-row]:last-child [data-rtpk-wheel-pick]');
			if (last) {
				last.focus();
			}
		});

		/** Field keys follow the row order so server errors (wheels.1.size) land on the right row. */
		function reindex() {
			var all = toArray(rows.querySelectorAll('[data-rtpk-wheel-row]'));
			all.forEach(function (row, index) {
				toArray(row.querySelectorAll('[data-rtpk-wheel-field]')).forEach(function (wrap) {
					var key = wrap.getAttribute('data-rtpk-wheel-field');
					wrap.setAttribute('data-rtpk-field', 'wheels.' + index + '.' + key);
					toArray(wrap.querySelectorAll('select, input')).forEach(function (input, n) {
						input.id = 'rtpk-garage-wheel-' + index + '-' + key + (n ? '-' + n : '');
					});
				});
				labelFor(row);
			});
			addWheel.hidden = all.length >= maxWheels;
		}

		function collect() {
			return toArray(rows.querySelectorAll('[data-rtpk-wheel-row]')).map(function (row) {
				var pick = row.querySelector('[data-rtpk-wheel-pick]').value;
				var other = pick === '0';
				return {
					wheel_id: other || pick === '' ? 0 : Number(pick),
					size: other ? row.querySelector('[data-rtpk-wheel-size-input]').value : (pick ? row.querySelector('[data-rtpk-wheel-size-pick]').value : ''),
					name: other ? row.querySelector('[data-rtpk-wheel-field="name"] input').value : '',
					label: row.querySelector('[data-rtpk-wheel-label]').value
				};
			});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var body = {
				model: modelSel.value,
				year: yearSel.value,
				generation: genSel.value,
				nickname: nickname.value,
				wheels: collect(),
				make_default: makeDefault.checked
			};
			clearErrors(form);
			setBusy(submit, true);
			setInline(inline, i18n.saving || 'Saving…', '');
			api(editing ? 'account/garage/vehicles/' + encodeURIComponent(editing) : 'account/garage/vehicles', body)
				.then(function (result) {
					showMsg(msg, result.message || '', 'success');
					close();
					apply(result);
					addBtn.focus();
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
				});
		});

		/* ---- helpers */

		function field(key, labelText, input) {
			var wrap = el('div', 'rtpk-account__field');
			wrap.setAttribute('data-rtpk-wheel-field', key);
			var label = el('label', 'rtpk-account__label', labelText);
			var error = el('p', 'rtpk-account__error');
			error.setAttribute('data-rtpk-error', '');
			error.hidden = true;
			wrap.appendChild(label);
			wrap.appendChild(input);
			wrap.appendChild(error);
			return { wrap: wrap, input: input, error: error };
		}

		if (garage.vehicles.length) {
			render();
		} else {
			open(null);
		}
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	function button(className, flag, text) {
		var btn = el('button', className, text);
		btn.type = 'button';
		btn.setAttribute(flag, '');
		return btn;
	}

	function option(value, text) {
		var opt = document.createElement('option');
		opt.value = value;
		opt.textContent = text;
		return opt;
	}

	function setStat(root, tab, value) {
		var stat = root.querySelector('[data-rtpk-goto="' + tab + '"] .rtpk-account__stat-value');
		if (stat) {
			stat.textContent = String(value);
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
				first = wrap.querySelector('input:not([hidden]), textarea, select:not([hidden])');
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
