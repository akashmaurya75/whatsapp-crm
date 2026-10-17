// WhatsApp CRM — progressive enhancement only; every action works without JS.
(function () {
  'use strict';
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } }
  };

  // Theme
  var theme = store.get('wcrm-theme');
  if (theme === 'dark' || theme === 'light') document.documentElement.setAttribute('data-theme', theme);

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.documentElement;
    var shell = document.querySelector('[data-shell]');

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var current = root.getAttribute('data-theme') ||
          (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        var next = current === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        store.set('wcrm-theme', next);
      });
    });

    // Sidebar: collapse on desktop, slide-in on mobile
    if (shell && store.get('wcrm-sidebar') === 'collapsed' && window.innerWidth > 900) shell.classList.add('is-collapsed');
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!shell) return;
        if (window.innerWidth <= 900) { shell.classList.toggle('is-open'); return; }
        shell.classList.toggle('is-collapsed');
        store.set('wcrm-sidebar', shell.classList.contains('is-collapsed') ? 'collapsed' : 'open');
      });
    });

    // Confirmation dialogs for destructive forms
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
      });
    });

    // Prevent double submits
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) setTimeout(function () { btn.disabled = true; }, 0);
      });
    });

    // Toasts
    document.querySelectorAll('[data-toast]').forEach(function (t) {
      var close = function () { t.remove(); };
      var b = t.querySelector('[data-dismiss]'); if (b) b.addEventListener('click', close);
      setTimeout(close, 6000);
    });

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = btn.parentElement.querySelector('[data-copy-source]');
        if (!input) return;
        input.select();
        var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
        if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
        else { document.execCommand('copy'); done(); }
      });
    });

    // Keyboard shortcuts: "/" focuses search, "r" focuses reply box
    document.addEventListener('keydown', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.metaKey || e.ctrlKey || e.altKey) return;
      var el = document.querySelector('[data-hotkey="' + e.key + '"]');
      if (el) { e.preventDefault(); el.focus(); }
    });

    initInbox();
    initInstaller();
    initTemplateBuilder();
    initToggles();
  });

  // Show/hide helpers driven by data attributes (campaigns, automations, import, inbox).
  function initToggles() {
    var each = function (sel, fn) { document.querySelectorAll(sel).forEach(fn); };
    each('[data-confirm-click]', function (b) {
      b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-click'))) e.preventDefault(); });
    });
    var consent = document.querySelector('[data-consent-toggle]'), attest = document.querySelector('[data-attest]');
    if (consent && attest) {
      var syncC = function () { attest.hidden = consent.checked; };
      consent.addEventListener('change', syncC); syncC();
    }
    var at = document.querySelector('[data-schedule-at]');
    if (at) {
      var syncM = function () {
        var m = document.querySelector('[data-send-mode]:checked'); at.hidden = !m || m.value !== 'later';
      };
      each('[data-send-mode]', function (r) { r.addEventListener('change', syncM); }); syncM();
    }
    var panels = document.querySelectorAll('[data-reply-panel]');
    if (panels.length) {
      var syncR = function () {
        var r = document.querySelector('[data-reply-type]:checked');
        panels.forEach(function (p) { p.hidden = !r || p.getAttribute('data-reply-panel') !== r.value; });
      };
      each('[data-reply-type]', function (r) { r.addEventListener('change', syncR); }); syncR();
    }
    each('[data-template-picker]', function (s) {
      s.addEventListener('change', function () {
        if (s.value && window.confirm('Load this template’s variables? Unsaved changes on this page will be lost.')) {
          window.location.href = s.getAttribute('data-base-url') + encodeURIComponent(s.value);
        }
      });
    });
    each('[data-autosubmit]', function (s) { s.addEventListener('change', function () { if (s.value) s.form.submit(); }); });
    var all = document.querySelector('[data-consent-all]'), conf = document.querySelector('[data-consent-confirm]');
    if (all && conf) { var syncA = function () { conf.hidden = !all.checked; }; all.addEventListener('change', syncA); syncA(); }
    each('[data-varmap-row]', function (row) {
      var src = row.querySelector('[data-varmap-source]'), st = row.querySelector('[data-varmap-static]'), fb = row.querySelector('[data-varmap-fallback]');
      var sync = function () { var s = src.value === 'static'; st.hidden = !s; fb.hidden = s; };
      src.addEventListener('change', sync); sync();
    });
  }

  // Template builder: live WhatsApp-style preview, variable samples, formatting.
  function initTemplateBuilder() {
    var form = document.querySelector('[data-tpl-form]');
    if (!form) return;
    var body = form.querySelector('[data-tpl-body]');
    var samplesBox = form.querySelector('[data-samples]');
    var count = form.querySelector('[data-count]');
    var p = {
      media: document.querySelector('[data-p-media]'), header: document.querySelector('[data-p-header]'),
      body: document.querySelector('[data-p-body]'), footer: document.querySelector('[data-p-footer]'), buttons: document.querySelector('[data-p-buttons]')
    };
    var sampleValues = {};
    try { sampleValues = JSON.parse(samplesBox.getAttribute('data-initial') || '{}') || {}; } catch (e) { sampleValues = {}; }

    function vars(text) {
      var out = [], re = /\{\{\s*(\d+)\s*\}\}/g, m;
      while ((m = re.exec(text))) { var n = parseInt(m[1], 10); if (out.indexOf(n) === -1) out.push(n); }
      return out.sort(function (a, b) { return a - b; });
    }
    function fmt(text) {
      // WhatsApp formatting on escaped text: *bold* _italic_ ~strike~
      return esc(text).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>').replace(/~([^~\n]+)~/g, '<s>$1</s>').replace(/\n/g, '<br>');
    }
    function fill(text, values) {
      return text.replace(/\{\{\s*(\d+)\s*\}\}/g, function (_, n) { return values[n] ? values[n] : '{{' + n + '}}'; });
    }
    function renderSamples() {
      samplesBox.querySelectorAll('input').forEach(function (i) {
        var n = i.name.match(/\[(\d+)\]/); if (n) sampleValues[n[1]] = i.value;
      });
      var html = '';
      vars(body.value).forEach(function (n) {
        html += '<label>Example for {{' + n + '}} <input name="body_samples[' + n + ']" maxlength="200" value="' + esc(sampleValues[n] || '') + '" required></label>';
      });
      samplesBox.innerHTML = html;
      samplesBox.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', update); });
      if (form.querySelector('fieldset') && form.querySelector('fieldset').disabled) {
        samplesBox.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
      }
    }
    function headerType() { var r = form.querySelector('[data-header-type]:checked'); return r ? r.value : 'none'; }
    function update() {
      var ht = headerType();
      form.querySelectorAll('[data-header-panel]').forEach(function (el) {
        var k = el.getAttribute('data-header-panel');
        el.hidden = !((k === 'text' && ht === 'text') || (k === 'media' && ['image', 'video', 'document'].indexOf(ht) !== -1));
      });
      var values = {};
      samplesBox.querySelectorAll('input').forEach(function (i) { var n = i.name.match(/\[(\d+)\]/); if (n) values[n[1]] = i.value; });
      p.media.hidden = ['image', 'video', 'document'].indexOf(ht) === -1;
      p.media.textContent = ht === 'video' ? '▶ Video' : ht === 'document' ? '📄 Document' : '🖼 Image';
      var hText = form.querySelector('[name="header_text"]').value, hSample = form.querySelector('[name="header_sample"]').value;
      p.header.innerHTML = ht === 'text' ? esc(fill(hText, { 1: hSample })) : '';
      p.body.innerHTML = body.value ? fmt(fill(body.value, values)) : '<span class="muted">Your message appears here…</span>';
      p.footer.textContent = form.querySelector('[data-tpl-footer]').value;
      var bh = '';
      form.querySelectorAll('.btn-row').forEach(function (row) {
        var t = row.querySelector('[data-btn-type]').value, label = row.querySelector('[data-btn-text]').value;
        var val = row.querySelector('[data-btn-value]');
        val.hidden = t !== 'url' && t !== 'phone';
        val.placeholder = t === 'phone' ? '+91 98765 43210' : 'https://…';
        if (t && label) bh += '<div class="wa-btn">' + (t === 'url' ? '↗ ' : t === 'phone' ? '📞 ' : '↩ ') + esc(label) + '</div>';
      });
      p.buttons.innerHTML = bh;
      if (count) count.textContent = body.value.length + '/1024';
    }
    body.addEventListener('input', function () { renderSamples(); update(); });
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (el !== body) { el.addEventListener('input', update); el.addEventListener('change', update); }
    });
    var name = form.querySelector('[data-tpl-name]');
    if (name) name.addEventListener('input', function () {
      var c = name.selectionStart; name.value = name.value.toLowerCase().replace(/[\s-]+/g, '_').replace(/[^a-z0-9_]/g, ''); name.setSelectionRange(c, c);
    });
    var addVar = form.querySelector('[data-insert-var]');
    if (addVar) addVar.addEventListener('click', function () {
      var next = (vars(body.value).slice(-1)[0] || 0) + 1, s = body.selectionStart, e = body.selectionEnd, tok = '{{' + next + '}}';
      body.value = body.value.slice(0, s) + tok + body.value.slice(e);
      body.focus(); body.setSelectionRange(s + tok.length, s + tok.length);
      renderSamples(); update();
    });
    form.querySelectorAll('[data-wrap]').forEach(function (b) {
      b.addEventListener('click', function () {
        var w = b.getAttribute('data-wrap'), s = body.selectionStart, e = body.selectionEnd;
        if (s === e) return;
        body.value = body.value.slice(0, s) + w + body.value.slice(s, e) + w + body.value.slice(e);
        body.focus(); body.setSelectionRange(s, e + 2); update();
      });
    });
    var more = form.querySelector('[data-more-buttons]');
    if (more) more.addEventListener('click', function () {
      var hidden = form.querySelectorAll('[data-extra][hidden]');
      if (hidden.length) hidden[0].hidden = false;
      if (hidden.length <= 1) more.hidden = true;
    });
    renderSamples(); update();
  }

  // Installer: show the fields for the selected database type; show progress on install.
  function initInstaller() {
    var form = document.querySelector('[data-db-form]');
    if (form) {
      var sync = function () {
        var checked = form.querySelector('input[name="driver"]:checked');
        var driver = checked ? checked.value : '';
        form.querySelectorAll('[data-driver-panel]').forEach(function (p) {
          p.hidden = p.getAttribute('data-driver-panel') !== driver;
        });
      };
      form.querySelectorAll('input[name="driver"]').forEach(function (r) { r.addEventListener('change', sync); });
      sync();
    }
    var install = document.querySelector('[data-install-form]');
    if (install) {
      install.addEventListener('submit', function () {
        var b = install.querySelector('[data-install-submit]');
        if (b) b.textContent = 'Installing… (this can take a few seconds)';
      });
    }
  }

  function esc(s) {
    var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML;
  }

  function initInbox() {
    var box = document.querySelector('[data-messages]');
    if (!box) return;
    box.scrollTop = box.scrollHeight;
    var composer = document.querySelector('[data-composer]');
    var textarea = composer && composer.querySelector('textarea');

    // Enter to send, Shift+Enter for newline
    if (textarea) {
      textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          if (textarea.value.trim() !== '') composer.requestSubmit ? composer.requestSubmit() : composer.submit();
        }
      });
    }

    // Reply-to
    var replyInput = composer && composer.querySelector('[data-reply-to]');
    var replying = composer && composer.querySelector('[data-replying]');
    document.querySelectorAll('[data-reply]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!replyInput) return;
        replyInput.value = btn.getAttribute('data-reply');
        replying.querySelector('[data-replying-text]').textContent = 'Replying to: ' + btn.getAttribute('data-reply-text');
        replying.hidden = false;
        if (textarea) textarea.focus();
      });
    });
    var cancel = composer && composer.querySelector('[data-cancel-reply]');
    if (cancel) cancel.addEventListener('click', function () { replyInput.value = ''; replying.hidden = true; });

    // Poll for new messages and status updates (every 5 s while visible)
    var url = box.getAttribute('data-poll');
    var icons = { queued: '◷', submitted: '✓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '!' };
    function poll() {
      if (document.hidden) return;
      var last = box.getAttribute('data-last-id') || '0';
      fetch(url + '?after=' + encodeURIComponent(last), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          var nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
          data.messages.forEach(function (m) {
            if (document.getElementById('m' + m.id)) return;
            var el = document.createElement('div');
            el.className = 'msg msg-' + (m.direction === 'in' ? 'in' : 'out');
            el.id = 'm' + m.id;
            el.setAttribute('data-id', m.id);
            var status = m.direction === 'out'
              ? '<span class="msg-status status-' + esc(m.status) + '" data-status>' + esc(icons[m.status] || '') + ' ' + esc(m.status) + '</span>' : '';
            el.innerHTML = (m.type !== 'text' ? '<span class="msg-type">[' + esc(m.type) + ']</span>' : '') +
              '<div class="msg-body">' + esc(m.body).replace(/\n/g, '<br>') + '</div>' +
              '<div class="msg-meta">' + (m.sender_name ? '<span>' + esc(m.sender_name) + '</span>' : '') +
              '<time>' + esc(String(m.created_at).substr(11, 5)) + '</time>' + status + '</div>';
            box.appendChild(el);
            box.setAttribute('data-last-id', m.id);
          });
          data.statuses.forEach(function (s) {
            var el = document.querySelector('#m' + s.id + ' [data-status]');
            if (el && el.className.indexOf('status-' + s.status) === -1) {
              el.className = 'msg-status status-' + s.status;
              el.textContent = (icons[s.status] || '') + ' ' + s.status;
              el.title = s.status + (s.error_message ? ': ' + s.error_message : '');
            }
          });
          if (nearBottom && data.messages.length) box.scrollTop = box.scrollHeight;
        })
        .catch(function () { /* transient network error; next poll retries */ });
    }
    setInterval(poll, 5000);
  }
})();
