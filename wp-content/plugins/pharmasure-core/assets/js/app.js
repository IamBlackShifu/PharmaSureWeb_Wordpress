(() => {
  'use strict';

  const config = window.PharmaSureConfig || {};
  const mount = document.querySelector('#pharmasure-app');
  const routes = [
    ['overview', 'Overview'], ['pos', 'Point of Sale'], ['inventory', 'Inventory'],
    ['clinical', 'Clinical'], ['claims', 'Claims'], ['reports', 'Reports'],
    ['offline', 'Offline'], ['account', 'Account']
  ];
  const inventoryViews = [
    ['catalogue', 'Catalogue'], ['batches', 'Batches'], ['receipts', 'Receipts'],
    ['movements', 'Movements'], ['low-stock', 'Low stock'], ['expiry', 'Expiry'], ['suppliers', 'Suppliers']
  ];
  const requestedInventoryView = new URLSearchParams(window.location.search).get('view');
  const state = {
    route: routeFromLocation(),
    inventoryView: inventoryViews.some(([slug]) => slug === requestedInventoryView) ? requestedInventoryView : 'catalogue',
    inventorySearch: '',
    inventoryOptions: null,
    loading: false
  };

  function node(tag, options = {}, children = []) {
    const element = document.createElement(tag);
    Object.entries(options).forEach(([key, value]) => {
      if (value === null || value === undefined) return;
      if (key === 'className') element.className = value;
      else if (key === 'text') element.textContent = String(value);
      else if (key === 'htmlFor') element.htmlFor = value;
      else if (key.startsWith('on') && typeof value === 'function') element.addEventListener(key.slice(2).toLowerCase(), value);
      else element.setAttribute(key, String(value));
    });
    const items = Array.isArray(children) ? children : [children];
    items.filter(item => item !== null && item !== undefined).forEach(item => element.append(item instanceof Node ? item : document.createTextNode(String(item))));
    return element;
  }

  function routeFromLocation() {
    const path = window.location.pathname.replace(/^\/app\/?/, '').split('/')[0];
    return routes.some(([slug]) => slug === path) ? path : 'overview';
  }

  function routeUrl(route) {
    return `${String(config.appUrl || '/app').replace(/\/$/, '')}${route === 'overview' ? '' : `/${route}`}`;
  }

  function inventoryUrl(view = 'catalogue', search = '') {
    const url = new URL(routeUrl('inventory'), window.location.origin);
    if (view !== 'catalogue') url.searchParams.set('view', view);
    if (search) url.searchParams.set('q', search);
    return `${url.pathname}${url.search}`;
  }

  async function api(path, options = {}) {
    const response = await fetch(`${config.apiUrl}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
        ...(options.headers || {})
      }
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || 'The secure workspace could not complete this request.');
    return body;
  }

  function applyTheme(theme) {
    config.theme = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = config.theme;
    const themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.content = config.theme === 'light' ? '#F4F6F7' : '#09090B';
  }

  function renderThemeToggle() {
    const button = node('button', { id: 'ps-theme-toggle', className: 'ps-theme-toggle', type: 'button' });
    const update = () => {
      const isLight = config.theme === 'light';
      button.setAttribute('aria-label', `Switch to ${isLight ? 'dark' : 'light'} theme`);
      button.setAttribute('aria-pressed', String(isLight));
      button.replaceChildren(
        node('span', { className: 'ps-theme-toggle__icon', 'aria-hidden': 'true', text: isLight ? '◐' : '☼' }),
        node('span', { text: isLight ? 'Dark' : 'Light' })
      );
    };
    button.addEventListener('click', async () => {
      const previous = config.theme;
      applyTheme(previous === 'light' ? 'dark' : 'light');
      update();
      button.disabled = true;
      try {
        await api('app/preferences/theme', { method: 'POST', body: JSON.stringify({ theme: config.theme }) });
      } catch (error) {
        applyTheme(previous);
        update();
        showError(error.message);
      } finally {
        button.disabled = false;
      }
    });
    update();
    return button;
  }

  function renderHeader() {
    const brand = node('div', { className: 'ps-brand' }, [
      node('span', { className: 'ps-brand__mark', 'aria-hidden': 'true', text: 'P' }),
      node('div', { className: 'ps-brand__text' }, [node('strong', { text: 'PharmaSure' }), node('small', { text: 'Pharmacy operations' })])
    ]);

    const nav = node('nav', { className: 'ps-global-nav', 'aria-label': 'Pharmacy modules' });
    routes.forEach(([slug, label]) => {
      const link = node('a', { href: routeUrl(slug), text: label });
      if (state.route === slug) link.setAttribute('aria-current', 'page');
      link.addEventListener('click', event => {
        event.preventDefault();
        navigate(slug);
      });
      nav.append(link);
    });

    const command = node('button', { className: 'ps-command', type: 'button', 'aria-label': 'Open command launcher', onClick: openCommand }, [
      node('span', { text: 'Command' }), node('kbd', { text: 'Ctrl K' })
    ]);
    const branchLabel = node('label', { htmlFor: 'ps-working-branch', text: 'Working branch' });
    const branchSelect = node('select', { id: 'ps-working-branch', 'aria-label': 'Working branch' });
    (config.branches || []).forEach(branch => {
      const option = node('option', { value: branch.id, text: branch.name });
      if (Number(branch.id) === Number(config.activeBranch)) option.selected = true;
      branchSelect.append(option);
    });
    branchSelect.addEventListener('change', async () => {
      branchSelect.disabled = true;
      try {
        await api('app/branch', { method: 'POST', body: JSON.stringify({ branch_id: Number(branchSelect.value) }) });
        config.activeBranch = Number(branchSelect.value);
        state.inventoryOptions = null;
        if (state.route === 'overview') await renderOverview();
        if (state.route === 'inventory') await renderInventory(state.inventoryView, state.inventorySearch);
      } catch (error) {
        showError(error.message);
      } finally {
        branchSelect.disabled = false;
      }
    });
    const tools = node('div', { className: 'ps-global-tools' }, [
      renderThemeToggle(), command,
      node('div', { className: 'ps-branch-control' }, [branchLabel, branchSelect]),
      node('span', { className: 'ps-role', text: config.currentUser?.role || 'staff', title: config.currentUser?.displayName || 'Current user' })
    ]);
    return node('header', { className: 'ps-global-header' }, [brand, nav, tools]);
  }

  function renderFrame() {
    mount.replaceChildren(renderHeader(), node('main', { id: 'ps-workspace', className: 'ps-workspace', tabindex: '-1' }));
    mount.setAttribute('aria-busy', 'false');
  }

  function navigate(route) {
    if (!routes.some(([slug]) => slug === route)) route = 'overview';
    state.route = route;
    window.history.pushState({ route }, '', route === 'inventory' ? inventoryUrl(state.inventoryView, state.inventorySearch) : routeUrl(route));
    renderFrame();
    renderRoute();
  }

  function renderRoute() {
    if (state.route === 'overview') renderOverview();
    else if (state.route === 'inventory') renderInventory(state.inventoryView, state.inventorySearch);
    else renderModule(state.route);
  }

  function metric(label, value, note, tone = '') {
    return node('section', { className: `ps-metric${tone ? ` ps-metric--${tone}` : ''}` }, [
      node('span', { className: 'ps-metric__label', text: label }),
      node('strong', { text: value }),
      node('small', { text: note })
    ]);
  }

  async function renderOverview() {
    const workspace = document.querySelector('#ps-workspace');
    workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(overviewSkeleton());
    try {
      const data = await api('app/overview');
      workspace.replaceChildren(overviewDashboard(data));
    } catch (error) {
      workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message }));
    } finally {
      workspace.setAttribute('aria-busy', 'false');
    }
  }

  function overviewSkeleton() {
    return node('div', {}, [
      node('div', { className: 'ps-scope-banner ps-skeleton', style: 'height:86px' }),
      node('div', { className: 'ps-metrics ps-skeleton', style: 'height:110px' }),
      node('div', { className: 'ps-panel ps-skeleton', style: 'height:340px' })
    ]);
  }

  function overviewDashboard(data) {
    const lowTone = Number(data.metrics.low_stock) > 0 ? 'warning' : 'healthy';
    const expiryTone = Number(data.metrics.expiring_90_days) > 0 ? 'critical' : 'healthy';
    const heading = node('section', { className: 'ps-scope-banner' }, [
      node('div', {}, [
        node('span', { className: 'ps-eyebrow', text: 'Operations overview' }),
        node('h1', { text: `${data.scope.pharmacy} — ${data.scope.branch}` }),
        node('p', { text: 'Live inventory posture and recent medicine movement.' })
      ]),
      node('span', { className: 'ps-live', text: 'Live branch scope' })
    ]);
    const metrics = node('section', { className: 'ps-metrics', 'aria-label': 'Inventory posture' }, [
      metric('Active medicines', formatInteger(data.metrics.active_medicines), 'Tenant catalogue'),
      metric('Units available', formatQuantity(data.metrics.units_available), 'Current branch'),
      metric('Low stock alert', formatInteger(data.metrics.low_stock), Number(data.metrics.low_stock) ? 'Review required' : 'Within threshold', lowTone),
      metric('Expiring ≤90 days', formatInteger(data.metrics.expiring_90_days), Number(data.metrics.expiring_90_days) ? 'Shelf-life action' : 'No immediate risk', expiryTone)
    ]);
    return node('div', {}, [heading, metrics, node('div', { className: 'ps-split' }, [ledgerPanel(data.movements), inspector(data.signals)])]);
  }

  function ledgerPanel(movements) {
    const panel = node('section', { className: 'ps-panel', 'aria-labelledby': 'ps-ledger-title' });
    panel.append(node('div', { className: 'ps-panel__head' }, [
      node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Stock ledger' }), node('h2', { id: 'ps-ledger-title', text: 'Recent movement' }), node('p', { text: 'Immutable branch-level inventory events.' })]),
      node('span', { className: 'ps-record-count', text: `${movements.length} records` })
    ]));
    if (!movements.length) {
      panel.append(node('div', { className: 'ps-empty' }, [node('strong', { text: 'No movement in this branch' }), node('span', { text: 'Stock receipts and dispensing activity will appear here.' })]));
      return panel;
    }
    const table = node('table', { className: 'ps-ledger' });
    const headRow = node('tr');
    ['Time', 'Medicine', 'Movement', 'Quantity', 'Reference'].forEach(label => headRow.append(node('th', { scope: 'col', text: label })));
    table.append(node('thead', {}, headRow));
    const body = node('tbody');
    movements.forEach(movement => {
      const positive = Number(movement.quantity_delta) > 0;
      const movementName = titleCase(movement.movement_type);
      body.append(node('tr', {}, [
        node('td', {}, node('time', { datetime: mysqlDate(movement.created_at), text: formatTime(movement.created_at) })),
        node('td', {}, [node('strong', { text: movement.name }), node('code', { text: movement.sku })]),
        node('td', {}, node('span', { className: `ps-badge${positive ? '' : ' ps-badge--out'}`, text: movementName })),
        node('td', { className: `ps-number ${positive ? 'ps-number--positive' : 'ps-number--negative'}`, text: `${positive ? '+' : ''}${formatQuantity(movement.quantity_delta)}` }),
        node('td', {}, [node('strong', { text: titleCase(movement.reference_type) }), node('small', { text: `#${movement.reference_id}` })])
      ]));
    });
    table.append(body);
    panel.append(node('div', { className: 'ps-table-wrap', role: 'region', 'aria-label': 'Recent stock movements', tabindex: '0' }, table));
    return panel;
  }

  function inspector(signals) {
    const low = Number(signals.low_stock || 0);
    const expiring = Number(signals.expiring_90_days || 0);
    const list = node('dl', { className: 'ps-signal-list' }, [
      signal('Low-stock medicines', low, low > 0), signal('Batches expiring soon', expiring, expiring > 0), signal('Security scope', 'Verified', false)
    ]);
    const actions = node('nav', { className: 'ps-quick-actions', 'aria-label': 'Operational quick actions' });
    [['inventory', 'Review inventory'], ['reports', 'Open movement reports'], ['pos', 'Open point of sale']].forEach(([route, label]) => {
      const link = node('a', { href: routeUrl(route) }, [node('span', { text: label }), node('span', { 'aria-hidden': 'true', text: '→' })]);
      link.addEventListener('click', event => { event.preventDefault(); navigate(route); });
      actions.append(link);
    });
    return node('aside', { className: 'ps-panel ps-inspector', 'aria-labelledby': 'ps-signals-title' }, [
      node('span', { className: 'ps-eyebrow', text: 'Attention queue' }), node('h2', { id: 'ps-signals-title', text: 'Operational signals' }),
      node('p', { text: 'Exceptions that need a decision in the active branch.' }), list, actions,
      node('div', { className: 'ps-security-note' }, [node('strong', { text: 'Server-enforced scope' }), node('span', { text: 'Tenant identity is derived from your authenticated pharmacy site and is never accepted from the browser.' })])
    ]);
  }

  function signal(label, value, warning) {
    return node('div', {}, [node('dt', { text: label }), node('dd', { className: warning ? 'is-warning' : '', text: value })]);
  }

  const inventoryMeta = {
    catalogue: ['Medicine master', 'Catalogue', 'Branch availability, clinical identity and prescribing controls in one compact register.', 'Catalogue profile', 'Medicine identity remains tenant-owned while availability follows the authenticated branch.'],
    batches: ['FEFO control', 'Batch register', 'Trace quantities, acquisition cost, selling price and shelf life by medicine lot.', 'Batch posture', 'Earliest-expiring stock is ordered first to make the next eligible batch obvious.'],
    receipts: ['Inbound control', 'Goods receipts', 'Reconcile supplier deliveries with recorded lines, quantities and landed value.', 'Receiving context', 'Completed receipts produce traceable batches and immutable stock-ledger entries.'],
    movements: ['Immutable ledger', 'Stock movement history', 'Follow receipts, dispensing events, sales and adjustments back to their source.', 'Ledger posture', 'Correlation and source references preserve a complete branch investigation path.'],
    'low-stock': ['Replenishment queue', 'Low-stock queue', 'Prioritise replenishment by availability, reorder level and calculated shortfall.', 'Queue health', 'Out-of-stock items lead the queue, followed by medicines approaching threshold.'],
    expiry: ['Shelf-life assurance', 'Expiry inspection', 'Inspect every live batch in FEFO order with urgency bands and value exposure.', 'Shelf-life posture', 'Near-term batches surface first while long-dated stock remains visible for assurance.'],
    suppliers: ['Supply network', 'Supplier context', 'Keep delivery history, commercial terms and contact context beside inventory.', 'Supplier network', 'Receipt activity is calculated only inside the current authorized branch.']
  };

  async function renderInventory(view = 'catalogue', search = '') {
    state.inventoryView = inventoryViews.some(([slug]) => slug === view) ? view : 'catalogue';
    state.inventorySearch = state.inventoryView === 'catalogue' ? String(search || '') : '';
    const workspace = document.querySelector('#ps-workspace');
    workspace.className = 'ps-workspace ps-inventory-workspace';
    workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(inventorySkeleton());
    try {
      const query = new URLSearchParams({ view: state.inventoryView });
      if (state.inventorySearch) query.set('q', state.inventorySearch);
      const data = await api(`inventory/workspace?${query.toString()}`);
      workspace.replaceChildren(inventoryDashboard(data));
    } catch (error) {
      workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message }));
    } finally {
      workspace.setAttribute('aria-busy', 'false');
    }
  }

  function inventorySkeleton() {
    return node('div', {}, [
      node('div', { className: 'ps-inventory-title ps-skeleton', style: 'height:84px' }),
      node('div', { className: 'ps-inventory-pulse ps-skeleton', style: 'height:88px' }),
      node('div', { className: 'ps-inventory-body ps-skeleton', style: 'height:380px' })
    ]);
  }

  function inventoryDashboard(data) {
    const meta = inventoryMeta[data.view] || inventoryMeta.catalogue;
    const title = node('section', { className: 'ps-inventory-title' }, [
      node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Inventory control' }), node('h1', { text: 'Inventory workspace' }), node('p', { text: 'Medicine availability, traceability and replenishment without leaving the application shell.' })]),
      node('div', { className: 'ps-inventory-scope' }, [node('span', { text: data.scope.trading_name }), node('strong', { text: data.scope.branch_name }), node('small', { text: `${data.scope.currency} · LIVE LEDGER` })])
    ]);
    const pulse = node('section', { className: 'ps-inventory-pulse', 'aria-label': 'Inventory summary' }, [
      inventoryPulse('Active medicines', data.summary.products, 'catalogue'),
      inventoryPulse('Units available', formatQuantity(data.summary.units), 'batches'),
      inventoryPulse('Low-stock queue', data.summary.low_stock, 'low-stock', Number(data.summary.low_stock) > 0),
      inventoryPulse('Expiry attention', data.summary.expiry, 'expiry', Number(data.summary.expiry) > 0)
    ]);
    const tabs = node('nav', { className: 'ps-inventory-tabs', 'aria-label': 'Inventory workspace sections' });
    inventoryViews.forEach(([slug, label], index) => {
      const link = node('a', { href: inventoryUrl(slug), }, [node('span', { text: String(index + 1).padStart(2, '0'), 'aria-hidden': 'true' }), node('strong', { text: label })]);
      if (data.view === slug) link.setAttribute('aria-current', 'page');
      link.addEventListener('click', event => { event.preventDefault(); selectInventoryView(slug); });
      tabs.append(link);
    });
    const canvas = node('section', { className: 'ps-panel ps-inventory-canvas', 'aria-labelledby': 'ps-inventory-view-title' }, [
      inventoryCanvasHeader(data, meta), inventoryTable(data)
    ]);
    const inspectorPanel = inventoryInspector(data, meta);
    return node('div', {}, [title, pulse, tabs, inventoryCommandBar(data.view), node('div', { className: 'ps-inventory-body' }, [canvas, inspectorPanel])]);
  }

  function inventoryPulse(label, value, view, attention = false) {
    const link = node('a', { className: `ps-inventory-pulse__item${attention ? ' is-attention' : ''}`, href: inventoryUrl(view) }, [
      node('span', { text: label }), node('strong', { text: value }), node('small', { text: 'Open register →' })
    ]);
    link.addEventListener('click', event => { event.preventDefault(); selectInventoryView(view); });
    return link;
  }

  function selectInventoryView(view, search = '') {
    state.route = 'inventory';
    state.inventoryView = view;
    state.inventorySearch = search;
    window.history.pushState({ route: 'inventory', view }, '', inventoryUrl(view, search));
    renderFrame();
    renderInventory(view, search);
  }

  function inventoryCanvasHeader(data, meta) {
    const actions = node('div', { className: 'ps-inventory-canvas__actions' });
    if (data.view === 'catalogue') {
      const input = node('input', { type: 'search', value: state.inventorySearch, placeholder: 'Search SKU, barcode or medicine', 'aria-label': 'Search medicine catalogue' });
      const form = node('form', { className: 'ps-inventory-search', role: 'search' }, [input, node('button', { type: 'submit', text: 'Search' })]);
      form.addEventListener('submit', event => { event.preventDefault(); selectInventoryView('catalogue', input.value.trim()); });
      actions.append(form);
    }
    actions.append(node('span', { className: 'ps-record-count', text: `${data.rows.length} records` }));
    return node('header', { className: 'ps-inventory-canvas__head' }, [
      node('div', {}, [node('span', { className: 'ps-eyebrow', text: meta[0] }), node('h2', { id: 'ps-inventory-view-title', text: meta[1] }), node('p', { text: meta[2] })]), actions
    ]);
  }

  function inventoryTable(data) {
    if (!data.rows.length) return node('div', { className: 'ps-empty' }, [node('strong', { text: 'No records in this branch' }), node('span', { text: 'Try another inventory section or adjust the catalogue search.' })]);
    const headers = {
      catalogue: ['Medicine', 'Clinical profile', 'Availability', 'Reorder', 'Price', 'Control', 'Action'],
      batches: ['Batch / medicine', 'Supplier', 'Expiry', 'Available', 'Unit cost', 'Sell price', 'Action'],
      receipts: ['Receipt', 'Supplier / branch', 'Received', 'Lines', 'Units', 'Value'],
      movements: ['Time', 'Medicine', 'Movement', 'Quantity', 'Source', 'Correlation'],
      'low-stock': ['Medicine', 'Available', 'Reorder level', 'Shortfall', 'Unit', 'Priority'],
      expiry: ['Batch / medicine', 'Supplier', 'Expiry date', 'Days remaining', 'Quantity', 'Exposure', 'Action'],
      suppliers: ['Supplier', 'Contact', 'Terms', 'Receipts', 'Last delivery', 'Status', 'Action']
    };
    const table = node('table', { className: 'ps-inventory-table' });
    const head = node('tr');
    headers[data.view].forEach(label => head.append(node('th', { scope: 'col', text: label })));
    table.append(node('thead', {}, head));
    const body = node('tbody');
    data.rows.forEach(row => body.append(inventoryRow(data.view, row, data.scope.currency)));
    table.append(body);
    return node('div', { className: 'ps-inventory-table-wrap', role: 'region', 'aria-label': `${inventoryMeta[data.view][1]} register`, tabindex: '0' }, table);
  }

  function inventoryRow(view, row, currency) {
    const tr = node('tr');
    let cells = [];
    if (view === 'catalogue') {
      const control = Number(row.is_controlled) ? ['Controlled', 'danger'] : (Number(row.requires_prescription) ? ['Prescription', 'purple'] : ['OTC', 'neutral']);
      cells = [identity(row.name, row.generic_name || 'Generic name not recorded', row.sku), identity(`${row.strength || ''} ${row.dosage_form || ''}`.trim() || 'Profile incomplete', row.category || 'Uncategorised'), quantityCell(row.quantity_available, row.unit_of_measure), numberCell(row.reorder_level), numberCell(money(row.selling_price_minor, currency)), statusCell(control[0], control[1]), actionCell('Manage', () => openInventoryWorkflow('medicine', row))];
    } else if (view === 'batches') {
      cells = [identity(row.batch_number, `${row.name}${row.strength ? ` ${row.strength}` : ''}`, row.sku), textCell(row.supplier_name || 'Not recorded'), textCell(formatDate(row.expiry_date)), quantityCell(row.quantity_available, `of ${formatQuantity(row.quantity_received)} received`), numberCell(money(row.unit_cost_minor, currency)), numberCell(money(row.selling_price_minor, currency)), actionCell('Disposition', () => openInventoryWorkflow('batch', row))];
    } else if (view === 'receipts') {
      cells = [identity(row.purchase_reference || `Receipt #${row.id}`, '', '', statusBadge(titleCase(row.status), 'success')), identity(row.supplier_name, row.branch_name), textCell(formatDate(row.received_date)), numberCell(formatInteger(row.line_count)), numberCell(formatQuantity(row.units_received)), numberCell(money(row.receipt_value_minor, currency))];
    } else if (view === 'movements') {
      const positive = Number(row.quantity_delta) > 0;
      cells = [textCell(formatTime(row.created_at)), identity(row.name, '', row.sku), statusCell(titleCase(row.movement_type), positive ? 'success' : 'warning'), numberCell(`${positive ? '+' : ''}${formatQuantity(row.quantity_delta)}`, positive ? 'positive' : 'negative'), identity(titleCase(row.reference_type), `#${row.reference_id}`), codeCell(compactId(row.correlation_id))];
    } else if (view === 'low-stock') {
      const empty = Number(row.quantity_available) <= 0;
      cells = [identity(row.name, row.generic_name || 'Generic name not recorded', row.sku), numberCell(formatQuantity(row.quantity_available)), numberCell(formatQuantity(row.reorder_level)), numberCell(formatQuantity(row.shortfall), 'negative'), textCell(row.unit_of_measure), statusCell(empty ? 'Out of stock' : 'Reorder', empty ? 'danger' : 'warning')];
    } else if (view === 'expiry') {
      const days = Number(row.days_remaining); const tone = days < 0 ? 'danger' : (days <= 90 ? 'danger' : (days <= 180 ? 'warning' : 'success'));
      cells = [identity(row.batch_number, row.name, row.sku), textCell(row.supplier_name || 'Not recorded'), textCell(formatDate(row.expiry_date)), statusCell(days < 0 ? `${Math.abs(days)} days overdue` : `${days} days`, tone), numberCell(formatQuantity(row.quantity_available)), numberCell(money(Number(row.quantity_available) * Number(row.unit_cost_minor), currency)), actionCell('Disposition', () => openInventoryWorkflow('batch', row))];
    } else if (view === 'suppliers') {
      const contact = node('div', { className: 'ps-cell-stack' });
      if (row.email) contact.append(node('a', { href: `mailto:${row.email}`, text: row.email }));
      contact.append(node('small', { text: row.phone || 'Phone not recorded' }));
      cells = [identity(row.name, row.contact_name || 'No contact assigned'), node('td', {}, contact), textCell(row.payment_terms || 'Not recorded'), numberCell(formatInteger(row.receipt_count)), textCell(row.last_receipt ? formatDate(row.last_receipt) : '—'), statusCell(titleCase(row.status), 'success')];
    }
    if (view === 'suppliers') cells.push(actionCell('Manage', () => openInventoryWorkflow('supplier', row)));
    cells.forEach(cell => tr.append(cell));
    return tr;
  }

  function identity(primary, secondary = '', code = '', extra = null) {
    const stack = node('div', { className: 'ps-cell-stack' }, [node('strong', { text: primary })]);
    if (secondary) stack.append(node('small', { text: secondary }));
    if (code) stack.append(node('code', { text: code }));
    if (extra) stack.append(extra);
    return node('td', {}, stack);
  }
  function textCell(value) { return node('td', { text: value }); }
  function codeCell(value) { return node('td', {}, node('code', { text: value })); }
  function numberCell(value, tone = '') { return node('td', { className: `ps-number${tone ? ` ps-number--${tone}` : ''}`, text: value }); }
  function quantityCell(value, unit) { return node('td', {}, node('div', { className: 'ps-cell-stack ps-number' }, [node('strong', { text: formatQuantity(value) }), node('small', { text: unit })])); }
  function statusCell(label, tone) { return node('td', {}, statusBadge(label, tone)); }
  function statusBadge(label, tone = 'neutral') { return node('span', { className: `ps-inventory-status ps-inventory-status--${tone}`, text: label }); }
  function actionCell(label, handler) { return node('td', {}, node('button', { className: 'ps-table-action', type: 'button', text: label, onClick: handler })); }

  function inventoryCommandBar(activeView) {
    const bar = node('section', { className: 'ps-inventory-command-bar', 'aria-label': 'Inventory operations' });
    const intro = node('div', { className: 'ps-inventory-command-bar__intro' }, [
      node('span', { text: 'COMMAND LAYER' }), node('strong', { text: 'Controlled stock operations' })
    ]);
    const actions = node('div', { className: 'ps-inventory-command-bar__actions' });
    [
      ['receipt', 'Receive stock', 'primary'], ['adjustment', 'Adjust stock', ''], ['transfer', 'Transfer', ''],
      ['medicine', 'Add medicine', activeView === 'catalogue' ? 'context' : ''], ['supplier', 'Add supplier', activeView === 'suppliers' ? 'context' : '']
    ].forEach(([type, label, tone]) => actions.append(node('button', {
      className: `ps-operation-button${tone ? ` ps-operation-button--${tone}` : ''}`,
      type: 'button', text: label, onClick: () => openInventoryWorkflow(type)
    })));
    bar.append(intro, actions);
    return bar;
  }

  async function inventoryOptions() {
    if (!state.inventoryOptions) {
      const response = await api('inventory/options');
      state.inventoryOptions = response.data || response;
    }
    return state.inventoryOptions;
  }

  async function openInventoryWorkflow(type, record = null) {
    try {
      const options = await inventoryOptions();
      document.querySelector('#ps-inventory-workflow')?.remove();
      const dialog = buildInventoryWorkflow(type, record, options);
      document.body.append(dialog);
      dialog.showModal();
      dialog.querySelector('input:not([type="hidden"]),select,textarea,button')?.focus();
    } catch (error) {
      showError(error.message);
    }
  }

  function buildInventoryWorkflow(type, record, options) {
    const copy = {
      medicine: [record ? 'Edit medicine' : 'New medicine', 'Maintain clinical identity, price and replenishment controls.'],
      supplier: [record ? 'Edit supplier' : 'New supplier', 'Maintain the approved supply-network record.'],
      receipt: ['Receive stock', 'Post a supplier delivery into batches and the immutable branch ledger.'],
      adjustment: ['Adjust physical stock', 'Record a reasoned correction against a specific branch batch.'],
      transfer: ['Inter-branch transfer', 'Allocate FEFO stock and preserve the source batch at the destination.'],
      batch: ['Batch disposition', 'Quarantine, release, expire or withdraw a batch from available stock.']
    }[type];
    const dialog = node('dialog', { id: 'ps-inventory-workflow', className: 'ps-workflow-dialog', 'aria-labelledby': 'ps-workflow-title' });
    const close = node('button', { className: 'ps-dialog-close', type: 'button', 'aria-label': 'Close inventory workflow', text: 'Close', onClick: () => dialog.close() });
    const header = node('header', { className: 'ps-workflow-dialog__head' }, [
      node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Inventory operation' }), node('h2', { id: 'ps-workflow-title', text: copy[0] }), node('p', { text: copy[1] })]), close
    ]);
    const form = node('form', { className: 'ps-workflow-form' });
    form.append(workflowBody(type, record, options));
    const submit = node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'submit', text: workflowSubmitLabel(type, record) });
    const footerActions = node('div', { className: 'ps-workflow-form__submit' }, [node('button', { className: 'ps-operation-button', type: 'button', text: 'Cancel', onClick: () => dialog.close() }), submit]);
    if (record && ['medicine', 'supplier'].includes(type)) {
      footerActions.prepend(node('button', { className: 'ps-operation-button ps-operation-button--danger', type: 'button', text: 'Archive', onClick: () => archiveInventoryRecord(type, record, dialog) }));
    }
    form.append(footerActions);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      submit.disabled = true;
      submit.textContent = 'Posting...';
      try {
        const request = workflowRequest(type, record, form);
        await api(request.path, { method: request.method, body: JSON.stringify(request.payload) });
        dialog.close();
        state.inventoryOptions = null;
        announce(`${copy[0]} completed and recorded in the inventory ledger.`);
        await renderInventory(state.inventoryView, state.inventorySearch);
      } catch (error) {
        workflowError(form, error.message);
      } finally {
        submit.disabled = false;
        submit.textContent = workflowSubmitLabel(type, record);
      }
    });
    dialog.append(header, form);
    dialog.addEventListener('close', () => dialog.remove());
    return dialog;
  }

  function workflowSubmitLabel(type, record) {
    if (record && ['medicine', 'supplier'].includes(type)) return 'Save changes';
    return { medicine: 'Create medicine', supplier: 'Create supplier', receipt: 'Post receipt', adjustment: 'Post adjustment', transfer: 'Complete transfer', batch: 'Apply disposition' }[type];
  }

  function workflowBody(type, record, options) {
    const body = node('div', { className: 'ps-workflow-form__body' });
    const grid = node('div', { className: 'ps-workflow-grid' });
    if (type === 'medicine') {
      grid.append(
        workflowField('SKU', 'sku', 'text', record?.sku, { required: true, mono: true }), workflowField('Barcode', 'barcode', 'text', record?.barcode, { mono: true }),
        workflowField('Medicine name', 'name', 'text', record?.name, { required: true, wide: true }), workflowField('Generic name', 'generic_name', 'text', record?.generic_name),
        workflowField('Strength', 'strength', 'text', record?.strength), workflowField('Dosage form', 'dosage_form', 'text', record?.dosage_form),
        workflowField('Pack size', 'pack_size', 'text', record?.pack_size), workflowField('Unit of measure', 'unit_of_measure', 'text', record?.unit_of_measure || 'unit', { required: true }),
        workflowField('Category', 'category', 'text', record?.category), workflowField('Manufacturer', 'manufacturer', 'text', record?.manufacturer),
        workflowField('Acquisition price', 'cost_price', 'number', minorToMajor(record?.cost_price_minor), { min: 0, step: .01 }), workflowField('Selling price', 'selling_price', 'number', minorToMajor(record?.selling_price_minor), { min: 0, step: .01 }),
        workflowField('Reorder level', 'reorder_level', 'number', record?.reorder_level || 0, { min: 0, step: .001 }), workflowCheckbox('Prescription required', 'requires_prescription', Number(record?.requires_prescription) === 1),
        workflowCheckbox('Controlled medicine', 'is_controlled', Number(record?.is_controlled) === 1)
      );
    } else if (type === 'supplier') {
      grid.append(
        workflowField('Supplier name', 'name', 'text', record?.name, { required: true, wide: true }), workflowField('Contact name', 'contact_name', 'text', record?.contact_name),
        workflowField('Telephone', 'phone', 'tel', record?.phone), workflowField('Email', 'email', 'email', record?.email),
        workflowField('Tax / registration number', 'tax_number', 'text', record?.tax_number, { mono: true }), workflowField('Payment terms', 'payment_terms', 'text', record?.payment_terms)
      );
    } else if (type === 'receipt') {
      grid.append(
        workflowSelect('Supplier', 'supplier_id', options.suppliers, item => item.id, item => item.name, { required: true }),
        workflowField('Supplier reference', 'purchase_reference', 'text', '', { mono: true }),
        workflowField('Received date', 'received_date', 'date', today(), { required: true }),
        workflowField('Receiving note', 'notes', 'text', '', { wide: true })
      );
      body.append(grid, lineEditor('receipt', options));
      return body;
    } else if (type === 'adjustment') {
      grid.append(
        workflowSelect('Reason code', 'reason_code', [
          { id: 'stocktake_correction', name: 'Stocktake correction' }, { id: 'damage', name: 'Damage' }, { id: 'loss', name: 'Loss' },
          { id: 'return_to_stock', name: 'Return to stock' }, { id: 'data_correction', name: 'Data correction' }
        ], item => item.id, item => item.name, { required: true }),
        workflowSelect('Branch batch', 'batch_id', options.batches.filter(item => item.status === 'active'), item => item.id, item => `${item.sku} / ${item.batch_number} / ${formatQuantity(item.quantity_available)}`, { required: true, wide: true }),
        workflowField('Quantity delta', 'quantity_delta', 'number', '', { required: true, step: .001 }),
        workflowField('Adjustment rationale', 'notes', 'text', '', { required: true, wide: true })
      );
    } else if (type === 'transfer') {
      grid.append(
        workflowSelect('Destination branch', 'to_branch_id', options.branches, item => item.id, item => `${item.name} (${item.code})`, { required: true }),
        workflowField('Transfer note', 'notes', 'text', '', { wide: true })
      );
      body.append(grid, lineEditor('transfer', options));
      return body;
    } else if (type === 'batch') {
      grid.append(
        workflowField('Batch', 'batch_display', 'text', `${record?.sku || ''} / ${record?.batch_number || ''}`, { disabled: true, mono: true, wide: true }),
        workflowSelect('New disposition', 'status', [
          { id: 'quarantined', name: 'Quarantined - unavailable for sale' }, { id: 'active', name: 'Active - release to sale' },
          { id: 'expired', name: 'Expired - unavailable' }, { id: 'withdrawn', name: 'Withdrawn / recalled' }
        ], item => item.id, item => item.name, { required: true }),
        workflowField('Disposition reason', 'reason', 'textarea', '', { required: true, wide: true })
      );
    }
    body.append(grid);
    return body;
  }

  function workflowField(label, name, type = 'text', value = '', settings = {}) {
    const id = `ps-field-${name}-${Math.random().toString(36).slice(2, 7)}`;
    const attributes = { id, name, type, value: value ?? '', autocomplete: 'off' };
    ['min', 'max', 'step'].forEach(key => { if (settings[key] !== undefined) attributes[key] = settings[key]; });
    if (settings.required) attributes.required = '';
    if (settings.disabled) attributes.disabled = '';
    const control = type === 'textarea' ? node('textarea', { id, name, required: settings.required ? '' : null, text: value || '' }) : node('input', attributes);
    return node('label', { className: `ps-workflow-field${settings.wide ? ' ps-workflow-field--wide' : ''}${settings.mono ? ' ps-workflow-field--mono' : ''}`, htmlFor: id }, [node('span', { text: label }), control]);
  }

  function workflowCheckbox(label, name, checked) {
    const input = node('input', { type: 'checkbox', name, value: '1', checked: checked ? '' : null });
    return node('label', { className: 'ps-workflow-check' }, [input, node('span', { text: label })]);
  }

  function workflowSelect(label, name, items, valueOf, labelOf, settings = {}) {
    const id = `ps-field-${name}-${Math.random().toString(36).slice(2, 7)}`;
    const select = node('select', { id, name, required: settings.required ? '' : null });
    select.append(node('option', { value: '', text: items.length ? 'Select...' : 'No eligible records', disabled: '', selected: '' }));
    items.forEach(item => select.append(node('option', { value: valueOf(item), text: labelOf(item) })));
    return node('label', { className: `ps-workflow-field${settings.wide ? ' ps-workflow-field--wide' : ''}`, htmlFor: id }, [node('span', { text: label }), select]);
  }

  function lineEditor(kind, options) {
    const section = node('section', { className: 'ps-line-editor', 'aria-label': kind === 'receipt' ? 'Receipt lines' : 'Transfer lines' });
    const lines = node('div', { className: 'ps-line-editor__lines' });
    const addLine = () => {
      const line = node('div', { className: 'ps-workflow-line', 'data-kind': kind });
      if (kind === 'receipt') {
        line.append(
          workflowSelect('Medicine', 'line_drug_id', options.drugs, item => item.id, item => `${item.sku} - ${item.name}`, { required: true }),
          workflowField('Batch number', 'line_batch_number', 'text', '', { required: true, mono: true }), workflowField('Quantity', 'line_quantity', 'number', '', { required: true, min: .001, step: .001 }),
          workflowField('Unit cost', 'line_unit_cost', 'number', '', { required: true, min: 0, step: .01 }), workflowField('Selling price', 'line_selling_price', 'number', '', { min: 0, step: .01 }),
          workflowField('Expiry date', 'line_expiry_date', 'date', '', { required: true })
        );
      } else {
        line.append(
          workflowSelect('Medicine', 'line_drug_id', options.drugs, item => item.id, item => `${item.sku} - ${item.name}`, { required: true, wide: true }),
          workflowField('Quantity', 'line_quantity', 'number', '', { required: true, min: .001, step: .001 })
        );
      }
      line.append(node('button', { className: 'ps-line-remove', type: 'button', text: 'Remove line', onClick: () => { if (lines.children.length > 1) line.remove(); } }));
      lines.append(line);
    };
    const head = node('header', { className: 'ps-line-editor__head' }, [
      node('div', {}, [node('strong', { text: kind === 'receipt' ? 'Delivery lines' : 'Transfer lines' }), node('small', { text: 'Each line is validated and posted in one database transaction.' })]),
      node('button', { className: 'ps-operation-button', type: 'button', text: 'Add line', onClick: addLine })
    ]);
    addLine();
    section.append(head, lines);
    return section;
  }

  function workflowRequest(type, record, form) {
    const data = new FormData(form);
    const get = name => String(data.get(name) || '').trim();
    if (type === 'medicine') return {
      path: `inventory/drugs${record ? `/${record.id}` : ''}`, method: record ? 'PATCH' : 'POST',
      payload: { sku: get('sku'), barcode: get('barcode'), name: get('name'), generic_name: get('generic_name'), strength: get('strength'), dosage_form: get('dosage_form'), pack_size: get('pack_size'), unit_of_measure: get('unit_of_measure'), category: get('category'), manufacturer: get('manufacturer'), cost_price_minor: majorToMinor(get('cost_price')), selling_price_minor: majorToMinor(get('selling_price')), reorder_level: Number(get('reorder_level') || 0), requires_prescription: data.has('requires_prescription'), is_controlled: data.has('is_controlled') }
    };
    if (type === 'supplier') return {
      path: `inventory/suppliers${record ? `/${record.id}` : ''}`, method: record ? 'PATCH' : 'POST',
      payload: { name: get('name'), contact_name: get('contact_name'), phone: get('phone'), email: get('email'), tax_number: get('tax_number'), payment_terms: get('payment_terms') }
    };
    if (type === 'receipt') return { path: 'inventory/receipts', method: 'POST', payload: { supplier_id: Number(get('supplier_id')), purchase_reference: get('purchase_reference'), received_date: get('received_date'), notes: get('notes'), items: collectWorkflowLines(form, type) } };
    if (type === 'adjustment') return { path: 'inventory/adjustments', method: 'POST', payload: { reason_code: get('reason_code'), notes: get('notes'), items: [{ batch_id: Number(get('batch_id')), quantity_delta: Number(get('quantity_delta')), reason: get('notes') }] } };
    if (type === 'transfer') return { path: 'inventory/transfers', method: 'POST', payload: { to_branch_id: Number(get('to_branch_id')), notes: get('notes'), items: collectWorkflowLines(form, type) } };
    return { path: `inventory/batches/${record.id}/status`, method: 'POST', payload: { status: get('status'), reason: get('reason') } };
  }

  function collectWorkflowLines(form, type) {
    return [...form.querySelectorAll('.ps-workflow-line')].map(line => {
      const value = name => line.querySelector(`[name="${name}"]`)?.value || '';
      if (type === 'receipt') return { drug_id: Number(value('line_drug_id')), batch_number: value('line_batch_number').trim(), quantity: Number(value('line_quantity')), unit_cost_minor: majorToMinor(value('line_unit_cost')), selling_price_minor: majorToMinor(value('line_selling_price')), expiry_date: value('line_expiry_date') };
      return { drug_id: Number(value('line_drug_id')), quantity: Number(value('line_quantity')) };
    });
  }

  async function archiveInventoryRecord(type, record, dialog) {
    if (!window.confirm(`Archive ${record.name}? This is blocked while medicine stock remains available.`)) return;
    try {
      await api(`inventory/${type === 'medicine' ? 'drugs' : 'suppliers'}/${record.id}`, { method: 'DELETE' });
      dialog.close(); state.inventoryOptions = null; announce(`${record.name} archived.`); await renderInventory(state.inventoryView, state.inventorySearch);
    } catch (error) { workflowError(dialog.querySelector('form'), error.message); }
  }

  function workflowError(form, message) {
    form.querySelector('.ps-workflow-error')?.remove();
    form.prepend(node('div', { className: 'ps-workflow-error', role: 'alert', text: message }));
  }

  function announce(message) {
    document.querySelector('.ps-toast')?.remove();
    const toast = node('div', { className: 'ps-toast', role: 'status', text: message });
    document.body.append(toast);
    window.setTimeout(() => toast.remove(), 5000);
  }

  function majorToMinor(value) { return Math.round(Number(value || 0) * 100); }
  function minorToMajor(value) { return value === undefined || value === null || value === '' ? '' : (Number(value) / 100).toFixed(2); }
  function today() { return new Date().toISOString().slice(0, 10); }

  function inventoryInspector(data, meta) {
    const labels = {
      active_batches: 'Active batches', available_units: 'Available units', receipts: 'Receipts shown', units_received: 'Units received',
      entries: 'Entries shown', inbound: 'Inbound entries', outbound: 'Outbound entries', action_items: 'Items requiring action', out_of_stock: 'Out of stock',
      batches: 'Batches inspected', within_180_days: 'Within 180 days', stock_value_minor: 'Stock value reviewed', suppliers: 'Suppliers shown', active: 'Active suppliers',
      medicines: 'Medicines shown', prescription_items: 'Prescription items', categories: 'Categories'
    };
    const facts = node('dl', { className: 'ps-inventory-facts' });
    Object.entries(data.facts).forEach(([key, value]) => {
      let display = formatInteger(value);
      if (['available_units', 'units_received'].includes(key)) display = formatQuantity(value);
      if (key === 'stock_value_minor') display = money(value, data.scope.currency);
      facts.append(node('div', {}, [node('dt', { text: labels[key] || titleCase(key) }), node('dd', { text: display })]));
    });
    return node('aside', { className: 'ps-panel ps-inventory-inspector', 'aria-labelledby': 'ps-inventory-inspector-title' }, [
      node('span', { className: 'ps-eyebrow', text: 'Context inspector' }), node('h2', { id: 'ps-inventory-inspector-title', text: meta[3] }), node('p', { text: meta[4] }), facts,
      node('div', { className: 'ps-security-note' }, [node('strong', { text: 'Scope protection' }), node('span', { text: 'Tenant and branch are resolved from the authenticated session and cannot be supplied by this screen.' })])
    ]);
  }

  function renderModule(route) {
    const label = routes.find(([slug]) => slug === route)?.[1] || 'Workspace';
    const workspace = document.querySelector('#ps-workspace');
    workspace.replaceChildren(node('section', { className: 'ps-module' }, node('div', { className: 'ps-module__card' }, [
      node('span', { className: 'ps-eyebrow', text: 'Headless application route' }),
      node('h1', { text: label }),
      node('p', { text: `${label} is now inside the standalone application shell. Its existing operational workspace is the next component to migrate from the legacy presentation layer.` }),
      node('a', { href: routeUrl('overview'), text: 'Return to operational overview →', onClick: event => { event.preventDefault(); navigate('overview'); } })
    ])));
  }

  function openCommand() {
    let dialog = document.querySelector('#ps-command-dialog');
    if (!dialog) {
      const input = node('input', { type: 'search', placeholder: 'Go to a workspace…', 'aria-label': 'Filter application commands' });
      const results = node('nav', { 'aria-label': 'Application commands' });
      const populate = term => {
        results.replaceChildren();
        routes.filter(([, label]) => label.toLowerCase().includes(term.toLowerCase())).forEach(([slug, label], index) => {
          const link = node('a', { href: routeUrl(slug) }, [node('strong', { text: label }), node('span', { text: `0${index + 1}` })]);
          link.addEventListener('click', event => { event.preventDefault(); dialog.close(); navigate(slug); });
          results.append(link);
        });
      };
      input.addEventListener('input', () => populate(input.value));
      dialog = node('dialog', { id: 'ps-command-dialog', className: 'ps-command-dialog' }, [
        node('div', { className: 'ps-command-dialog__head' }, [input, node('button', { type: 'button', 'aria-label': 'Close command launcher', text: '×', onClick: () => dialog.close() })]), results
      ]);
      document.body.append(dialog);
      populate('');
      dialog.addEventListener('close', () => input.value = '');
    }
    dialog.showModal();
    dialog.querySelector('input').focus();
  }

  function showError(message) {
    const workspace = document.querySelector('#ps-workspace');
    workspace.prepend(node('div', { className: 'ps-error', role: 'alert', text: message }));
  }

  const formatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
  const quantityFormatter = new Intl.NumberFormat(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  function formatInteger(value) { return formatter.format(Number(value || 0)); }
  function formatQuantity(value) { return quantityFormatter.format(Number(value || 0)); }
  function money(minor, currency = 'USD') { return `${currency} ${new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(minor || 0) / 100)}`; }
  function titleCase(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
  function compactId(value) { const id = String(value || ''); return id ? (id.length > 15 ? `${id.slice(0, 12)}…` : id) : '—'; }
  function mysqlDate(value) { return String(value || '').replace(' ', 'T') + 'Z'; }
  function formatDate(value) {
    const date = new Date(`${String(value || '')}T00:00:00Z`);
    return Number.isNaN(date.getTime()) ? String(value || '—') : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(date);
  }
  function formatTime(value) {
    const date = new Date(mysqlDate(value));
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
  }

  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openCommand(); }
  });
  window.addEventListener('popstate', () => {
    state.route = routeFromLocation();
    const view = new URLSearchParams(window.location.search).get('view');
    state.inventoryView = inventoryViews.some(([slug]) => slug === view) ? view : 'catalogue';
    state.inventorySearch = new URLSearchParams(window.location.search).get('q') || '';
    renderFrame(); renderRoute();
  });

  applyTheme(config.theme);
  if (mount) { renderFrame(); renderRoute(); }
})();
