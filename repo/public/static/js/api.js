/* Studio API helper — wraps fetch() with the spec's envelope, idempotency,
 * draft persistence, and weak-network degraded-mode handling. */
(function (global) {
  'use strict';

  var DEGRADED_BANNER_ID = 'studio-degraded-banner';
  var consecutiveFailures = 0;
  var degraded = false;

  function ensureBanner() {
    var el = document.getElementById(DEGRADED_BANNER_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = DEGRADED_BANNER_ID;
      el.className = 'degraded-banner';
      el.innerHTML = '⚠ Network is degraded. <a href="#" id="studio-reconnect" style="color:#fff;text-decoration:underline">Reconnect now</a>';
      document.body.appendChild(el);
      el.querySelector('#studio-reconnect').addEventListener('click', function (e) {
        e.preventDefault();
        consecutiveFailures = 0;
        setDegraded(false);
        location.reload();
      });
    }
    return el;
  }
  function setDegraded(on) {
    degraded = on;
    var el = ensureBanner();
    el.classList.toggle('on', on);
  }

  function getCsrfToken() {
    var m = document.cookie.match(/(?:^|; )studio_csrf=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  function newIdemKey() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, function (c) {
      return (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16);
    });
  }

  function request(method, url, body, opts) {
    opts = opts || {};
    var headers = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    var csrf = getCsrfToken();
    if (csrf) headers['X-CSRF-Token'] = csrf;
    if (method !== 'GET' && method !== 'HEAD') {
      headers['Idempotency-Key'] = opts.idempotencyKey || newIdemKey();
    }
    var init = {
      method: method,
      headers: headers,
      credentials: 'same-origin'
    };
    if (body) init.body = body instanceof FormData ? body : JSON.stringify(body);

    return fetch(url, init).then(function (res) {
      consecutiveFailures = 0;
      if (degraded) setDegraded(false);
      return res.json().then(function (json) {
        if (res.status === 401) {
          // Session expired — bounce to login
          if (!/login\.html/.test(location.pathname)) location.href = '/pages/login.html';
        }
        return json;
      });
    }).catch(function (err) {
      consecutiveFailures += 1;
      if (consecutiveFailures >= 2) setDegraded(true);
      throw err;
    });
  }

  // Browser-side draft persistence (spec §12.5/§22).
  //
  // Two-tier: localStorage as the primary (always available even offline) and
  // best-effort server sync via PUT /api/v1/drafts/:token so a refresh on
  // a different tab/device picks up the latest payload. Server failures are
  // swallowed — the local copy is canonical.
  var DRAFT_LOCAL_PREFIX = 'draft:';
  var DRAFT_SERVER_PATH = '/api/v1/drafts/';
  var DRAFT_SAVE_DEBOUNCE_MS = 750;

  var Drafts = {
    save: function (token, payload) {
      try {
        localStorage.setItem(DRAFT_LOCAL_PREFIX + token, JSON.stringify({
          payload: payload, ts: Date.now()
        }));
      } catch (_) {}
      // best-effort server sync
      try { request('PUT', DRAFT_SERVER_PATH + encodeURIComponent(token), payload).catch(function () {}); } catch (_) {}
    },
    load: function (token) {
      // Local first
      var local = null;
      try {
        var raw = localStorage.getItem(DRAFT_LOCAL_PREFIX + token);
        if (raw) {
          var rec = JSON.parse(raw);
          if (Date.now() - rec.ts > 7 * 86400 * 1000) {
            localStorage.removeItem(DRAFT_LOCAL_PREFIX + token);
          } else {
            local = rec.payload;
          }
        }
      } catch (_) {}
      return local;
    },
    loadRemote: function (token) {
      return request('GET', DRAFT_SERVER_PATH + encodeURIComponent(token))
        .then(function (res) { return res && res.code === 0 ? res.data : null; })
        .catch(function () { return null; });
    },
    clear: function (token) {
      try { localStorage.removeItem(DRAFT_LOCAL_PREFIX + token); } catch (_) {}
      try { request('DELETE', DRAFT_SERVER_PATH + encodeURIComponent(token)).catch(function () {}); } catch (_) {}
    },

    /**
     * Bind a draft token to a `<form>` element:
     *   - restores values on load (local first, then remote async)
     *   - autosaves debounced on input/change
     *   - returns helpers so the page can call `clear()` after a
     *     successful submit, and `idemKey()` to reuse the same key for
     *     replay-safe submits across reconnects.
     */
    bindForm: function (formEl, token, opts) {
      opts = opts || {};
      var debounceTimer = null;
      var idemKey = (function () {
        var k = 'idem:' + token;
        var existing = null;
        try { existing = localStorage.getItem(k); } catch (_) {}
        if (!existing) {
          existing = newIdemKey();
          try { localStorage.setItem(k, existing); } catch (_) {}
        }
        return existing;
      })();

      function snapshot() {
        var data = {};
        Array.prototype.forEach.call(formEl.elements, function (el) {
          if (!el.name) return;
          if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked) data[el.name] = el.value;
          } else if (el.type !== 'submit' && el.type !== 'button') {
            data[el.name] = el.value;
          }
        });
        return data;
      }
      function applyValues(values) {
        if (!values || typeof values !== 'object') return;
        Array.prototype.forEach.call(formEl.elements, function (el) {
          if (!el.name || !(el.name in values)) return;
          if (el.type === 'checkbox' || el.type === 'radio') {
            el.checked = (el.value === values[el.name]);
          } else {
            el.value = values[el.name];
          }
        });
      }

      // Restore: local first, then attempt remote
      var local = Drafts.load(token);
      if (local) applyValues(local);
      Drafts.loadRemote(token).then(function (remote) {
        if (remote && !local) applyValues(remote);
      });

      // Auto-save
      function scheduleSave() {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          Drafts.save(token, snapshot());
        }, DRAFT_SAVE_DEBOUNCE_MS);
      }
      formEl.addEventListener('input',  scheduleSave);
      formEl.addEventListener('change', scheduleSave);

      return {
        snapshot: snapshot,
        clear: function () {
          if (debounceTimer) clearTimeout(debounceTimer);
          Drafts.clear(token);
          try { localStorage.removeItem('idem:' + token); } catch (_) {}
        },
        idemKey: function () { return idemKey; },
        // Reset the idempotency key after a successful POST so the next
        // submission of the same form gets a fresh key (prevents the
        // server cache from short-circuiting the second attempt).
        rotateIdemKey: function () {
          idemKey = newIdemKey();
          try { localStorage.setItem('idem:' + token, idemKey); } catch (_) {}
        },
      };
    }
  };

  // ---------------------------------------------------------------
  // Adaptive polling (MEDIUM fix audit-3 #4).
  //
  // schedulePoll(fn, opts) runs `fn` repeatedly. When `fn` resolves cleanly
  // the next tick fires after `baseMs`; on rejection or 5xx envelope the
  // gap doubles up to `maxMs`. Going from degraded back to healthy resets
  // the interval to base. Returns a `cancel()` handle.
  //
  // The transition log is exposed via getLastIntervalMs() for unit tests
  // that want to assert the backoff curve without mocking timers.
  // ---------------------------------------------------------------
  function computeNextDelay(currentMs, baseMs, maxMs, lastWasFailure) {
    if (!lastWasFailure) return baseMs;
    var next = currentMs * 2;
    if (next < baseMs) next = baseMs;
    if (next > maxMs)  next = maxMs;
    return next;
  }

  function schedulePoll(fn, opts) {
    opts = opts || {};
    var baseMs = opts.baseMs || 5000;
    var maxMs  = opts.maxMs  || 60000;
    var current = baseMs;
    var stopped = false;
    var timer = null;
    var lastIntervalMs = baseMs;
    var statsListeners = [];

    function tick() {
      if (stopped) return;
      var p;
      try { p = fn(); } catch (e) { p = Promise.reject(e); }
      Promise.resolve(p).then(function (res) {
        var failure = res && res.code !== undefined && res.code >= 50000;
        current = computeNextDelay(current, baseMs, maxMs, !!failure);
        if (!failure && degraded) setDegraded(false);
        lastIntervalMs = current;
        statsListeners.forEach(function (cb) { try { cb({ intervalMs: current, failure: !!failure }); } catch (_) {} });
        if (!stopped) timer = setTimeout(tick, current);
      }).catch(function () {
        current = computeNextDelay(current, baseMs, maxMs, true);
        lastIntervalMs = current;
        statsListeners.forEach(function (cb) { try { cb({ intervalMs: current, failure: true }); } catch (_) {} });
        if (!stopped) timer = setTimeout(tick, current);
      });
    }

    timer = setTimeout(tick, current);
    return {
      cancel: function () { stopped = true; if (timer) clearTimeout(timer); },
      getLastIntervalMs: function () { return lastIntervalMs; },
      onTick: function (cb) { statsListeners.push(cb); },
    };
  }

  // ---------------------------------------------------------------
  // Idempotent retry for approval/decision actions
  // (MEDIUM fix audit-3 #4 — durable replay handling).
  //
  // idempotentAction(method, url, body, opts) generates ONE Idempotency-Key
  // for the logical action and reuses it across retries. The server's
  // idempotency middleware returns the cached response on the second
  // attempt, so the action's side effects fire exactly once even if the
  // browser drops the connection mid-request.
  // ---------------------------------------------------------------
  function idempotentAction(method, url, body, opts) {
    opts = opts || {};
    var maxAttempts = opts.maxAttempts || 3;
    var backoffMs   = opts.backoffMs   || 750;
    var key         = opts.idempotencyKey || newIdemKey();
    var attempt = 0;

    function tryOnce() {
      attempt += 1;
      return request(method, url, body, { idempotencyKey: key }).then(function (res) {
        // Retry only on transport-style failures (5xx envelopes); never on
        // 4xx user errors — those are deterministic and replaying won't help.
        var transient = res && res.code !== undefined && res.code >= 50000;
        if (!transient) return res;
        if (attempt >= maxAttempts) return res;
        return new Promise(function (resolve) {
          setTimeout(function () { resolve(tryOnce()); }, backoffMs * Math.pow(2, attempt - 1));
        });
      }).catch(function (err) {
        if (attempt >= maxAttempts) throw err;
        return new Promise(function (resolve) {
          setTimeout(function () { resolve(tryOnce()); }, backoffMs * Math.pow(2, attempt - 1));
        });
      });
    }
    return tryOnce();
  }

  global.StudioApi = {
    get:    function (url, opts)        { return request('GET',    url, null, opts); },
    post:   function (url, body, opts)  { return request('POST',   url, body, opts); },
    put:    function (url, body, opts)  { return request('PUT',    url, body, opts); },
    del:    function (url, opts)        { return request('DELETE', url, null, opts); },
    Drafts: Drafts,
    setDegraded: setDegraded,
    isDegraded: function () { return degraded; },
    schedulePoll: schedulePoll,
    idempotentAction: idempotentAction,
    // Exposed for testing/inspection — pure function, no I/O.
    _computeNextDelay: computeNextDelay,
  };
})(window);
