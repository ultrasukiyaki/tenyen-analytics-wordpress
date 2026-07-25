(() => {
  'use strict';
  const config = window.TYASessions || {};
  const root = document.querySelector('[data-sessions-root]');
  if (!root) return;
  const form = root.querySelector('[data-sessions-form]');
  const list = root.querySelector('[data-sessions-list]');
  const status = root.querySelector('[data-sessions-status]');
  const dialog = document.querySelector('[data-session-dialog]');
  const content = dialog.querySelector('[data-dialog-content]');
  const title = dialog.querySelector('[data-dialog-title]');
  let page = 1;
  let controller;
  let returnFocus;

  const request = async endpoint => {
    controller?.abort();
    controller = new AbortController();
    const response = await fetch(endpoint, {
      headers: {'Accept': 'application/json', 'X-WP-Nonce': config.nonce || ''},
      credentials: 'same-origin', cache: 'no-store', signal: controller.signal
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
    return payload;
  };
  const load = async requestedPage => {
    page = Math.max(1, Number(requestedPage) || 1);
    status.textContent = wp.i18n.__('Loading…', 'tenyen-analytics');
    const params = new URLSearchParams(new FormData(form));
    [...params].forEach(([key, value]) => { if (!String(value).trim()) params.delete(key); });
    params.set('page', String(page));
    try {
      const payload = await request(`${config.listEndpoint}?${params}`);
      list.innerHTML = payload.html || '';
      status.textContent = `${Number(payload.total || 0).toLocaleString()} ${wp.i18n.__('sessions', 'tenyen-analytics')}`;
    } catch (error) {
      if (error.name === 'AbortError') return;
      list.innerHTML = `<p class="notice notice-error">${escapeHtml(error.message)}</p>`;
      status.textContent = wp.i18n.__('Server error. Please retry.', 'tenyen-analytics');
    }
  };
  const escapeHtml = value => {
    const node = document.createElement('span');
    node.textContent = String(value || '');
    return node.innerHTML;
  };
  const open = async (kind, id, trigger) => {
    if (!id) return;
    returnFocus = trigger || document.activeElement;
    dialog.hidden = false;
    document.body.classList.add('tya-dialog-open');
    content.innerHTML = `<p role="status">${wp.i18n.__('Loading…', 'tenyen-analytics')}</p>`;
    dialog.querySelector('[data-dialog-close]').focus();
    try {
      const base = kind === 'visitor' ? config.visitorEndpoint : config.sessionEndpoint;
      const payload = await request(base + encodeURIComponent(id));
      title.textContent = payload.title || wp.i18n.__('Details', 'tenyen-analytics');
      content.innerHTML = payload.html || '';
    } catch (error) {
      if (error.name !== 'AbortError') content.innerHTML = `<p class="notice notice-error">${escapeHtml(error.message)}</p>`;
    }
  };
  const close = () => {
    dialog.hidden = true;
    document.body.classList.remove('tya-dialog-open');
    controller?.abort();
    returnFocus?.focus?.();
  };
  form.addEventListener('submit', event => { event.preventDefault(); load(1); });
  form.addEventListener('reset', () => setTimeout(() => load(1), 0));
  root.addEventListener('click', event => {
    const pager = event.target.closest('[data-session-page]');
    if (pager) load(pager.dataset.sessionPage);
    const session = event.target.closest('[data-session-id]');
    if (session) open('session', session.dataset.sessionId, session);
    const visitor = event.target.closest('[data-visitor-id]');
    if (visitor) open('visitor', visitor.dataset.visitorId, visitor);
  });
  dialog.addEventListener('click', event => {
    if (event.target.closest('[data-dialog-close]')) close();
    const session = event.target.closest('[data-session-id]');
    if (session) open('session', session.dataset.sessionId, session);
    const visitor = event.target.closest('[data-visitor-id]');
    if (visitor) open('visitor', visitor.dataset.visitorId, visitor);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !dialog.hidden) close();
    if (event.key === 'Tab' && !dialog.hidden) {
      const focusable = [...dialog.querySelectorAll('button,a[href],input,select,summary')].filter(node => !node.disabled);
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  const query = new URLSearchParams(location.search);
  load(1).then(() => {
    if (query.get('session')) open('session', query.get('session'));
    else if (query.get('visitor')) open('visitor', query.get('visitor'));
  });
})();
