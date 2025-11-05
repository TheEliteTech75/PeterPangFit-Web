(function(){
  const ENDPOINT = 'client_sessions_actions.php';
  const POLL_INTERVAL = 4000;
  const AUTO_CLOSE_DELAY = 2600;
  let activeOverlay = null;
  let pollTimer = null;
  let currentSessionId = null;
  let currentOptions = null;

  function getCsrf() {
    if (typeof window.__CSRF === 'string') {
      return window.__CSRF;
    }
    return '';
  }

  function createOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'ppf-session-qr-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = `
      <div class="ppf-session-qr-modal">
        <button type="button" class="ppf-session-qr-close" aria-label="Close">&times;</button>
        <div class="ppf-session-qr-content" data-qr-content>
          <div class="ppf-session-qr-loading">Generating secure code…</div>
        </div>
      </div>
    `;
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        closeOverlay();
      }
    });
    const closeBtn = overlay.querySelector('.ppf-session-qr-close');
    closeBtn.addEventListener('click', closeOverlay);
    document.body.appendChild(overlay);
    document.body.classList.add('ppf-session-qr-open');
    activeOverlay = overlay;
    return overlay;
  }

  function destroyOverlay() {
    if (!activeOverlay) return;
    activeOverlay.remove();
    document.body.classList.remove('ppf-session-qr-open');
    activeOverlay = null;
  }

  function closeOverlay() {
    stopPolling();
    destroyOverlay();
    if (currentOptions && typeof currentOptions.onClose === 'function') {
      try { currentOptions.onClose(); } catch (err) { /* ignore */ }
    }
    currentSessionId = null;
    currentOptions = null;
  }

  function setContent(node) {
    if (!activeOverlay) return;
    const container = activeOverlay.querySelector('[data-qr-content]');
    if (!container) return;
    container.innerHTML = '';
    if (node) {
      container.appendChild(node);
    }
  }

  function showError(message) {
    const block = document.createElement('div');
    block.className = 'ppf-session-qr-error';
    block.innerHTML = `<strong>Unable to display QR code.</strong><p>${message}</p>`;
    setContent(block);
  }

  function showSuccess(details) {
    const block = document.createElement('div');
    block.className = 'ppf-session-qr-success';
    block.innerHTML = `
      <div class="ppf-session-qr-success-icon" aria-hidden="true">✓</div>
      <h3>Session Complete!</h3>
      <p>${details}</p>
    `;
    setContent(block);
    window.setTimeout(closeOverlay, AUTO_CLOSE_DELAY);
  }

  function showQr(data, options) {
    const block = document.createElement('div');
    block.className = 'ppf-session-qr-ready';
    const who = options.trainer && options.trainer.first_name
      ? `${options.trainer.first_name} ${options.trainer.last_name || ''}`.trim()
      : '';
    const when = options.session && options.session.scheduled_start
      ? new Date(options.session.scheduled_start.replace(' ', 'T'))
      : null;
    let whenText = '';
    if (when && !isNaN(when.getTime())) {
      whenText = when.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
    const subtitle = options.packageName
      ? options.packageName + (whenText ? ` • ${whenText}` : '')
      : (whenText || 'Present session');
    block.innerHTML = `
      <h3>Scan to complete</h3>
      <p class="ppf-session-qr-subtitle">${subtitle}</p>
      <div class="ppf-session-qr-code"><img alt="Session QR" src="trainer_sessions_qr.php?token=${encodeURIComponent(data.token)}&size=320"></div>
      <p class="ppf-session-qr-instructions">${who ? `Trainer: ${who}<br>` : ''}Have your trainer scan this code to wrap up your session.</p>
    `;
    setContent(block);
  }

  function stopPolling() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startPolling(sessionId, options) {
    stopPolling();
    pollTimer = window.setInterval(() => {
      requestStatus(sessionId).then((payload) => {
        if (!payload || !payload.ok) {
          return;
        }
        const session = payload.session || null;
        if (options && typeof options.onStatus === 'function') {
          try { options.onStatus(payload); } catch (err) { /* ignore */ }
        }
        if (session && typeof session.status === 'string' && session.status.toLowerCase() === 'completed') {
          stopPolling();
          if (options && typeof options.onCompleted === 'function') {
            try { options.onCompleted(payload); } catch (err) { /* ignore */ }
          }
          const summary = options && options.packageName ? options.packageName : 'Training session';
          showSuccess(`${summary} has been confirmed.`);
        }
      }).catch(() => {});
    }, POLL_INTERVAL);
  }

  function requestToken(sessionId) {
    const params = new URLSearchParams();
    params.set('action', 'request_token');
    params.set('session_id', sessionId);
    const csrf = getCsrf();
    if (csrf) {
      params.set('csrf_token', csrf);
    }
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
      credentials: 'same-origin',
    }).then((res) => res.json().catch(() => null).then((json) => ({ ok: res.ok, json })));
  }

  function requestStatus(sessionId) {
    const params = new URLSearchParams();
    params.set('action', 'session_status');
    params.set('session_id', sessionId);
    const csrf = getCsrf();
    if (csrf) {
      params.set('csrf_token', csrf);
    }
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
      credentials: 'same-origin',
    }).then((res) => res.json().catch(() => null));
  }

  function open(sessionId, options = {}) {
    sessionId = String(sessionId || '').trim();
    if (!sessionId) {
      return Promise.reject(new Error('Missing session id.'));
    }
    if (activeOverlay) {
      closeOverlay();
    }
    currentSessionId = sessionId;
    currentOptions = options;
    const overlay = createOverlay();
    setContent(document.createElement('div'));
    const loader = document.createElement('div');
    loader.className = 'ppf-session-qr-loading';
    loader.textContent = 'Generating secure code…';
    setContent(loader);

    return requestToken(sessionId).then((result) => {
      if (!result.ok || !result.json || !result.json.ok) {
        const message = (result.json && result.json.message) ? result.json.message : 'Unable to fetch session token.';
        showError(message);
        return Promise.reject(new Error(message));
      }
      const payload = result.json;
      if (options && typeof options.onReady === 'function') {
        try { options.onReady(payload); } catch (err) { /* ignore */ }
      }
      showQr(payload, {
        packageName: options.packageName || (payload.package && payload.package.name) || '',
        trainer: payload.trainer || {},
        session: payload.session || {},
      });
      if (options && typeof options.onStatus === 'function') {
        try { options.onStatus(payload); } catch (err) { /* ignore */ }
      }
      startPolling(sessionId, options);
      return payload;
    }).catch((error) => {
      if (!activeOverlay) {
        return Promise.reject(error);
      }
      showError(error && error.message ? error.message : 'Unexpected error.');
      return Promise.reject(error);
    });
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-session-qr-trigger]');
    if (!trigger) return;
    const sessionId = trigger.getAttribute('data-session-qr-session-id');
    if (!sessionId) return;
    event.preventDefault();
    const label = trigger.getAttribute('data-session-qr-label') || '';
    open(sessionId, {
      packageName: label,
    }).catch(() => {});
  });

  window.ppfSessionQrOpen = open;
})();
