/*!
 * RivianTrackr Passkeys — admin: invite members without a passkey.
 */
(function (window, document) {
	'use strict';

	var cfg = window.rtpkInvites || {};
	var i18n = cfg.i18n || {};
	var root = document.querySelector('[data-rtpk-invites]');
	if (!root || !cfg.ajaxUrl) {
		return;
	}

	var rolesBox = root.querySelector('[data-rtpk-roles]');
	var modeBox = root.querySelector('[data-rtpk-mode]');
	var staleInput = root.querySelector('[data-rtpk-stale]');
	var countMsg = root.querySelector('[data-rtpk-count]');
	var sendBtn = root.querySelector('[data-rtpk-send]');
	var testBtn = root.querySelector('[data-rtpk-test]');
	var testMsg = root.querySelector('[data-rtpk-test-msg]');
	var subject = root.querySelector('[data-rtpk-subject]');
	var body = root.querySelector('[data-rtpk-body]');
	var progressRow = root.querySelector('[data-rtpk-progress-row]');
	var progress = root.querySelector('[data-rtpk-progress]');
	var progressBar = root.querySelector('[data-rtpk-progress-bar]');
	var progressText = root.querySelector('[data-rtpk-progress-text]');
	var log = root.querySelector('[data-rtpk-log]');
	var themeSelect = root.querySelector('[data-rtpk-theme]');
	var logoId = root.querySelector('[data-rtpk-logo-id]');
	var logoImg = root.querySelector('[data-rtpk-logo-img]');
	var logoEmpty = root.querySelector('[data-rtpk-logo-empty]');
	var logoPreview = root.querySelector('[data-rtpk-logo-preview]');
	var logoChoose = root.querySelector('[data-rtpk-logo-choose]');
	var logoRemove = root.querySelector('[data-rtpk-logo-remove]');
	var previewBtn = root.querySelector('[data-rtpk-preview]');
	var previewPanel = root.querySelector('[data-rtpk-preview-panel]');
	var previewFrame = root.querySelector('[data-rtpk-preview-frame]');
	var defaultLogo = logoImg ? logoImg.getAttribute('src') : '';

	var countTimer = null;
	var pendingCount = 0;

	function post(action, fields) {
		var form = new FormData();
		form.append('action', action);
		form.append('nonce', cfg.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			var value = fields[key];
			if (Array.isArray(value)) {
				value.forEach(function (v) { form.append(key + '[]', v); });
			} else {
				form.append(key, value);
			}
		});
		return window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || i18n.genericError);
				}
				return json.data || {};
			});
	}

	function filters() {
		var roles = [];
		rolesBox.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
			roles.push(box.value);
		});
		var modeInput = modeBox.querySelector('input[name="mode"]:checked');
		return {
			roles: roles,
			mode: modeInput ? modeInput.value : 'never',
			stale_days: staleInput ? staleInput.value : 14
		};
	}

	function fmt(template, a, b) {
		return String(template).replace('%1$d', a).replace('%2$d', b).replace('%d', a);
	}

	function refreshCount() {
		window.clearTimeout(countTimer);
		countTimer = window.setTimeout(function () {
			countMsg.textContent = i18n.counting;
			sendBtn.disabled = true;
			pendingCount = 0;
			post('rtpk_invite_count', filters()).then(function (data) {
				pendingCount = data.count || 0;
				if (pendingCount > 0) {
					var sample = data.sample && data.sample.length ? ' (' + data.sample.join(', ') + (pendingCount > data.sample.length ? ', …' : '') + ')' : '';
					countMsg.textContent = fmt(i18n.willEmail, pendingCount) + sample;
					sendBtn.disabled = false;
				} else {
					countMsg.textContent = i18n.nobody;
				}
			}).catch(function (err) {
				countMsg.textContent = err.message || i18n.genericError;
			});
		}, 250);
	}

	function setProgress(sent, failed, total) {
		var doneCount = sent + failed;
		var pct = total ? Math.round((doneCount / total) * 100) : 0;
		progressBar.style.width = pct + '%';
		progress.setAttribute('aria-valuenow', String(pct));
		progressText.textContent = fmt(i18n.progress, doneCount, total);
	}

	function appendLog(entries) {
		(entries || []).forEach(function (entry) {
			var li = document.createElement('li');
			li.className = entry.ok ? 'is-ok' : 'is-fail';
			li.textContent = entry.name + ' — ' + entry.note;
			log.appendChild(li);
		});
		log.scrollTop = log.scrollHeight;
	}

	function runJob(job) {
		return post('rtpk_invite_send', { job: job }).then(function (data) {
			setProgress(data.sent, data.failed, data.total);
			appendLog(data.log);
			if (data.done) {
				progressText.textContent = fmt(i18n.done, data.sent, data.failed);
				return data;
			}
			return runJob(job);
		});
	}

	sendBtn.addEventListener('click', function () {
		if (!pendingCount || !window.confirm(fmt(i18n.confirm, pendingCount))) {
			return;
		}
		sendBtn.disabled = true;
		progressRow.hidden = false;
		log.textContent = '';
		setProgress(0, 0, pendingCount);
		progressText.textContent = i18n.sending;

		post('rtpk_invite_send', filters())
			.then(function (data) { return runJob(data.job); })
			.then(function () { refreshCount(); })
			.catch(function (err) {
				progressText.textContent = err.message || i18n.genericError;
				sendBtn.disabled = false;
			});
	});

	function draft() {
		return {
			subject: subject.value,
			body: body.value,
			theme: themeSelect ? themeSelect.value : '',
			logo_id: logoId ? logoId.value : ''
		};
	}

	function showLogo(url) {
		if (!logoImg) {
			return;
		}
		if (url) {
			logoImg.src = url;
			logoImg.hidden = false;
			logoEmpty.hidden = true;
		} else {
			logoImg.removeAttribute('src');
			logoImg.hidden = true;
			logoEmpty.hidden = false;
		}
	}

	if (logoChoose && window.wp && window.wp.media) {
		var frame = null;
		logoChoose.addEventListener('click', function () {
			if (!frame) {
				frame = window.wp.media({
					title: i18n.chooseLogo,
					library: { type: 'image' },
					multiple: false,
					button: { text: i18n.useLogo }
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					logoId.value = attachment.id;
					showLogo(url);
					logoRemove.hidden = false;
				});
			}
			frame.open();
		});
		logoRemove.addEventListener('click', function () {
			logoId.value = '0';
			showLogo(defaultLogo);
			logoRemove.hidden = true;
		});
	}

	if (themeSelect && logoPreview) {
		themeSelect.addEventListener('change', function () {
			logoPreview.classList.toggle('is-light', themeSelect.value === 'light');
		});
	}

	previewBtn.addEventListener('click', function () {
		previewBtn.disabled = true;
		testMsg.textContent = i18n.previewing;
		testMsg.className = 'rtpk-inline-msg';
		post('rtpk_invite_preview', draft())
			.then(function (data) {
				previewFrame.srcdoc = data.html;
				previewPanel.hidden = false;
				testMsg.textContent = '';
				previewPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			})
			.catch(function (err) {
				testMsg.textContent = err.message || i18n.genericError;
				testMsg.className = 'rtpk-inline-msg is-error';
			})
			.then(function () { previewBtn.disabled = false; });
	});

	testBtn.addEventListener('click', function () {
		testBtn.disabled = true;
		testMsg.textContent = i18n.sending;
		testMsg.className = 'rtpk-inline-msg';
		post('rtpk_invite_test', draft())
			.then(function () {
				testMsg.textContent = i18n.testSent;
				testMsg.className = 'rtpk-inline-msg is-success';
			})
			.catch(function (err) {
				testMsg.textContent = err.message || i18n.genericError;
				testMsg.className = 'rtpk-inline-msg is-error';
			})
			.then(function () { testBtn.disabled = false; });
	});

	rolesBox.addEventListener('change', refreshCount);
	modeBox.addEventListener('change', refreshCount);
	if (staleInput) {
		staleInput.addEventListener('input', refreshCount);
	}
	refreshCount();
})(window, document);
