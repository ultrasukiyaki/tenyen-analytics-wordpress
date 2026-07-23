(function () {
  'use strict';

  const { __, _x, sprintf } = window.wp.i18n || {};
  const config = window.TYDashboardWidgetConfig || {};
  const rootId = 'tya-dashboard-widget';

  function createElement(tag, attrs = {}, children = []) {
    const el = document.createElement(tag);
    Object.keys(attrs).forEach((key) => {
      if (key === 'className') {
        el.className = attrs[key];
      } else if (key === 'html') {
        el.innerHTML = attrs[key];
      } else {
        el.setAttribute(key, attrs[key]);
      }
    });
    (Array.isArray(children) ? children : [children]).forEach((child) => {
      if (typeof child === 'string') {
        el.appendChild(document.createTextNode(child));
      } else if (child instanceof Node) {
        el.appendChild(child);
      }
    });
    return el;
  }

  function formatNumber(value) {
    return new Intl.NumberFormat(window.navigator.language || 'en-US').format(value);
  }

  function formatDate(value) {
    if (!value) {
      return __('Never', 'tenyen-analytics');
    }
    const date = new Date(value);
    return date.toLocaleString(window.navigator.language || 'en-US');
  }

  function renderError(message) {
    return createElement('div', { className: 'tya-dashboard-widget__error' }, [
      createElement('p', {}, [__('Failed to load analytics data.', 'tenyen-analytics')]),
      createElement('p', { className: 'tya-dashboard-widget__error-message' }, [message || '']),
    ]);
  }

  function renderEmpty() {
    return createElement('div', { className: 'tya-dashboard-widget__empty' }, [
      createElement('p', {}, [__('No analytics data is available yet.', 'tenyen-analytics')]),
    ]);
  }

  function renderStats(payload) {
    const card = createElement('div', { className: 'tya-dashboard-widget__grid' });
    const items = [
      { label: __('Today pageviews', 'tenyen-analytics'), value: payload.today.pageviews },
      { label: __('Today visitors', 'tenyen-analytics'), value: payload.today.visitors },
      { label: __('Today sessions', 'tenyen-analytics'), value: payload.today.sessions },
      { label: __('Human sessions (30 min)', 'tenyen-analytics'), value: payload.realtime_sessions },
      { label: __('Notable organizations', 'tenyen-analytics'), value: payload.notable_organizations },
    ];

    items.forEach((item) => {
      card.appendChild(
        createElement('div', { className: 'tya-dashboard-widget__stat' }, [
          createElement('div', { className: 'tya-dashboard-widget__stat-label' }, [item.label]),
          createElement('div', { className: 'tya-dashboard-widget__stat-value' }, [formatNumber(item.value || 0)]),
        ])
      );
    });

    return card;
  }

  function renderTopPages(rows) {
    if (!rows || !rows.length) {
      return createElement('div', { className: 'tya-dashboard-widget__section' }, [
        createElement('h4', {}, [__('Top pages today', 'tenyen-analytics')]),
        createElement('p', {}, [__('No top pages yet.', 'tenyen-analytics')]),
      ]);
    }

    const list = createElement('ol', { className: 'tya-dashboard-widget__top-pages' });
    rows.slice(0, 3).forEach((row) => {
      list.appendChild(
        createElement('li', {}, [
          createElement('span', { className: 'tya-dashboard-widget__top-pages-title' }, [row.title || row.path || '—']),
          createElement('span', { className: 'tya-dashboard-widget__top-pages-value' }, [
            sprintf(__('PV: %s', 'tenyen-analytics'), formatNumber(row.pageviews || 0)),
          ]),
        ])
      );
    });

    return createElement('div', { className: 'tya-dashboard-widget__section' }, [
      createElement('h4', {}, [__('Top pages today', 'tenyen-analytics')]),
      list,
    ]);
  }

  async function fetchWidget(bypassCache = false, signal = undefined) {
    const headers = { Accept: 'application/json' };
    if (config.nonce) {
      headers['X-WP-Nonce'] = config.nonce;
    }
    const url = new URL(config.endpoint, window.location.origin);
    if (bypassCache) {
      url.searchParams.set('fresh', '1');
    }

    const response = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers,
      cache: 'no-store',
      signal,
    });
    if (!response.ok) {
      const data = await response.json().catch(() => null);
      throw new Error(data?.message || response.statusText || 'Request failed');
    }
    return await response.json();
  }

  function renderWidget(payload, root) {
    root.innerHTML = '';
    if (!payload || !payload.ok) {
      root.appendChild(renderError(payload?.error || __('Unable to parse response.', 'tenyen-analytics')));
      return;
    }

    const content = createElement('div', { className: 'tya-dashboard-widget__content' });
    const hasData = Number(payload.today?.pageviews || 0) > 0
      || Number(payload.today?.visitors || 0) > 0
      || Number(payload.today?.sessions || 0) > 0
      || Number(payload.realtime_sessions || 0) > 0
      || Number(payload.notable_organizations || 0) > 0
      || (payload.top_pages || []).length > 0;
    if (!hasData) {
      content.appendChild(renderEmpty());
    }
    content.appendChild(renderStats(payload));
    content.appendChild(renderTopPages(payload.top_pages || []));
    content.appendChild(
      createElement('div', { className: 'tya-dashboard-widget__meta' }, [
        createElement('span', {}, [
          sprintf(__('Last received: %s', 'tenyen-analytics'), formatDate(payload.last_received_at)),
        ]),
        createElement('a', {
          href: config.dashboardUrl || 'admin.php?page=tenyen-analytics',
          className: 'tya-dashboard-widget__link',
        }, [__('Open analytics dashboard', 'tenyen-analytics')]),
      ])
    );
    root.appendChild(content);
  }

  function setup(root) {
    const refreshButton = root.querySelector('.tya-dashboard-widget__refresh');
    const body = root.querySelector('.tya-dashboard-widget__body');
    if (!refreshButton || !body) {
      return;
    }

    let controller = null;
    async function load(bypass) {
      body.innerHTML = '';
      body.appendChild(createElement('div', { className: 'tya-dashboard-widget__loading' }, [__('Loading…', 'tenyen-analytics')]));
      controller?.abort();
      controller = new AbortController();
      try {
        const payload = await fetchWidget(bypass, controller.signal);
        renderWidget(payload, body);
      } catch (error) {
        if (error.name === 'AbortError') {
          return;
        }
        body.innerHTML = '';
        const errorBox = renderError(error.message || __('An unexpected error occurred.', 'tenyen-analytics'));
        errorBox.appendChild(createElement('button', {
          type: 'button',
          className: 'button button-secondary',
        }, [__('Retry', 'tenyen-analytics')]));
        errorBox.querySelector('button').addEventListener('click', () => load(true));
        body.appendChild(errorBox);
      }
    }

    refreshButton.addEventListener('click', () => load(true));
    load(false);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById(rootId);
    if (!root) {
      return;
    }
    setup(root);
  });
})();
