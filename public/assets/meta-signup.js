// "Continue with Facebook" — WhatsApp Embedded Signup (v4). Loaded only on /whatsapp/connect.
(function () {
  'use strict';
  var root = document.querySelector('[data-meta-signup]');
  if (!root) return;
  var btn = root.querySelector('[data-fb-login]');
  var statusEl = root.querySelector('[data-fb-status]');
  var pinInput = root.querySelector('[data-fb-pin]');
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var session = {}; // filled by the WA_EMBEDDED_SIGNUP message (waba_id, phone_number_id)

  function say(text, kind) {
    statusEl.textContent = text;
    statusEl.className = 'alert small ' + (kind === 'error' ? 'alert-error' : kind === 'ok' ? 'alert-success' : '');
    statusEl.hidden = !text;
  }

  // Session info from the popup (only trust messages from facebook.com).
  window.addEventListener('message', function (event) {
    var host;
    try { host = new URL(event.origin).hostname; } catch (e) { return; }
    if (host !== 'facebook.com' && !/\.facebook\.com$/.test(host)) return;
    var data;
    try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (e) { return; }
    if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
    if (String(data.event || '').indexOf('FINISH') === 0) {
      session.waba_id = data.data && data.data.waba_id ? String(data.data.waba_id) : '';
      session.phone_number_id = data.data && data.data.phone_number_id ? String(data.data.phone_number_id) : '';
    } else if (data.event === 'CANCEL') {
      var d = data.data || {};
      say(d.error_message ? 'Facebook reported an error: ' + d.error_message : 'Sign-up was closed before finishing' + (d.current_step ? ' (at step: ' + d.current_step + ')' : '') + '. You can try again.', 'error');
      btn.disabled = false;
    }
  });

  function send(code) {
    say('Connecting your WhatsApp number… this takes a few seconds.');
    fetch(root.getAttribute('data-post-url'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ code: code, waba_id: session.waba_id || '', phone_number_id: session.phone_number_id || '', pin: pinInput ? pinInput.value : '' })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); })
      .then(function (res) {
        if (res.ok && res.redirect) { say('Connected! Opening your account…', 'ok'); window.location.href = res.redirect; return; }
        var msg = typeof res.error === 'string' ? res.error : (res.error && res.error.message);
        say(msg || 'Connection failed.', 'error'); btn.disabled = false;
      })
      .catch(function () { say('Network error while connecting. Please try again.', 'error'); btn.disabled = false; });
  }

  window.fbAsyncInit = function () {
    window.FB.init({ appId: root.getAttribute('data-app-id'), autoLogAppEvents: true, xfbml: false, version: root.getAttribute('data-version') });
    btn.disabled = false;
    say('');
  };
  var s = document.createElement('script');
  s.async = true; s.defer = true; s.crossOrigin = 'anonymous';
  s.src = 'https://connect.facebook.net/en_US/sdk.js';
  s.onerror = function () { say('Could not load Facebook. Check your internet connection or ad blocker, then reload.', 'error'); };
  document.head.appendChild(s);

  btn.addEventListener('click', function () {
    if (!window.FB) return;
    btn.disabled = true; session = {};
    say('Complete the steps in the Facebook window…');
    window.FB.login(function (response) {
      if (!response || !response.authResponse || !response.authResponse.code) {
        if (!statusEl.classList.contains('alert-error')) say('Facebook sign-in was cancelled.', 'error');
        btn.disabled = false; return;
      }
      var code = response.authResponse.code;
      // The session message can arrive just after this callback; wait briefly (the code is valid for 30 s).
      var waited = 0;
      (function wait() {
        if (session.waba_id || waited >= 1500) { send(code); return; }
        waited += 150; setTimeout(wait, 150);
      })();
    }, {
      config_id: root.getAttribute('data-config-id'),
      response_type: 'code',
      override_default_response_type: true,
      extras: {} // v4: products are chosen in the Login for Business configuration
    });
  });
})();
