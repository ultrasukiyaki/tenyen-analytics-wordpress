(() => {
  'use strict';
  const config = window.TYAMetadata || {};
  const root = document.querySelector('#tya-metadata-manager');
  if (!root) return;
  const content = root.querySelector('[data-meta-content]');
  const status = root.querySelector('[data-meta-status]');
  let tab = 'annotations';
  let controller;

  const esc = value => {
    const span = document.createElement('span');
    span.textContent = String(value ?? '');
    return span.innerHTML;
  };
  const request = async (url, options = {}) => {
    controller?.abort();
    controller = new AbortController();
    const response = await fetch(url, {
      ...options, signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '', ...(options.headers || {})}
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
    return payload;
  };
  const tags = items => (items || []).map(tag => `<span class="tya-meta-tag tya-meta-tag--${esc(tag.color)}">${esc(tag.name)}</span>`).join(' ');
  const table = (head, rows, empty) => `<div class="tya-table-wrap"><table><thead><tr>${head.map(item => `<th>${esc(item)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${head.length}">${esc(empty)}</td></tr>`}</tbody></table></div>`;
  const load = async () => {
    status.textContent = wp.i18n.__('Loading…', 'tenyen-analytics');
    try {
      if (tab === 'tags') {
        const payload = await request(config.tags);
        content.innerHTML = `<form data-tag-form><label>${esc(wp.i18n.__('Tag name', 'tenyen-analytics'))}<input name="name" maxlength="50" required></label> <label>${esc(wp.i18n.__('Color', 'tenyen-analytics'))}<select name="color">${['blue','green','orange','purple','gray','red'].map(color => `<option>${color}</option>`).join('')}</select></label> <button class="button button-primary">${esc(wp.i18n.__('Create tag', 'tenyen-analytics'))}</button></form>` + table(
          [wp.i18n.__('Tag', 'tenyen-analytics'), wp.i18n.__('Usage', 'tenyen-analytics'), wp.i18n.__('Actions', 'tenyen-analytics')],
          payload.items.map(item => `<tr><td>${tags([item])}</td><td>${Number(item.usage_count)}</td><td><button class="button-link-delete" data-delete-tag="${Number(item.tag_id)}" data-count="${Number(item.usage_count)}">${esc(wp.i18n.__('Delete', 'tenyen-analytics'))}</button></td></tr>`),
          wp.i18n.__('No tags yet.', 'tenyen-analytics')
        );
      } else if (tab === 'views') {
        const payload = await request(config.views);
        content.innerHTML = table(
          [wp.i18n.__('Name', 'tenyen-analytics'), wp.i18n.__('Report', 'tenyen-analytics'), wp.i18n.__('Status', 'tenyen-analytics'), wp.i18n.__('Actions', 'tenyen-analytics')],
          payload.items.map(item => `<tr><td>${esc(item.name)}</td><td>${esc(item.report)}</td><td>${item.pinned === '1' ? esc(wp.i18n.__('Pinned', 'tenyen-analytics')) : ''} ${item.is_default === '1' ? esc(wp.i18n.__('Default', 'tenyen-analytics')) : ''}</td><td><button class="button-link-delete" data-delete-view="${Number(item.view_id)}">${esc(wp.i18n.__('Delete', 'tenyen-analytics'))}</button></td></tr>`),
          wp.i18n.__('No saved views yet.', 'tenyen-analytics')
        );
      } else {
        const watched = tab === 'watched' ? '&entity_type=organization&watched=1' : '';
        const payload = await request(`${config.annotations}?per_page=100${watched}`);
        content.innerHTML = table(
          [wp.i18n.__('Type', 'tenyen-analytics'), wp.i18n.__('Alias / original', 'tenyen-analytics'), wp.i18n.__('Tags', 'tenyen-analytics'), wp.i18n.__('Note', 'tenyen-analytics'), wp.i18n.__('Status', 'tenyen-analytics')],
          payload.items.map(item => `<tr><td>${esc(item.entity_type)}</td><td><strong>${esc(item.alias || item.original_value || item.entity_key)}</strong>${item.alias ? `<br><code>${esc(item.original_value || item.entity_key)}</code>` : ''}</td><td>${tags(item.tags)}</td><td class="tya-meta-note">${esc(item.note).replace(/\n/g, '<br>')}</td><td>${item.watched === '1' ? `<span class="tya-watched">${esc(wp.i18n.__('Watched', 'tenyen-analytics'))}</span>` : ''}</td></tr>`),
          tab === 'watched' ? wp.i18n.__('No watched organizations yet.', 'tenyen-analytics') : wp.i18n.__('No annotations yet.', 'tenyen-analytics')
        );
      }
      status.textContent = '';
    } catch (error) {
      if (error.name === 'AbortError') return;
      content.innerHTML = `<div class="notice notice-error inline"><p>${esc(error.message)}</p></div>`;
      status.textContent = wp.i18n.__('Load failed', 'tenyen-analytics');
    }
  };
  root.addEventListener('click', async event => {
    const tabButton = event.target.closest('[data-meta-tab]');
    if (tabButton) {
      tab = tabButton.dataset.metaTab;
      root.querySelectorAll('[data-meta-tab]').forEach(button => button.classList.toggle('nav-tab-active', button === tabButton));
      load(); return;
    }
    const tag = event.target.closest('[data-delete-tag]');
    if (tag && confirm(`${wp.i18n.__('Delete this tag?', 'tenyen-analytics')} (${tag.dataset.count} ${wp.i18n.__('entities', 'tenyen-analytics')})`)) {
      await request(`${config.tags}/${tag.dataset.deleteTag}`, {method: 'DELETE'}); load(); return;
    }
    const view = event.target.closest('[data-delete-view]');
    if (view && confirm(wp.i18n.__('Delete this saved view?', 'tenyen-analytics'))) {
      await request(`${config.views}/${view.dataset.deleteView}`, {method: 'DELETE'}); load();
    }
  });
  root.addEventListener('submit', async event => {
    if (!event.target.matches('[data-tag-form]')) return;
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.target));
    await request(config.tags, {method: 'POST', body: JSON.stringify(values)});
    load();
  });
  load();
})();
