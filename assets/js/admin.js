/**
 * GEO Engine — admin interactions.
 *
 * Vanilla JS, no jQuery dependency. Every request goes through admin-ajax.php
 * with a nonce; the API key is never stored in the DOM.
 */
(function () {
	'use strict';

	var cfg = window.GeoEngine || {};
	var strings = cfg.strings || {};
	var nonces = cfg.nonces || {};
	var presets = cfg.presets || {};

	function $(selector, root) {
		return (root || document).querySelector(selector);
	}

	function $all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	/**
	 * Strip a UTF-8 BOM and surrounding whitespace.
	 *
	 * Some hosts prepend a BOM to every response — one PHP file saved with a
	 * BOM is enough — and JSON.parse() rejects it, which previously surfaced as
	 * a misleading "network error" even though the server answered 200.
	 *
	 * @param {string} text Raw response body.
	 * @return {string} Cleaned text.
	 */
	function cleanBody(text) {
		return String(text === null || text === undefined ? '' : text)
			.replace(/^\uFEFF+/, '')
			.trim();
	}

	/**
	 * Turn an HTML error page into a short, human readable hint.
	 *
	 * @param {string} body Response body.
	 * @return {string} Snippet.
	 */
	function snippet(body) {
		return String(body || '')
			.replace(/<script[\s\S]*?<\/script>/gi, ' ')
			.replace(/<style[\s\S]*?<\/style>/gi, ' ')
			.replace(/<[^>]*>/g, ' ')
			.replace(/\s+/g, ' ')
			.trim()
			.slice(0, 120);
	}

	/**
	 * Parse an admin-ajax response and fail loudly.
	 *
	 * @param {string} text      Raw response body.
	 * @param {number} httpStatus HTTP status code.
	 * @return {Object} Parsed payload.
	 */
	function parseResponse(text, httpStatus) {
		var clean = cleanBody(text);

		try {
			return JSON.parse(clean);
		} catch (error) {
			// A 502/504 page or a PHP fatal shows up here as HTML, not JSON.
			if (window.console && window.console.error) {
				window.console.error(
					'[GEO Engine] 响应不是 JSON（HTTP ' + httpStatus + '），前 300 字符：',
					clean.slice(0, 300)
				);
			}

			var failure = new Error('HTTP ' + httpStatus);

			failure.httpStatus = httpStatus;
			failure.parse = true;
			failure.snippet = snippet(clean);

			throw failure;
		}
	}

	function post(action, data, nonce) {
		var body = new URLSearchParams();
		body.append('action', action);

		if (nonce) {
			body.append('nonce', nonce);
		}

		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];

			if (value === undefined || value === null) {
				return;
			}

			body.append(key, value);
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.text().then(function (text) {
				if (!response.ok) {
					var failure = new Error('HTTP ' + response.status);

					failure.httpStatus = response.status;
					failure.snippet = snippet(text);

					if (window.console && window.console.error) {
						window.console.error(
							'[GEO Engine] ' + action + ' 返回 HTTP ' + response.status + '：',
							String(text || '').slice(0, 300)
						);
					}

					throw failure;
				}

				return parseResponse(text, response.status);
			});
		});
	}

	/**
	 * Human readable reason for a failed request.
	 *
	 * @param {Error} error Error.
	 * @return {string} Text.
	 */
	function describeError(error) {
		if (!error) {
			return strings.networkError || '连接中断';
		}

		var status = error.httpStatus;

		if (error.message === 'timeout') {
			return '请求超时';
		}

		if (status === 504) {
			return '服务器处理超时（HTTP 504）';
		}

		if (status === 502 || status === 503) {
			return '服务器暂时不可用（HTTP ' + status + '）';
		}

		if (status === 413) {
			return '请求过大（HTTP 413）';
		}

		if (status === 403 || status === 429) {
			return '请求被拒绝（HTTP ' + status + '），可能被安全插件或防火墙拦截';
		}

		if (error.parse) {
			return status && status !== 200
				? '服务器返回了网页而不是数据（HTTP ' + status + '）'
				: '响应不是合法 JSON（HTTP 200，可能被其他插件或 BOM 污染）';
		}

		if (status) {
			return '服务器返回错误（HTTP ' + status + '）';
		}

		return '连接中断';
	}

	function setValue(selector, value) {
		var field = $(selector);

		if (field && typeof value === 'string') {
			field.value = value;
		}
	}

	/* ---------------------------------------------------------------- LLM tab */

	function toggleCustomAuth() {
		var select = $('#geo-auth-scheme');
		var block = $('.geo-custom-auth');

		if (!select || !block) {
			return;
		}

		block.style.display = select.value === 'custom' ? '' : 'none';
	}

	function initProviderPresets() {
		var select = $('#geo-provider');

		if (!select) {
			return;
		}

		select.addEventListener('change', function () {
			var preset = presets[select.value];

			if (!preset) {
				return;
			}

			setValue('#geo-api-base', preset.api_base || '');
			setValue('#geo-model', preset.model || '');
			setValue('#geo-protocol', preset.protocol || 'openai-chat');
			setValue('#geo-auth-scheme', preset.auth_scheme || 'bearer');
			setValue('#geo-extra-headers', preset.extra_headers || '');

			var note = $('#geo-provider-note');

			if (note) {
				note.textContent = preset.note || '';
			}

			toggleCustomAuth();

			// Local providers will be blocked unless the switch is on: help the user.
			var base = preset.api_base || '';

			if (base.indexOf('http://localhost') === 0 || base.indexOf('http://127.0.0.1') === 0) {
				var localSwitch = document.querySelector('input[name="allow_local_endpoint"]');

				if (localSwitch) {
					localSwitch.checked = true;
				}
			}

			if (select.value === 'custom') {
				var customSwitch = document.querySelector('input[name="allow_custom_endpoint"]');

				if (customSwitch) {
					customSwitch.checked = true;
				}
			}
		});

		var authSelect = $('#geo-auth-scheme');

		if (authSelect) {
			authSelect.addEventListener('change', toggleCustomAuth);
		}

		toggleCustomAuth();
	}

	function formValues(form) {
		var data = {};
		var elements = form.elements;

		Array.prototype.forEach.call(elements, function (field) {
			if (!field.name) {
				return;
			}

			if (field.type === 'checkbox') {
				data[field.name] = field.checked ? '1' : '0';
				return;
			}

			if (field.type === 'file') {
				return;
			}

			if (field.type === 'radio' && !field.checked) {
				return;
			}

			data[field.name] = field.value;
		});

		return data;
	}

	function initTestConnection() {
		var button = $('#geo-test-connection');
		var output = $('#geo-test-result');

		if (!button || !output) {
			return;
		}

		button.addEventListener('click', function () {
			var form = $('#geo-llm-form');

			if (!form) {
				return;
			}

			var original = button.textContent;

			button.disabled = true;
			button.textContent = strings.testing || '…';
			output.className = 'geo-test-result is-pending';
			output.textContent = strings.testing || '…';

			post('geo_engine_test', formValues(form), nonces.test)
				.then(function (response) {
					var data = response.data || {};

					if (response.success) {
						output.className = 'geo-test-result is-ok';
						output.textContent =
							'连接成功 · ' + (data.provider || '') + ' · ' + (data.model || '') +
							' · ' + (data.latency || 0) + ' ms';
					} else {
						output.className = 'geo-test-result is-error';
						output.textContent = data.message || strings.networkError;
					}
				})
				.catch(function () {
					output.className = 'geo-test-result is-error';
					output.textContent = strings.networkError;
				})
				.finally(function () {
					button.disabled = false;
					button.textContent = original;
				});
		});
	}

	/* ------------------------------------------------------------- Generate */

	function generateFor(button, container) {
		var postId = button.getAttribute('data-post-id');
		var force = button.getAttribute('data-force') === '1';

		if (force && !window.confirm(strings.confirmRegen || 'Regenerate?')) {
			return;
		}

		var original = button.textContent;

		button.disabled = true;
		button.textContent = strings.generating || '…';

		if (container) {
			container.className = 'geo-metabox-result is-pending';
			container.textContent = strings.generating || '…';
		}

		post('geo_engine_generate', { post_id: postId, force: force ? 1 : 0 }, nonces.generate)
			.then(function (response) {
				var data = response.data || {};
				var message = data.message || '';

				if (container) {
					container.className = 'geo-metabox-result ' + (response.success ? 'is-ok' : 'is-error');
					container.textContent = message;
				} else {
					window.alert(message);
				}

				if (response.success) {
					window.setTimeout(function () {
						window.location.reload();
					}, 1200);
				}
			})
			.catch(function () {
				if (container) {
					container.className = 'geo-metabox-result is-error';
					container.textContent = strings.networkError;
				}
			})
			.finally(function () {
				button.disabled = false;
				button.textContent = original;
			});
	}

	function initGenerateButtons() {
		$all('.geo-generate').forEach(function (button) {
			button.addEventListener('click', function () {
				generateFor(button, button.closest('.geo-metabox') ? $('.geo-metabox-result', button.closest('.geo-metabox')) : null);
			});
		});

		$all('.geo-generate-row').forEach(function (link) {
			link.addEventListener('click', function (event) {
				event.preventDefault();
				generateFor(link, null);
			});
		});
	}

	/* ----------------------------------------------------------------- Bulk */

	function initBulk() {
		var root = $('.geo-bulk');

		if (!root) {
			return;
		}

		var startBtn = $('#geo-bulk-start');
		var continueBtn = $('#geo-bulk-continue');
		var stopBtn = $('#geo-bulk-stop');
		var retryBtn = $('#geo-bulk-retry');
		var resetBtn = $('#geo-bulk-reset');
		var forceBox = $('#geo-bulk-force');

		var MAX_RECOVERY = 8;
		var PROBE_INTERVAL = 3000;
		var PROBE_DEADLINE = 180000;

		var bar = $('.geo-progress-bar', root);
		var track = $('.geo-progress', root);
		var message = $('[data-bulk-message]', root);
		var logBox = $('[data-bulk-log]', root);
		var liveBox = $('[data-bulk-live]', root);
		var liveText = $('[data-bulk-live-text]', root);
		var pctEl = $('[data-progress-percent]', root);
		var countEl = $('[data-progress-count]', root);
		var remainEl = $('[data-progress-remain]', root);
		var speedEl = $('[data-progress-speed]', root);
		var etaEl = $('[data-progress-eta]', root);

		var delay = parseInt(root.getAttribute('data-delay'), 10);

		if (isNaN(delay) || delay < 0) {
			delay = 0;
		}

		var state = null;
		var mode = 'batch';
		var running = false;
		var busy = false;
		var pollBusy = false;
		var reqStart = 0;
		var rate = null;
		var pollTimer = null;
		var tickTimer = null;
		var waitTimer = null;
		var seqTimer = null;
		var failures = 0;
		var probeStart = 0;
		var lastError = '';
		var forceSynced = false;
		var lastProcessed = 0;
		var logSeq = 0;

		function forceValue() {
			return forceBox && forceBox.checked ? 1 : 0;
		}

		/**
		 * Progress counters depend on the run type: a retry pass walks its own
		 * queue, so the bar must not follow the batch cursor (which is frozen
		 * at 114/114 while retrying).
		 *
		 * @param {Object} next State payload.
		 * @return {number} Completed count.
		 */
		function doneOf(next) {
			if (!next) {
				return 0;
			}

			return next.mode === 'retry' ? next.retry_done || 0 : next.processed || 0;
		}

		function totalOf(next) {
			if (!next) {
				return 0;
			}

			return next.mode === 'retry' ? next.retry_total || 0 : next.total || 0;
		}

		function fill(template, values) {
			var list = values || [];
			var index = 0;

			return String(template || '').replace(/%s/g, function () {
				var value = list[index];
				index += 1;

				return value === undefined ? '' : value;
			});
		}

		function secondsText(seconds) {
			var total = Math.max(0, Math.round(seconds));

			if (total < 60) {
				return total + ' 秒';
			}

			var minutes = Math.floor(total / 60);
			var rest = total % 60;

			if (minutes < 60) {
				return rest ? minutes + ' 分 ' + rest + ' 秒' : minutes + ' 分钟';
			}

			return Math.floor(minutes / 60) + ' 小时 ' + (minutes % 60) + ' 分';
		}

		function withTimeout(promise, ms) {
			return new Promise(function (resolve, reject) {
				var timer = window.setTimeout(function () {
					reject(new Error('timeout'));
				}, ms);

				promise.then(function (value) {
					window.clearTimeout(timer);
					resolve(value);
				}, function (error) {
					window.clearTimeout(timer);
					reject(error);
				});
			});
		}

		function setLive(text, visible) {
			if (!liveBox) {
				return;
			}

			liveBox.classList.remove('is-warn');

			if (!visible) {
				liveBox.hidden = true;

				if (liveText) {
					liveText.textContent = '';
				}

				return;
			}

			liveBox.hidden = false;

			if (liveText) {
				liveText.textContent = text || '';
			}
		}

		/**
		 * Same box, warning colours — used for failures so the state is obvious.
		 *
		 * @param {string} text Message.
		 */
		function warnLive(text) {
			setLive(text, true);

			if (liveBox) {
				liveBox.classList.add('is-warn');
			}
		}

		function renderState(next) {
			if (!next) {
				return;
			}

			state = next;

			Object.keys(next).forEach(function (key) {
				var cell = $('[data-stat="' + key + '"]', root);

				if (cell && typeof next[key] === 'number') {
					cell.textContent = next[key];
				}
			});

			var total = totalOf(next);
			var processed = doneOf(next);
			var retrying = next.mode === 'retry';
			var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
			var left = Math.max(0, total - processed);

			mode = retrying ? 'retry' : 'batch';

			if (bar) {
				bar.style.width = percent + '%';
				bar.classList.toggle('is-running', next.status === 'running');
			}

			if (track) {
				track.setAttribute('aria-valuenow', String(percent));
			}

			if (pctEl) {
				pctEl.textContent = percent + '%';
			}

			if (countEl) {
				countEl.textContent = retrying
					? fill(strings.retryCount || '已重试 %s / %s 篇', [processed, total])
					: fill(strings.progressCount || '%s / %s 篇', [processed, total]);
			}

			if (remainEl) {
				remainEl.textContent = fill(strings.progressRemain || '剩余 %s 篇', [left]);
			}

			if (message) {
				message.textContent = next.message || '';
			}

			if (startBtn) {
				startBtn.disabled = next.status === 'running';
			}

			if (continueBtn) {
				continueBtn.disabled = next.status !== 'paused';
			}

			if (stopBtn) {
				stopBtn.disabled = next.status !== 'running';
			}

			if (retryBtn) {
				var pending = (next.failed_ids || []).length;

				// Retry is a queue of its own: never offer it while a batch or
				// another retry is in flight, otherwise two runs fight over the
				// same articles.
				retryBtn.disabled = next.status === 'running' || pending === 0;
				retryBtn.textContent = fill(strings.retryButton || '重试失败项（%s）', [pending]);
			}

			// Sync the checkbox once from the stored run, then let the user own
			// it: re-rendering must not undo a tick made mid-run.
			if (forceBox && !forceSynced) {
				forceBox.checked = !!next.force;
				forceSynced = true;
			}
		}

		function appendLog(entries) {
			if (!logBox || !entries || !entries.length) {
				return;
			}

			entries.forEach(function (entry) {
				var row = document.createElement('div');
				var status = entry.status || '';

				row.className = 'geo-bulk-log-row is-' + status;
				row.textContent = '[' + status + '] ' + (entry.title || '#' + entry.post_id) + ' — ' + (entry.message || '');
				logBox.insertBefore(row, logBox.firstChild);
			});

			while (logBox.childElementCount > 100) {
				logBox.removeChild(logBox.lastChild);
			}
		}

		function sample(next) {
			var now = Date.now();
			var done = doneOf(next);

			if (!rate) {
				rate = { processed: done, at: now };
				return;
			}

			var delta = done - rate.processed;
			var elapsed = (now - rate.at) / 1000;

			if (delta <= 0 || elapsed <= 0) {
				return;
			}

			var per = elapsed / delta;
			var left = Math.max(0, totalOf(next) - done);

			if (speedEl) {
				speedEl.textContent = fill(strings.speed || '约 %s 秒/篇', [per.toFixed(1)]);
			}

			if (etaEl) {
				etaEl.textContent = left > 0 ? fill(strings.eta || '预计剩余 %s', [secondsText(per * left)]) : '';
			}

			rate = { processed: done, at: now };
		}

		function stopTicker() {
			if (tickTimer) {
				window.clearInterval(tickTimer);
				tickTimer = null;
			}
		}

		function startTicker() {
			stopTicker();
			tickTimer = window.setInterval(function () {
				if (!reqStart) {
					return;
				}

				var passed = Math.round((Date.now() - reqStart) / 1000);

				setLive(fill(strings.processing || '正在处理…已用时 %s 秒', [passed]), true);
			}, 1000);
		}

		function stopPolling() {
			if (pollTimer) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}

			pollBusy = false;
		}

		function startPolling() {
			stopPolling();

			pollTimer = window.setInterval(function () {
				if (pollBusy || !running) {
					return;
				}

				pollBusy = true;

				withTimeout(post('geo_engine_bulk_status', {}, nonces.bulk), 4000)
					.then(function (response) {
						pollBusy = false;

						if (!response || !response.success || !response.data || !response.data.state) {
							return;
						}

						var live = response.data.state;
						var passed = reqStart ? Math.round((Date.now() - reqStart) / 1000) : 0;

						if (live.current_title) {
							setLive(fill(strings.processingTitle || '正在处理《%s》…已用时 %s 秒', [live.current_title, passed]), true);
						}
					})
					.catch(function () {
						pollBusy = false;
					});
			}, 3000);
		}

		function stopTimers() {
			stopTicker();
			stopPolling();

			if (waitTimer) {
				window.clearTimeout(waitTimer);
				waitTimer = null;
			}

			if (seqTimer) {
				window.clearInterval(seqTimer);
				seqTimer = null;
			}
		}

		function countdown(seconds) {
			if (seqTimer) {
				window.clearInterval(seqTimer);
				seqTimer = null;
			}

			var left = seconds;
			var waitingText = mode === 'retry'
				? strings.waitingRetry || '重试中，下一批将在 %s 秒后开始'
				: strings.waiting || '下一批将在 %s 秒后开始';

			if (left <= 0) {
				loop();
				return;
			}

			setLive(fill(waitingText, [left]), true);

			seqTimer = window.setInterval(function () {
				left -= 1;

				if (left <= 0) {
					window.clearInterval(seqTimer);
					seqTimer = null;

					if (running) {
						loop();
					}

					return;
				}

				setLive(fill(waitingText, [left]), true);
			}, 1000);
		}

		function scheduleProbe(ms) {
			if (waitTimer) {
				window.clearTimeout(waitTimer);
			}

			waitTimer = window.setTimeout(probe, ms);
		}

		/**
		 * Ask the server what really happened after a broken response.
		 *
		 * A 504 does not mean the work stopped: PHP keeps running, finishes the
		 * slice and saves the cursor. So instead of giving up, the browser reads
		 * the state back until the lock is released and then asks for the next
		 * slice — which is what makes "press start once, run to the end" work
		 * even when the gateway cuts a request short.
		 */
		function probe() {
			if (!running) {
				return;
			}

			if (Date.now() - probeStart > PROBE_DEADLINE) {
				running = false;
				warnLive(fill(strings.stalled || '服务端长时间没有响应（%s）。进度已保留，点「继续」可接着跑。', [lastError || '']));

				return;
			}

			post('geo_engine_bulk_status', {}, nonces.bulk)
				.then(function (response) {
					if (!response || !response.success || !response.data || !response.data.state) {
						scheduleProbe(PROBE_INTERVAL * 2);

						return;
					}

					var data = response.data;
					var live = data.state;

					renderState(live);

					// A detached slice reports its per-article rows through the
					// state array (the response body is long gone). seq is
					// monotonic, so only the unseen rows are appended.
					if (live.retry_log && live.retry_log.length) {
						var unseen = live.retry_log.filter(function (row) {
							return (row.seq || 0) > logSeq;
						});

						if (unseen.length) {
							logSeq = unseen[unseen.length - 1].seq || logSeq;
							appendLog(unseen);
						}
					}

					// Progress made since the last look: whatever broke, the run
					// is healthy — clear the failure streak so a slow gateway can
					// never abort a batch that is still moving forward.
					if (doneOf(live) > lastProcessed) {
						failures = 0;
						lastProcessed = doneOf(live);
					}

					if (live.status === 'running') {
						// busy = the lock is still held; age = how long ago the
						// state was written. A live worker refreshes both, so a
						// stale pair means the process died and the queue is free.
						var working = data.busy || (live.current_id > 0 && data.age < 20);

						if (working) {
							// The deadline measures "no sign of work", not
							// "long slice": a 60 second article keeps resetting
							// it while the queue is visibly moving.
							probeStart = Date.now();

							setLive(
								fill(strings.serverBusy || '服务端仍在处理《%s》，等待它收尾…', [live.current_title || '…']),
								true
							);
							scheduleProbe(PROBE_INTERVAL);

							return;
						}

						// Server is idle again: the cursor is saved, carry on.
						// `failures` is deliberately left alone — it is cleared by
						// a successful batch or by actual progress above, so a
						// stream of failures still ends after MAX_RECOVERY tries.
						busy = false;
						loop();

						return;
					}

					if (live.status === 'paused' || live.status === 'error') {
						running = false;
						setLive(live.message || strings.batchStopped || '', true);

						return;
					}

					running = false;
					setLive('', false);
				})
				.catch(function () {
					scheduleProbe(PROBE_INTERVAL * 2);
				});
		}

		/**
		 * Recover from a failed batch request without dropping the run.
		 *
		 * @param {Error}  error Failure.
		 * @param {boolean} soft  True when the server is simply busy.
		 */
		function recover(error, soft) {
			if (!running) {
				return;
			}

			stopTimers();

			lastError = describeError(error);

			if (!soft) {
				failures += 1;
			}

			if (failures > MAX_RECOVERY) {
				running = false;
				warnLive(fill(strings.gaveUp || '连续 %s 次连接失败（%s）。进度已保留，点「继续」可接着跑。', [MAX_RECOVERY, lastError]));

				return;
			}

			probeStart = Date.now();
			setLive(fill(strings.recovering || '%s，正在自动接续…', [lastError]), true);
			scheduleProbe(soft ? 2000 : PROBE_INTERVAL);
		}

		function loop() {
			if (!running || busy) {
				return;
			}

			busy = true;
			reqStart = Date.now();

			startTicker();
			startPolling();

			post(mode === 'retry' ? 'geo_engine_bulk_retry_batch' : 'geo_engine_bulk_batch', {}, nonces.bulk)
				.then(function (response) {
					stopTicker();
					stopPolling();
					busy = false;

					if (!response || !response.success) {
						var data = (response && response.data) || {};

						// Another request still holds the queue: wait for it and
						// read the state back, this is not a failure.
						if (data.busy) {
							lastError = strings.busy || '服务端仍在处理上一批';
							probeStart = Date.now();
							setLive(lastError, true);
							scheduleProbe(2000);

							return;
						}

						running = false;

						if (data.state) {
							renderState(data.state);
						}

						warnLive(data.message || strings.networkError);

						return;
					}

					// The retry endpoint answers *before* it starts working: the
					// slice is already running in the background, the gateway is
					// off the clock, and progress arrives through the status
					// endpoint from here on.
					if (response.data && response.data.accepted) {
						failures = 0;
						renderState(response.data.state);
						lastProcessed = doneOf(response.data.state);
						probeStart = Date.now();
						setLive(strings.detached || '服务端已在后台处理，等待结果…', true);
						scheduleProbe(1500);

						return;
					}

					failures = 0;

					renderState(response.data.state);
					lastProcessed = doneOf(response.data.state);
					appendLog(response.data.log);
					sample(response.data.state);

					if (!running) {
						// Stopped while the request was in flight: keep the stop
						// message and do not queue another batch.
						return;
					}

					if (response.data.state && response.data.state.status === 'running') {
						countdown(typeof response.data.delay === 'number' ? response.data.delay : delay);

						return;
					}

					running = false;
					setLive('', false);

					if (etaEl) {
						etaEl.textContent = '';
					}
				})
				.catch(function (error) {
					busy = false;
					recover(error, false);
				});
		}

		if (startBtn) {
			startBtn.addEventListener('click', function () {
				if (!window.confirm(strings.confirmBatch || '将处理全部可处理内容，确定开始吗？')) {
					return;
				}

				startBtn.disabled = true;
				rate = null;
				failures = 0;

				post('geo_engine_bulk_start', { force: forceValue() }, nonces.bulk)
					.then(function (response) {
						if (!response || !response.success) {
							startBtn.disabled = false;
							setLive((response && response.data && response.data.message) || strings.networkError, true);

							return;
						}

						if (logBox) {
							logBox.innerHTML = '';
						}

						if (speedEl) {
							speedEl.textContent = '';
						}

						if (etaEl) {
							etaEl.textContent = '';
						}

						renderState(response.data.state);
						running = true;
						loop();
					})
					.catch(function () {
						startBtn.disabled = false;
					});
			});
		}

		if (continueBtn) {
			continueBtn.addEventListener('click', function () {
				continueBtn.disabled = true;

				post('geo_engine_bulk_resume', { force: forceValue() }, nonces.bulk)
					.then(function (response) {
						if (!response || !response.success) {
							continueBtn.disabled = false;

							return;
						}

						rate = null;
						busy = false;
						failures = 0;
						renderState(response.data.state);
						running = true;
						loop();
					})
					.catch(function () {
						continueBtn.disabled = false;
					});
			});
		}

		if (stopBtn) {
			stopBtn.addEventListener('click', function () {
				running = false;
				busy = false;
				failures = 0;
				stopTimers();
				setLive(strings.batchStopped || '已停止，进度已保留。', true);

				post('geo_engine_bulk_stop', {}, nonces.bulk)
					.then(function (response) {
						if (response && response.success) {
							renderState(response.data.state);
						}
					})
					.catch(function () {
						/* the pending batch will report the real state */
					});
			});
		}

		if (retryBtn) {
			retryBtn.addEventListener('click', function () {
				var pending = state && state.failed_ids ? state.failed_ids.length : 0;

				if (!pending) {
					return;
				}

				if (!window.confirm(fill(strings.confirmRetry || '将重试 %s 篇失败项，期间会逐篇重新生成，确定吗？', [pending]))) {
					return;
				}

				retryBtn.disabled = true;
				rate = null;
				failures = 0;
				lastProcessed = 0;
				logSeq = 0;

				// A retry pass starts a fresh queue server side; clear the old
				// rows so the panel shows this run only.
				if (logBox) {
					logBox.innerHTML = '';
				}

				post('geo_engine_bulk_retry', {}, nonces.bulk)
					.then(function (response) {
						if (!response || !response.success) {
							retryBtn.disabled = false;

							if (response && response.data && response.data.state) {
								renderState(response.data.state);
							}

							setLive((response && response.data && response.data.message) || strings.networkError, true);

							return;
						}

						busy = false;
						renderState(response.data.state);
						running = true;
						loop();
					})
					.catch(function (error) {
						retryBtn.disabled = false;
						setLive(describeError(error), true);
					});
			});
		}

		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				if (!window.confirm(strings.confirmResetBatch || '重置会清空进度统计（不会删除已生成的 FAQ），确定吗？')) {
					return;
				}

				post('geo_engine_bulk_reset', {}, nonces.bulk).then(function (response) {
					if (!response || !response.success) {
						return;
					}

					running = false;
					busy = false;
					rate = null;
					stopTimers();
					renderState(response.data.state);
					setLive('', false);

					if (logBox) {
						logBox.innerHTML = '';
					}

					if (speedEl) {
						speedEl.textContent = '';
					}

					if (etaEl) {
						etaEl.textContent = '';
					}
				});
			});
		}

		var initial = root.getAttribute('data-state');
		var age = parseInt(root.getAttribute('data-age'), 10);

		if (isNaN(age)) {
			age = 0;
		}

		if (initial) {
			try {
				var parsed = JSON.parse(initial);

				renderState(parsed);
				lastProcessed = doneOf(parsed);

				// Rows already rendered before the reload must not be repeated
				// by the first probe: start after the newest seq.
				if (parsed.retry_log && parsed.retry_log.length) {
					logSeq = parsed.retry_log[parsed.retry_log.length - 1].seq || 0;
				}

				if (parsed.status === 'running' && age < 90) {
					// The tab was reloaded mid-run. Pick the queue back up instead
					// of making the user press "continue".
					running = true;
					setLive(strings.resumed || '检测到进行中的任务，正在接续…', true);
					waitTimer = window.setTimeout(loop, 1200);
				} else if (parsed.status === 'running') {
					parsed.status = 'paused';
					parsed.message = strings.interrupted || '上次任务已中断，点「继续」从断点接着跑。';
					renderState(parsed);
					setLive('', false);
				}
			} catch (error) {
				/* ignore malformed state */
			}
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		initProviderPresets();
		initTestConnection();
		initGenerateButtons();
		initBulk();
	});
})();
