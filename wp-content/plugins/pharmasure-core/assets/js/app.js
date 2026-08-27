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
    posData: null,
    posCart: [],
    posSearch: '',
    posResults: [],
    posDiscountMinor: 0,
    posDiscountReason: '',
    posHoldId: 0,
    posTenders: [{ method: 'cash', amount_minor: 0, external_reference: '' }],
    clinicalData: null,
    clinicalSearch: '',
    clinicalFilter: 'attention',
    clinicalPatient: null,
    clinicalPrescription: null,
    claimsData: null,
    claimsFilter: '',
    claimsSearch: '',
    claimsSelection: null,
	reportsData: null,
	reportType: 'sales',
	reportFrom: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
	reportTo: new Date().toISOString().slice(0, 10),
	accountsData: null,
	accountSelection: null,
	offlineData: null,
	offlineView: 'queue',
	offlineStatus: '',
	offlineSelection: null,
	offlineDetail: null,
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
      button.firstElementChild.textContent = isLight ? 'D' : 'L';
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
        if (state.route === 'pos') { resetPosTransaction(); await renderPos(); }
        if (state.route === 'clinical') { state.clinicalPatient = null; state.clinicalPrescription = null; await renderClinical(); }
		if (state.route === 'claims') { state.claimsSelection = null; await renderClaims(); }
		if (state.route === 'reports') await renderReports();
		if (state.route === 'account') { state.accountSelection = null; await renderAccounts(); }
		if (state.route === 'offline') { state.offlineSelection = null; state.offlineDetail = null; await renderOffline(); }
      } catch (error) {
        showError(error.message);
      } finally {
        branchSelect.disabled = false;
      }
    });
    const logout = node('a', {
      className: 'ps-logout',
      href: config.logoutUrl || '/wp-login.php?action=logout',
      title: 'End this secure session',
      'aria-label': 'Log out of PharmaSure',
      onClick: event => {
        if (state.posCart.length && !window.confirm('The current sale has not been completed. Log out and discard it?')) event.preventDefault();
      }
    }, [node('span', { 'aria-hidden': 'true', text: 'Exit' }), node('strong', { text: 'Log out' })]);
    const tools = node('div', { className: 'ps-global-tools' }, [
      renderThemeToggle(), command,
      node('div', { className: 'ps-branch-control' }, [branchLabel, branchSelect]),
      node('span', { className: 'ps-role', text: config.currentUser?.role || 'staff', title: config.currentUser?.displayName || 'Current user' }),
      logout
    ]);
    return node('header', { className: 'ps-global-header' }, [brand, nav, tools]);
  }

  function renderFrame() {
	window.scrollTo(0, 0);
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
    else if (state.route === 'pos') renderPos();
    else if (state.route === 'clinical') renderClinical();
    else if (state.route === 'claims') renderClaims();
	else if (state.route === 'reports') renderReports();
	else if (state.route === 'account') renderAccounts();
	else if (state.route === 'offline') renderOffline();
    else if (state.route === 'inventory') renderInventory(state.inventoryView, state.inventorySearch);
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

  async function renderPos() {
    const workspace = document.querySelector('#ps-workspace');
    workspace.className = 'ps-workspace ps-pos-workspace';
    workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(posSkeleton());
    try {
      state.posData = await api('pos/workspace');
      if (!state.posResults.length) state.posResults = state.posData.products || [];
      workspace.replaceChildren(posDashboard());
    } catch (error) {
      workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message }));
    } finally {
      workspace.setAttribute('aria-busy', 'false');
    }
  }

  function posSkeleton() {
    return node('div', {}, [
      node('div', { className: 'ps-pos-title ps-skeleton', style: 'height:82px' }),
      node('div', { className: 'ps-pos-metrics ps-skeleton', style: 'height:76px' }),
      node('div', { className: 'ps-pos-grid ps-skeleton', style: 'height:540px' })
    ]);
  }

  function posDashboard() {
    const data = state.posData;
    const session = data.session;
    const headerActions = node('div', { className: 'ps-pos-title__actions' });
    if (session) {
      headerActions.append(
        node('span', { className: 'ps-pos-session' }, [node('i', { 'aria-hidden': 'true' }), node('span', { text: `${session.till_name} / OPEN` }), node('small', { text: `Since ${formatTime(session.opened_at)}` })]),
        node('button', { className: 'ps-operation-button', type: 'button', text: 'Close till', onClick: openCloseTillDialog })
      );
    } else {
      headerActions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Open till session', onClick: openTillDialog }));
    }
    const title = node('section', { className: 'ps-pos-title' }, [
      node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Counter operations / live sale' }), node('h1', { text: 'Point of Sale' }), node('p', { text: `${data.scope.trading_name} — ${data.scope.branch_name}. Fast, auditable dispensing for non-prescription sales.` })]), headerActions
    ]);
    const metrics = node('section', { className: 'ps-pos-metrics', 'aria-label': 'Today at this branch' }, [
      posMetric('Transactions today', formatInteger(data.metrics.transaction_count), 'Completed and exception activity'),
      posMetric('Gross sales', money(data.metrics.gross_minor, data.scope.currency), 'Before refunds and reversals'),
      posMetric('Refunds', money(data.metrics.refund_minor, data.scope.currency), 'Recorded today', Number(data.metrics.refund_minor) > 0 ? 'warning' : ''),
      posMetric('Net sales', money(data.metrics.net_minor, data.scope.currency), 'Live branch position', 'healthy')
    ]);
    const selling = node('section', { className: 'ps-pos-grid', 'aria-label': 'Active sale workspace' }, [posProductPane(), posCartPane(), posTenderPane()]);
    return node('div', {}, [title, metrics, session ? selling : posNoSession(), posActivity()]);
  }

  function posMetric(label, value, note, tone = '') {
    return node('div', { className: `ps-pos-metric${tone ? ` is-${tone}` : ''}` }, [node('span', { text: label }), node('strong', { text: value }), node('small', { text: note })]);
  }

  function posNoSession() {
    return node('section', { className: 'ps-panel ps-pos-locked' }, [
      node('span', { className: 'ps-eyebrow', text: 'Till session required' }), node('h2', { text: 'The selling surface is safely locked' }),
      node('p', { text: 'Open an available till with a verified cash float before scanning medicines or accepting payment.' }),
      node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Open till session', onClick: openTillDialog })
    ]);
  }

  function posProductPane() {
    const pane = node('section', { className: 'ps-panel ps-pos-products', 'aria-labelledby': 'ps-pos-products-title' });
    const input = node('input', { id: 'ps-pos-search', type: 'search', value: state.posSearch, autocomplete: 'off', placeholder: 'Scan barcode or search name / SKU', 'aria-label': 'Find a medicine' });
    const search = async () => {
      state.posSearch = input.value.trim();
      try {
        const result = state.posSearch ? await api(`pos/products?q=${encodeURIComponent(state.posSearch)}`) : { data: state.posData.products || [] };
        state.posResults = result.data || [];
        document.querySelector('.ps-pos-product-list')?.replaceWith(posProductList());
      } catch (error) { showError(error.message); }
    };
    input.addEventListener('input', debounce(search, 180));
    input.addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); const first = state.posResults.find(product => !Number(product.requires_prescription) && Number(product.quantity_available) > 0); if (first) addPosItem(first); }
    });
    pane.append(
      node('header', { className: 'ps-pos-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '01 / Product finder' }), node('h2', { id: 'ps-pos-products-title', text: 'Medicine lookup' })]), node('kbd', { text: 'F2' })]),
      node('div', { className: 'ps-pos-search' }, [node('span', { 'aria-hidden': 'true', text: '/' }), input]), posProductList()
    );
    return pane;
  }

  function posProductList() {
    const list = node('div', { className: 'ps-pos-product-list', role: 'list', 'aria-label': 'Medicine search results' });
    if (!state.posResults.length) return node('div', { className: 'ps-pos-product-list' }, node('div', { className: 'ps-empty' }, [node('strong', { text: 'No medicine found' }), node('span', { text: 'Search by exact barcode, SKU, generic or medicine name.' })]));
    state.posResults.forEach(product => {
      const rx = Number(product.requires_prescription) === 1;
      const available = Number(product.quantity_available || 0);
      const button = node('button', { className: `ps-pos-product${rx ? ' is-rx' : ''}`, type: 'button', disabled: rx || available <= 0 ? 'disabled' : null, 'aria-label': rx ? `${product.name}, prescription workflow required` : `Add ${product.name} to sale`, onClick: () => addPosItem(product) }, [
        node('span', { className: 'ps-pos-product__identity' }, [node('strong', { text: product.name }), node('small', { text: [product.generic_name, product.strength].filter(Boolean).join(' · ') || product.sku }), node('code', { text: product.sku })]),
        node('span', { className: 'ps-pos-product__stock' }, [node('strong', { text: money(product.selling_price_minor, state.posData.scope.currency) }), node('small', { text: rx ? 'RX / CLINICAL' : `${formatQuantity(available)} available` })])
      ]);
      list.append(node('div', { role: 'listitem' }, button));
    });
    return list;
  }

  function addPosItem(product) {
    if (Number(product.requires_prescription)) { announce('Prescription-only medicines must be dispensed through Clinical.'); return; }
    const existing = state.posCart.find(item => Number(item.id) === Number(product.id));
    if (existing) existing.quantity = Math.min(Number(product.quantity_available), existing.quantity + 1);
    else state.posCart.push({ ...product, quantity: 1 });
    syncPosTender(); refreshPosSurface(); announce(`${product.name} added to the active sale.`);
  }

  function updatePosQuantity(id, delta) {
    const item = state.posCart.find(row => Number(row.id) === Number(id));
    if (!item) return;
    item.quantity = Math.min(Number(item.quantity_available), Number(item.quantity) + delta);
    if (item.quantity <= 0) state.posCart = state.posCart.filter(row => Number(row.id) !== Number(id));
    syncPosTender(); refreshPosSurface();
  }

  function posCartPane() {
    const pane = node('section', { className: 'ps-panel ps-pos-cart', 'aria-labelledby': 'ps-pos-cart-title' });
    const count = state.posCart.reduce((sum, item) => sum + Number(item.quantity), 0);
    pane.append(node('header', { className: 'ps-pos-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '02 / Active sale' }), node('h2', { id: 'ps-pos-cart-title', text: 'Transaction cart' })]), node('span', { className: 'ps-record-count', text: `${formatQuantity(count)} units` })]));
    const lines = node('div', { className: 'ps-pos-cart-lines' });
    if (!state.posCart.length) lines.append(node('div', { className: 'ps-pos-cart-empty' }, [node('span', { 'aria-hidden': 'true', text: '+' }), node('strong', { text: 'Ready for the first item' }), node('small', { text: 'Scan a barcode or select a medicine from the finder.' })]));
    state.posCart.forEach(item => lines.append(posCartLine(item)));
    pane.append(lines);
    return pane;
  }

  function posCartLine(item) {
    return node('article', { className: 'ps-pos-cart-line' }, [
      node('div', { className: 'ps-pos-cart-line__identity' }, [node('strong', { text: item.name || item.description }), node('code', { text: item.sku || `MED-${item.id}` }), node('small', { text: `${money(item.selling_price_minor || item.unit_price_minor, state.posData.scope.currency)} / unit` })]),
      node('div', { className: 'ps-pos-stepper', 'aria-label': `Quantity for ${item.name || item.description}` }, [
        node('button', { type: 'button', text: '−', 'aria-label': `Remove one ${item.name || item.description}`, onClick: () => updatePosQuantity(item.id, -1) }),
        node('output', { text: formatQuantity(item.quantity) }),
        node('button', { type: 'button', text: '+', 'aria-label': `Add one ${item.name || item.description}`, onClick: () => updatePosQuantity(item.id, 1) })
      ]),
      node('strong', { className: 'ps-pos-line-total', text: money(Number(item.quantity) * Number(item.selling_price_minor || item.unit_price_minor), state.posData.scope.currency) }),
      node('button', { className: 'ps-pos-remove', type: 'button', text: 'Remove', onClick: () => { state.posCart = state.posCart.filter(row => Number(row.id) !== Number(item.id)); syncPosTender(); refreshPosSurface(); } })
    ]);
  }

  function posTotals() {
    const subtotal = state.posCart.reduce((sum, item) => sum + Math.round(Number(item.quantity) * Number(item.selling_price_minor || item.unit_price_minor)), 0);
    const maximumDiscount = Math.floor(subtotal * Number(state.posData.pricing.max_discount_bps || 0) / 10000);
    const discount = Math.min(subtotal, maximumDiscount, Math.max(0, Number(state.posDiscountMinor || 0)));
    const tax = Math.round((subtotal - discount) * Number(state.posData.pricing.tax_rate_bps || 0) / 10000);
    return { subtotal, discount, tax, total: subtotal - discount + tax };
  }

  function posTenderPane() {
    const totals = posTotals();
    const pane = node('aside', { className: 'ps-panel ps-pos-tender', 'aria-labelledby': 'ps-pos-tender-title' });
    const totalsList = node('dl', { className: 'ps-pos-totals' }, [
      posTotalRow('Subtotal', totals.subtotal), posTotalRow('Discount', -totals.discount), posTotalRow(`Tax (${(Number(state.posData.pricing.tax_rate_bps) / 100).toFixed(2)}%)`, totals.tax), posTotalRow('Amount due', totals.total, true)
    ]);
    const tenders = node('div', { className: 'ps-pos-tenders' });
    state.posTenders.forEach((tender, index) => tenders.append(posTenderRow(tender, index)));
    const paid = state.posTenders.reduce((sum, tender) => sum + Number(tender.amount_minor || 0), 0);
    const difference = totals.total - paid;
    const checkout = node('button', { className: 'ps-pos-checkout', type: 'button', disabled: !state.posCart.length || difference !== 0 ? 'disabled' : null, onClick: checkoutPos }, [node('span', { text: 'Complete sale' }), node('kbd', { text: 'Ctrl Enter' }), node('strong', { text: money(totals.total, state.posData.scope.currency) })]);
    const actions = node('div', { className: 'ps-pos-secondary-actions' }, [
      node('button', { type: 'button', text: 'Hold sale', disabled: !state.posCart.length ? 'disabled' : null, onClick: holdPosSale }),
      node('button', { type: 'button', text: 'Clear', disabled: !state.posCart.length ? 'disabled' : null, onClick: () => { resetPosTransaction(); refreshPosSurface(); } })
    ]);
    pane.append(
      node('header', { className: 'ps-pos-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '03 / Settlement' }), node('h2', { id: 'ps-pos-tender-title', text: 'Tender & total' })])]), totalsList,
      posDiscountControl(), node('div', { className: 'ps-pos-tender-head' }, [node('strong', { text: 'Payment split' }), node('button', { type: 'button', text: '+ Add tender', onClick: () => { state.posTenders.push({ method: 'card', amount_minor: Math.max(0, difference), external_reference: '' }); refreshPosSurface(); } })]), tenders,
      node('div', { className: `ps-pos-balance${difference !== 0 ? ' is-open' : ''}` }, [node('span', { text: difference >= 0 ? 'Remaining' : 'Over tendered' }), node('strong', { text: money(Math.abs(difference), state.posData.scope.currency) })]), checkout, actions,
      node('p', { className: 'ps-pos-authority', text: 'Final pricing, stock allocation and receipt sequence are verified server-side before commit.' })
    );
    return pane;
  }

  function posTotalRow(label, amount, grand = false) {
    return node('div', { className: grand ? 'is-grand' : '' }, [node('dt', { text: label }), node('dd', { text: money(amount, state.posData.scope.currency) })]);
  }

  function posDiscountControl() {
    if (!state.posData.permissions.discount || Number(state.posData.pricing.max_discount_bps) <= 0) return node('div', { className: 'ps-pos-policy', text: 'Discounts are disabled by role or branch policy.' });
    const maximum = Math.floor(posTotals().subtotal * Number(state.posData.pricing.max_discount_bps || 0) / 10000);
    const amount = node('input', { type: 'number', min: '0', max: minorToMajor(maximum), step: '0.01', value: minorToMajor(state.posDiscountMinor), 'aria-label': 'Discount amount' });
    const reason = node('input', { type: 'text', maxlength: '180', value: state.posDiscountReason, placeholder: 'Required reason', 'aria-label': 'Discount reason' });
    amount.addEventListener('change', () => { state.posDiscountMinor = majorToMinor(amount.value); syncPosTender(); refreshPosSurface(); });
    reason.addEventListener('change', () => { state.posDiscountReason = reason.value.trim(); });
    return node('div', { className: 'ps-pos-discount' }, [node('span', { text: `Discount / max ${(Number(state.posData.pricing.max_discount_bps) / 100).toFixed(2)}%` }), amount, reason]);
  }

  function posTenderRow(tender, index) {
    const methods = [['cash', 'Cash'], ['card', 'Card'], ['mobile_money', 'Mobile money'], ['bank_transfer', 'Bank transfer'], ['medical_aid', 'Medical aid']];
    const select = node('select', { 'aria-label': `Payment method ${index + 1}` });
    methods.forEach(([value, label]) => { const option = node('option', { value, text: label }); if (value === tender.method) option.selected = true; select.append(option); });
    const amount = node('input', { type: 'number', min: '0.01', step: '0.01', value: minorToMajor(tender.amount_minor), 'aria-label': `Payment amount ${index + 1}` });
    const reference = node('input', { type: 'text', value: tender.external_reference, placeholder: tender.method === 'cash' ? 'No reference' : 'Provider reference', disabled: tender.method === 'cash' ? 'disabled' : null, 'aria-label': `Payment reference ${index + 1}` });
    select.addEventListener('change', () => { tender.method = select.value; if (tender.method === 'cash') tender.external_reference = ''; refreshPosSurface(); });
    amount.addEventListener('change', () => { tender.amount_minor = majorToMinor(amount.value); refreshPosSurface(); });
    reference.addEventListener('change', () => { tender.external_reference = reference.value.trim(); });
    return node('div', { className: 'ps-pos-tender-row' }, [select, amount, reference, node('button', { type: 'button', text: '×', 'aria-label': `Remove payment ${index + 1}`, disabled: state.posTenders.length === 1 ? 'disabled' : null, onClick: () => { state.posTenders.splice(index, 1); refreshPosSurface(); } })]);
  }

  function syncPosTender() {
    if (state.posTenders.length === 1 && state.posTenders[0].method === 'cash') state.posTenders[0].amount_minor = posTotals().total;
  }

  function refreshPosSurface() {
    const workspace = document.querySelector('#ps-workspace');
    if (workspace && state.route === 'pos' && state.posData) workspace.replaceChildren(posDashboard());
  }

  function resetPosTransaction() {
    state.posCart = []; state.posSearch = ''; state.posResults = [];
    state.posDiscountMinor = 0; state.posDiscountReason = '';
    state.posHoldId = 0;
    state.posTenders = [{ method: 'cash', amount_minor: 0, external_reference: '' }];
  }

  async function checkoutPos() {
    const totals = posTotals();
    if (!state.posData.session || !state.posCart.length) return;
    if (state.posDiscountMinor > 0 && !state.posDiscountReason) { showError('A reason is required before applying a discount.'); return; }
    const invalidReference = state.posTenders.some(tender => tender.method !== 'cash' && !tender.external_reference.trim());
    if (invalidReference) { showError('Each non-cash tender needs its provider reference.'); return; }
    const button = document.querySelector('.ps-pos-checkout'); if (button) button.disabled = true;
    try {
      const result = await api('pos/checkout', { method: 'POST', body: JSON.stringify({
        till_session_id: Number(state.posData.session.id), hold_id: Number(state.posHoldId || 0), idempotency_key: uuid(), items: state.posCart.map(item => ({ drug_id: Number(item.id), quantity: Number(item.quantity) })),
        discount_amount_minor: totals.discount, discount_reason: state.posDiscountReason, payments: state.posTenders.map(tender => ({ method: tender.method, amount_minor: Number(tender.amount_minor), external_reference: tender.external_reference }))
      }) });
      const sale = result.data; resetPosTransaction(); await renderPos(); openReceiptDialog(sale);
    } catch (error) { showError(error.message); if (button) button.disabled = false; }
  }

  async function holdPosSale() {
    if (!state.posData.session || !state.posCart.length) return;
    if (state.posHoldId) {
      const reference = state.posData.holds.find(hold => Number(hold.id) === Number(state.posHoldId))?.reference || 'Held sale';
      resetPosTransaction(); refreshPosSurface(); announce(`${reference} remains safely held.`); return;
    }
    try {
      const result = await api('pos/holds', { method: 'POST', body: JSON.stringify({ till_session_id: Number(state.posData.session.id), items: state.posCart.map(item => ({ drug_id: Number(item.id), quantity: Number(item.quantity) })), discount_amount_minor: state.posDiscountMinor, discount_reason: state.posDiscountReason, notes: 'Held from headless POS' }) });
      resetPosTransaction(); await renderPos(); announce(`Sale ${result.data.reference} is held safely.`);
    } catch (error) { showError(error.message); }
  }

  function posActivity() {
    const section = node('section', { className: 'ps-pos-activity' });
    const holds = node('div', { className: 'ps-panel ps-pos-queue' }, [node('header', { className: 'ps-pos-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Interrupted transactions' }), node('h2', { text: 'Held sales' })]), node('span', { className: 'ps-record-count', text: `${state.posData.holds.length} open` })])]);
    const holdList = node('div', { className: 'ps-pos-activity-list' });
    if (!state.posData.holds.length) holdList.append(node('p', { className: 'ps-pos-empty-row', text: 'No held sales in this branch.' }));
    state.posData.holds.slice(0, 8).forEach(hold => {
      holdList.append(node('article', {}, [
        node('div', {}, [node('strong', { text: hold.reference }), node('small', { text: `${hold.cart?.items?.length || 0} lines · ${formatTime(hold.held_at)}` })]),
        node('div', {}, [node('button', { type: 'button', text: 'Resume', onClick: () => resumePosHold(hold) }), node('button', { type: 'button', text: 'Cancel', onClick: () => cancelPosHold(hold) })])
      ]));
    });
    holds.append(holdList);
    const recent = node('div', { className: 'ps-panel ps-pos-queue' }, [node('header', { className: 'ps-pos-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Branch receipt stream' }), node('h2', { text: 'Recent sales' })]), node('span', { className: 'ps-record-count', text: `${state.posData.recent_sales.length} shown` })])]);
    const sales = node('div', { className: 'ps-pos-activity-list' });
    if (!state.posData.recent_sales.length) sales.append(node('p', { className: 'ps-pos-empty-row', text: 'The first completed sale will appear here.' }));
    state.posData.recent_sales.slice(0, 8).forEach(sale => {
      const actions = node('div', {});
      if (state.posData.permissions.print) actions.append(node('button', { type: 'button', text: 'Receipt', onClick: () => printReceipt(sale.id) }));
      if (state.posData.permissions.refund && ['completed', 'partially_refunded'].includes(sale.status)) actions.append(node('button', { type: 'button', text: 'Refund', onClick: () => openRefundDialog(sale) }));
      if (state.posData.permissions.void && sale.status === 'completed') actions.append(node('button', { type: 'button', text: 'Void', onClick: () => openVoidDialog(sale) }));
      sales.append(node('article', {}, [node('div', {}, [node('strong', { text: sale.receipt_number }), node('small', { text: `${sale.item_count} lines · ${formatTime(sale.created_at)}` })]), node('span', { className: `ps-inventory-status ps-inventory-status--${sale.status === 'completed' ? 'success' : sale.status === 'voided' ? 'danger' : 'warning'}`, text: titleCase(sale.status) }), node('strong', { className: 'ps-number', text: money(sale.total_amount_minor, state.posData.scope.currency) }), actions]));
    });
    recent.append(sales); section.append(holds, recent); return section;
  }

  function resumePosHold(hold) {
    state.posCart = (hold.cart?.items || []).map(item => {
      const product = (state.posData.products || []).find(candidate => Number(candidate.id) === Number(item.drug_id));
      return { ...(product || {}), id: Number(item.drug_id), name: item.description, selling_price_minor: Number(item.unit_price_minor), quantity_available: Number(product?.quantity_available || item.quantity), quantity: Number(item.quantity) };
    });
    state.posDiscountMinor = Number(hold.cart?.discount_amount_minor || 0); state.posDiscountReason = hold.cart?.discount_reason || ''; state.posHoldId = Number(hold.id); syncPosTender(); refreshPosSurface();
    document.querySelector('#ps-pos-cart-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); announce(`${hold.reference} restored to the active cart.`);
  }

  async function cancelPosHold(hold) {
    if (!window.confirm(`Cancel held sale ${hold.reference}? This cannot be resumed afterward.`)) return;
    try { await api(`pos/holds/${hold.id}/cancel`, { method: 'POST', body: '{}' }); await renderPos(); announce(`${hold.reference} cancelled.`); } catch (error) { showError(error.message); }
  }

  function openTillDialog() {
    const available = (state.posData.tills || []).filter(till => !till.session_id);
    const dialog = posDialog('Open till session', 'Verify the physical till and record its opening cash float.');
    const form = node('form', { method: 'dialog', className: 'ps-workflow-form' });
    const select = node('select', { name: 'till_id', required: 'required' }); available.forEach(till => select.append(node('option', { value: till.id, text: `${till.name} / ${till.code}` })));
    form.append(node('div', { className: 'ps-workflow-form__body ps-workflow-grid' }, [node('label', { className: 'ps-workflow-field' }, [node('span', { text: 'Available till' }), select]), workflowField('Opening float', 'opening_float', 'number', '50.00', { min: 0, step: '0.01', className: 'ps-workflow-field--mono' })]), posDialogSubmit('Open secure session'));
    form.addEventListener('submit', async event => { event.preventDefault(); try { await api('pos/sessions/open', { method: 'POST', body: JSON.stringify({ till_id: Number(select.value), opening_float_minor: majorToMinor(form.elements.opening_float.value) }) }); dialog.close(); await renderPos(); announce('Till session opened. Selling controls are active.'); } catch (error) { workflowError(form, error.message); } });
    if (!available.length) form.replaceChildren(node('div', { className: 'ps-workflow-form__body' }, node('div', { className: 'ps-error', role: 'alert', text: 'No available till exists in this branch. A manager must close another session or create a till.' })));
    dialog.append(form); showPosDialog(dialog);
  }

  function openCloseTillDialog() {
    const dialog = posDialog('Close till session', 'Count physical cash. The server compares it with the session ledger and records any variance.');
    const form = node('form', { method: 'dialog', className: 'ps-workflow-form' }, [node('div', { className: 'ps-workflow-form__body' }, workflowField('Counted cash', 'counted_cash', 'number', '', { min: 0, step: '0.01', required: true, className: 'ps-workflow-field--mono' })), posDialogSubmit('Close and reconcile')]);
    form.addEventListener('submit', async event => { event.preventDefault(); try { const result = await api(`pos/sessions/${state.posData.session.id}/close`, { method: 'POST', body: JSON.stringify({ counted_cash_minor: majorToMinor(form.elements.counted_cash.value) }) }); dialog.close(); resetPosTransaction(); await renderPos(); announce(`Till closed. Variance: ${money(result.data.variance_minor, state.posData.scope.currency)}.`); } catch (error) { workflowError(form, error.message); } });
    dialog.append(form); showPosDialog(dialog);
  }

  function openReceiptDialog(sale) {
    const dialog = posDialog('Sale completed', 'Stock, tender and receipt records committed atomically.');
    dialog.append(node('div', { className: 'ps-pos-receipt-result' }, [node('span', { text: 'Receipt number' }), node('strong', { text: sale.receipt_number }), node('small', { text: money(sale.total_amount_minor, state.posData.scope.currency) }), node('div', {}, [node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Print receipt', onClick: () => printReceipt(sale.id) }), node('button', { className: 'ps-operation-button', type: 'button', text: 'New sale', onClick: () => dialog.close() })])]));
    showPosDialog(dialog);
  }

  function openRefundDialog(sale) {
    const dialog = posDialog(`Refund ${sale.receipt_number}`, 'Select exact quantities and choose whether returned stock is safe to restock or must be quarantined.');
    const form = node('form', { method: 'dialog', className: 'ps-workflow-form' });
    const itemFields = node('div', { className: 'ps-pos-refund-items' });
    sale.items.forEach(item => itemFields.append(node('label', {}, [node('span', { text: `${item.description} / sold ${formatQuantity(item.quantity)}` }), node('input', { type: 'number', name: `item_${item.drug_id}`, min: '0', max: item.quantity, step: '0.001', value: '0', 'data-drug-id': item.drug_id })])));
    const disposition = node('select', { name: 'disposition' }, [node('option', { value: 'quarantine', text: 'Quarantine for inspection' }), node('option', { value: 'restock', text: 'Verified safe — restock exact batch' })]);
    form.append(node('div', { className: 'ps-workflow-form__body' }, [itemFields, node('div', { className: 'ps-workflow-grid' }, [workflowField('Reason', 'reason', 'text', '', { required: true }), node('label', { className: 'ps-workflow-field' }, [node('span', { text: 'Stock disposition' }), disposition])])]), posDialogSubmit('Authorize refund'));
    form.addEventListener('submit', async event => { event.preventDefault(); const items = Array.from(form.querySelectorAll('[data-drug-id]')).map(input => ({ drug_id: Number(input.dataset.drugId), quantity: Number(input.value) })).filter(item => item.quantity > 0); if (!items.length) { workflowError(form, 'Select at least one return quantity.'); return; } try { await api(`pos/sales/${sale.id}/refund`, { method: 'POST', body: JSON.stringify({ idempotency_key: uuid(), reason: form.elements.reason.value, disposition: disposition.value, items }) }); dialog.close(); await renderPos(); announce(`${sale.receipt_number} refund recorded.`); } catch (error) { workflowError(form, error.message); } });
    dialog.append(form); showPosDialog(dialog);
  }

  function openVoidDialog(sale) {
    const dialog = posDialog(`Void ${sale.receipt_number}`, 'This creates a full auditable reversal and restores the original eligible batches.');
    const form = node('form', { method: 'dialog', className: 'ps-workflow-form' }, [node('div', { className: 'ps-workflow-form__body' }, workflowField('Reason for void', 'reason', 'text', '', { required: true })), posDialogSubmit('Void full sale', true)]);
    form.addEventListener('submit', async event => { event.preventDefault(); try { await api(`pos/sales/${sale.id}/void`, { method: 'POST', body: JSON.stringify({ reason: form.elements.reason.value }) }); dialog.close(); await renderPos(); announce(`${sale.receipt_number} voided and stock restored.`); } catch (error) { workflowError(form, error.message); } });
    dialog.append(form); showPosDialog(dialog);
  }

  function posDialog(title, description) {
    const dialog = node('dialog', { className: 'ps-workflow-dialog ps-pos-dialog', 'aria-label': title });
    dialog.append(node('header', { className: 'ps-workflow-dialog__head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Point of Sale control' }), node('h2', { text: title }), node('p', { text: description })]), node('button', { className: 'ps-dialog-close', type: 'button', text: 'Close', onClick: () => dialog.close() })]));
    dialog.addEventListener('close', () => dialog.remove()); return dialog;
  }

  function posDialogSubmit(label, danger = false) {
    return node('footer', { className: 'ps-workflow-form__submit' }, node('button', { className: `ps-operation-button ${danger ? 'ps-operation-button--danger' : 'ps-operation-button--primary'}`, type: 'submit', text: label }));
  }

  function showPosDialog(dialog) { document.body.append(dialog); dialog.showModal(); dialog.querySelector('input,select,button')?.focus(); }

  async function printReceipt(saleId) {
    await openPrintJob('sale_receipt', saleId, 'Preparing secure thermal receipt…');
  }

  async function openPrintJob(documentType, entityId, loadingMessage = 'Preparing secure document…') {
    const printWindow = window.open('about:blank', '_blank');
    if (!printWindow) { showError('The receipt window was blocked. Allow pop-ups for PharmaSure, then choose Print receipt again.'); return; }
    printWindow.opener = null;
    printWindow.document.title = 'PharmaSure print preparation';
    printWindow.document.body.style.cssText = 'margin:0;padding:32px;background:#09090b;color:#f8fafc;font:14px Arial,sans-serif';
    printWindow.document.body.textContent = loadingMessage;
    try {
      const result = await api('print/jobs', { method: 'POST', body: JSON.stringify({ document_type: documentType, entity_id: Number(entityId) }) });
      const url = result.data?.print_url || result.print_url;
      if (!url) throw new Error('The server did not return a printable document link.');
      printWindow.location.replace(url);
      announce('Print preview opened. Use the browser print dialog to select the receipt printer.');
    } catch (error) {
      printWindow.close(); showError(error.message);
    }
  }

  function uuid() { return window.crypto?.randomUUID?.() || `ps-${Date.now()}-${Math.random().toString(16).slice(2)}`; }
  function debounce(callback, wait) { let timer; return (...args) => { window.clearTimeout(timer); timer = window.setTimeout(() => callback(...args), wait); }; }

  async function renderClinical() {
    const workspace = document.querySelector('#ps-workspace');
    workspace.className = 'ps-workspace ps-clinical-workspace'; workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(node('div', {}, [node('div', { className: 'ps-clinical-title ps-skeleton', style: 'height:82px' }), node('div', { className: 'ps-clinical-metrics ps-skeleton', style: 'height:76px' }), node('div', { className: 'ps-clinical-grid ps-skeleton', style: 'height:560px' })]));
    try {
      const query = state.clinicalSearch ? `?q=${encodeURIComponent(state.clinicalSearch)}` : '';
      state.clinicalData = await api(`clinical/workspace${query}`);
      if (state.clinicalPrescription) state.clinicalPrescription = state.clinicalData.prescriptions.find(rx => Number(rx.id) === Number(state.clinicalPrescription.id)) || null;
      workspace.replaceChildren(clinicalDashboard());
    } catch (error) { workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message })); }
    finally { workspace.setAttribute('aria-busy', 'false'); }
  }

  function clinicalDashboard() {
    const data = state.clinicalData; const permissions = data.permissions || {};
    const titleActions = node('div', { className: 'ps-clinical-title__actions' });
    if (permissions.manage_patients) titleActions.append(node('button', { className: 'ps-operation-button', type: 'button', text: 'New patient', onClick: openClinicalPatientDialog }));
    if (permissions.manage_prescriptions) titleActions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'New prescription', onClick: () => openClinicalPrescriptionDialog() }));
    const title = node('section', { className: 'ps-clinical-title' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Clinical safety / dispensing control' }), node('h1', { text: 'Clinical workspace' }), node('p', { text: `${data.scope.trading_name} — ${data.scope.branch_name}. Patient-centred review, dispensing and counselling evidence.` })]), titleActions]);
    const metrics = node('section', { className: 'ps-clinical-metrics', 'aria-label': 'Clinical workload summary' }, [
      clinicalMetric('Active patients', data.metrics.active_patients, 'Branch patient register'), clinicalMetric('Awaiting review', data.metrics.pending_review, 'Pharmacist decision queue', Number(data.metrics.pending_review) ? 'warning' : ''),
      clinicalMetric('Ready to dispense', data.metrics.ready_to_dispense, 'Approved prescriptions', Number(data.metrics.ready_to_dispense) ? 'healthy' : ''), clinicalMetric('Dispensed today', data.metrics.dispensed_today, 'Completed with counselling'), clinicalMetric('Controlled attention', data.metrics.controlled_pending, 'Dual-control required', Number(data.metrics.controlled_pending) ? 'critical' : '')
    ]);
    return node('div', {}, [title, metrics, node('section', { className: 'ps-clinical-grid' }, [clinicalPatientRail(), clinicalQueue(), clinicalInspector()]), clinicalRecentDispensings()]);
  }

  function clinicalMetric(label, value, note, tone = '') { return node('div', { className: `ps-clinical-metric${tone ? ` is-${tone}` : ''}` }, [node('span', { text: label }), node('strong', { text: formatInteger(value) }), node('small', { text: note })]); }

  function clinicalPatientRail() {
    const rail = node('section', { className: 'ps-panel ps-clinical-patients', 'aria-labelledby': 'ps-clinical-patients-title' });
    const search = node('input', { id: 'ps-clinical-patient-search', type: 'search', value: state.clinicalSearch, placeholder: 'Patient no., name or phone', 'aria-label': 'Search patients' });
    const run = debounce(async () => { state.clinicalSearch = search.value.trim(); await renderClinical(); document.querySelector('#ps-clinical-patient-search')?.focus(); }, 260); search.addEventListener('input', run);
    const list = node('div', { className: 'ps-clinical-patient-list', role: 'list' });
    if (!state.clinicalData.patients.length) list.append(node('div', { className: 'ps-empty' }, [node('strong', { text: 'No matching patient' }), node('span', { text: 'Create a patient or refine the search.' })]));
    state.clinicalData.patients.forEach(patient => {
      const selected = Number(state.clinicalPatient?.id) === Number(patient.id);
      const button = node('button', { type: 'button', className: `ps-clinical-patient${selected ? ' is-selected' : ''}`, 'aria-pressed': String(selected), onClick: () => selectClinicalPatient(patient) }, [
        node('span', { className: 'ps-clinical-avatar', 'aria-hidden': 'true', text: `${patient.first_name?.[0] || ''}${patient.last_name?.[0] || ''}` }),
        node('span', {}, [node('strong', { text: `${patient.first_name} ${patient.last_name}` }), node('code', { text: patient.patient_number }), node('small', { text: patient.phone || 'No phone recorded' })]),
        patient.allergies ? node('i', { title: 'Allergy information recorded', text: '!' }) : null
      ]); list.append(node('div', { role: 'listitem' }, button));
    });
    rail.append(node('header', { className: 'ps-clinical-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '01 / Patient context' }), node('h2', { id: 'ps-clinical-patients-title', text: 'Patient register' })]), node('kbd', { text: 'F3' })]), node('div', { className: 'ps-clinical-search' }, search), list); return rail;
  }

  async function selectClinicalPatient(patient) {
    try { const result = await api(`clinical/patients/${patient.id}`); state.clinicalPatient = result.data || result; state.clinicalPrescription = null; refreshClinicalSurface(); }
    catch (error) { showError(error.message); }
  }

  function clinicalQueue() {
    const panel = node('section', { className: 'ps-panel ps-clinical-queue', 'aria-labelledby': 'ps-clinical-queue-title' });
    const filters = [['attention', 'Attention'], ['pending_review', 'Review'], ['approved', 'Dispense'], ['draft', 'Drafts'], ['all', 'All']];
    const tabs = node('div', { className: 'ps-clinical-filters', role: 'toolbar', 'aria-label': 'Filter prescription queue' });
    filters.forEach(([value, label]) => tabs.append(node('button', { type: 'button', className: state.clinicalFilter === value ? 'is-active' : '', 'aria-pressed': String(state.clinicalFilter === value), text: label, onClick: () => { state.clinicalFilter = value; refreshClinicalSurface(); } })));
    panel.append(node('header', { className: 'ps-clinical-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '02 / Decision queue' }), node('h2', { id: 'ps-clinical-queue-title', text: 'Prescriptions' })]), node('span', { className: 'ps-record-count', text: `${state.clinicalData.prescriptions.length} records` })]), tabs);
    const list = node('div', { className: 'ps-clinical-rx-list' });
    const rows = state.clinicalData.prescriptions.filter(rx => state.clinicalFilter === 'all' || (state.clinicalFilter === 'attention' ? ['pending_review', 'approved'].includes(rx.status) : rx.status === state.clinicalFilter));
    if (!rows.length) list.append(node('div', { className: 'ps-empty' }, [node('strong', { text: 'Queue is clear' }), node('span', { text: 'No prescriptions match this clinical posture.' })]));
    rows.forEach(rx => list.append(clinicalRxCard(rx))); panel.append(list); return panel;
  }

  function clinicalRxCard(rx) {
    const selected = Number(state.clinicalPrescription?.id) === Number(rx.id); const tone = rx.status === 'approved' ? 'success' : rx.status === 'pending_review' ? 'warning' : rx.status === 'rejected' ? 'danger' : 'neutral';
    const card = node('article', { className: `ps-clinical-rx${selected ? ' is-selected' : ''}` });
    const button = node('button', { className: 'ps-clinical-rx__select', type: 'button', 'aria-pressed': String(selected), onClick: () => { state.clinicalPrescription = rx; state.clinicalPatient = null; refreshClinicalSurface(); } }, [
      node('span', { className: 'ps-clinical-rx__top' }, [node('code', { text: `RX-${String(rx.id).padStart(6, '0')}` }), statusBadge(titleCase(rx.status), tone), Number(rx.controlled_items) ? statusBadge('Controlled', 'danger') : null]),
      node('strong', { text: `${rx.first_name} ${rx.last_name}` }), node('small', { text: `${rx.patient_number} · ${rx.prescriber} · ${formatDate(rx.prescription_date)}` }),
      node('span', { className: 'ps-clinical-rx__items', text: rx.items.map(item => `${item.drug_name} ${item.strength || ''} × ${formatQuantity(item.quantity)}`).join(' / ') })
    ]); card.append(button); return card;
  }

  function clinicalInspector() {
    const panel = node('aside', { className: 'ps-panel ps-clinical-inspector', 'aria-labelledby': 'ps-clinical-inspector-title' });
    if (state.clinicalPrescription) return clinicalPrescriptionInspector(panel, state.clinicalPrescription);
    if (state.clinicalPatient) return clinicalPatientInspector(panel, state.clinicalPatient);
    panel.append(node('span', { className: 'ps-eyebrow', text: '03 / Safety inspector' }), node('h2', { id: 'ps-clinical-inspector-title', text: 'Select a patient or prescription' }), node('p', { text: 'The inspector keeps allergies, conditions, item directions, stock posture and permitted next actions beside the decision.' }), node('div', { className: 'ps-clinical-safety-note' }, [node('strong', { text: 'Clinical responsibility' }), node('span', { text: 'Automated availability signals support—but never replace—pharmacist judgement and documented review.' })])); return panel;
  }

  function clinicalPatientInspector(panel, patient) {
    const alerts = node('dl', { className: 'ps-clinical-facts' }, [clinicalFact('Patient number', patient.patient_number), clinicalFact('Date of birth', patient.date_of_birth ? formatDate(patient.date_of_birth) : 'Not recorded'), clinicalFact('Allergies', patient.allergies || 'None recorded', Boolean(patient.allergies)), clinicalFact('Conditions', patient.medical_conditions || 'None recorded'), clinicalFact('Current medicines', patient.current_medications || 'None recorded'), clinicalFact('Active cover', patient.covers?.length ? `${patient.covers[0].insurer_name} / ${patient.covers[0].scheme_name}` : 'Self-pay / none')]);
    const actions = node('div', { className: 'ps-clinical-inspector-actions' }); if (state.clinicalData.permissions.manage_prescriptions) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Write prescription', onClick: () => openClinicalPrescriptionDialog(patient.id) }));
    panel.append(node('span', { className: 'ps-eyebrow', text: 'Patient safety profile' }), node('h2', { id: 'ps-clinical-inspector-title', text: `${patient.first_name} ${patient.last_name}` }), node('p', { text: 'Clinical context remains branch-scoped and visible during prescribing and review.' }), alerts, actions); return panel;
  }

  function clinicalPrescriptionInspector(panel, rx) {
    const patient = state.clinicalData.patients.find(item => Number(item.id) === Number(rx.patient_id));
    panel.append(node('span', { className: 'ps-eyebrow', text: 'Prescription safety profile' }), node('h2', { id: 'ps-clinical-inspector-title', text: `RX-${String(rx.id).padStart(6, '0')}` }), node('p', { text: `${rx.first_name} ${rx.last_name} · ${rx.prescriber}` }));
    if (patient?.allergies) panel.append(node('div', { className: 'ps-clinical-alert', role: 'note' }, [node('strong', { text: 'Allergy recorded' }), node('span', { text: patient.allergies })]));
    const items = node('div', { className: 'ps-clinical-item-review' });
    rx.items.forEach(item => {
      items.append(node('article', {}, [
        node('div', {}, [node('strong', { text: `${item.drug_name} ${item.strength || ''}` }), node('small', { text: `${item.dose} · ${item.frequency}${item.duration ? ` · ${item.duration}` : ''}` })]),
        node('span', {}, [node('strong', { className: Number(item.quantity_available) < Number(item.quantity) ? 'is-short' : '', text: `${formatQuantity(item.quantity_available)} stock` }), node('small', { text: `${formatQuantity(item.quantity)} prescribed` })])
      ]));
    });
    panel.append(items);
    const actions = node('div', { className: 'ps-clinical-inspector-actions' }); const permissions = state.clinicalData.permissions;
    if (rx.status === 'draft' && permissions.manage_prescriptions) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Submit for review', onClick: () => clinicalTransition(rx, 'submit') }));
    if (rx.status === 'pending_review' && permissions.review) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Review prescription', onClick: () => openClinicalReviewDialog(rx) }));
    if (rx.status === 'approved' && permissions.dispense) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Dispense & counsel', disabled: rx.items.some(item => Number(item.quantity_available) < Number(item.quantity)) ? 'disabled' : null, onClick: () => openClinicalDispenseDialog(rx) }));
    if (rx.status === 'dispensed' && permissions.print) { actions.append(node('button', { className: 'ps-operation-button', type: 'button', text: 'Labels', onClick: () => printClinical('medication_label', rx.id) }), node('button', { className: 'ps-operation-button', type: 'button', text: 'Summary', onClick: () => printClinical('dispensing_summary', rx.id) })); }
    panel.append(actions, node('div', { className: 'ps-clinical-safety-note' }, [node('strong', { text: 'Verified workflow' }), node('span', { text: 'Allergy, interaction, dose, counselling and controlled-drug checks are persisted as auditable clinical evidence.' })])); return panel;
  }

  function clinicalFact(label, value, alert = false) { return node('div', { className: alert ? 'is-alert' : '' }, [node('dt', { text: label }), node('dd', { text: value })]); }
  function refreshClinicalSurface() { const workspace = document.querySelector('#ps-workspace'); if (workspace && state.route === 'clinical' && state.clinicalData) workspace.replaceChildren(clinicalDashboard()); }

  function clinicalRecentDispensings() {
    const panel = node('section', { className: 'ps-panel ps-clinical-recent' }, [node('header', { className: 'ps-clinical-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Completed care' }), node('h2', { text: 'Recent dispensing & claim posture' })]), node('span', { className: 'ps-record-count', text: `${state.clinicalData.recent_dispensings.length} shown` })])]);
    const list = node('div', { className: 'ps-clinical-recent-list' });
    if (!state.clinicalData.recent_dispensings.length) list.append(node('p', { text: 'Completed dispensing will appear here with its claim posture.' }));
    state.clinicalData.recent_dispensings.forEach(sale => { const actions = node('div', {}); if (state.clinicalData.permissions.print) actions.append(node('button', { type: 'button', text: 'Summary', onClick: () => printClinical('dispensing_summary', sale.prescription_id) })); if (!sale.claim && state.clinicalData.permissions.prepare_claims) actions.append(node('button', { type: 'button', text: 'Claim handoff', onClick: () => navigate('claims') })); list.append(node('article', {}, [node('div', {}, [node('strong', { text: sale.receipt_number || `Dispense ${sale.id}` }), node('small', { text: `${sale.first_name} ${sale.last_name} · ${formatTime(sale.created_at)}` })]), statusBadge(sale.claim ? `Claim ${titleCase(sale.claim.status)}` : 'No claim', sale.claim ? 'warning' : 'neutral'), node('strong', { className: 'ps-number', text: money(sale.total_amount_minor, state.clinicalData.scope.currency) }), actions])); }); panel.append(list); return panel;
  }

  function openClinicalPatientDialog() {
    const dialog = clinicalDialog('Register patient', 'Create a branch-scoped patient safety profile before prescription intake.'); const form = node('form', { method: 'dialog', className: 'ps-workflow-form' });
    form.append(node('div', { className: 'ps-workflow-form__body ps-workflow-grid' }, [workflowField('First name', 'first_name', 'text', '', { required: true }), workflowField('Last name', 'last_name', 'text', '', { required: true }), workflowField('Date of birth', 'date_of_birth', 'date'), workflowField('Phone', 'phone', 'tel'), workflowField('Email', 'email', 'email'), workflowField('Address', 'address', 'text', '', { wide: true }), workflowField('Allergies / adverse reactions', 'allergies', 'textarea', '', { wide: true }), workflowField('Medical conditions', 'medical_conditions', 'textarea'), workflowField('Current medications', 'current_medications', 'textarea')]), posDialogSubmit('Create patient'));
    form.addEventListener('submit', async event => { event.preventDefault(); try { const payload = formObject(form); await api('clinical/patients', { method: 'POST', body: JSON.stringify(payload) }); dialog.close(); await renderClinical(); announce('Patient safety profile created.'); } catch (error) { workflowError(form, error.message); } }); dialog.append(form); showPosDialog(dialog);
  }

  function openClinicalPrescriptionDialog(patientId = 0) {
    const dialog = clinicalDialog('Prescription intake', 'Capture the original order. Medicine identity and price are resolved from the tenant catalogue.'); const form = node('form', { method: 'dialog', className: 'ps-workflow-form' });
    const patient = node('select', { name: 'patient_id', required: 'required' }); patient.append(node('option', { value: '', text: 'Select patient', disabled: 'disabled' })); state.clinicalData.patients.forEach(item => { const option = node('option', { value: item.id, text: `${item.patient_number} / ${item.first_name} ${item.last_name}` }); if (Number(item.id) === Number(patientId || state.clinicalPatient?.id)) option.selected = true; patient.append(option); });
    const lines = node('div', { className: 'ps-clinical-rx-lines' }); const add = () => lines.append(clinicalPrescriptionLine(lines)); add();
    form.append(node('div', { className: 'ps-workflow-form__body' }, [node('div', { className: 'ps-workflow-grid' }, [node('label', { className: 'ps-workflow-field' }, [node('span', { text: 'Patient' }), patient]), workflowField('Prescriber', 'prescriber', 'text', '', { required: true }), workflowField('Prescription date', 'prescription_date', 'date', today(), { required: true }), workflowField('Source / notes', 'notes', 'text', '', { wide: true })]), node('section', { className: 'ps-line-editor' }, [node('header', { className: 'ps-line-editor__head' }, [node('div', {}, [node('strong', { text: 'Medicine directions' }), node('small', { text: 'Dose, route, frequency, duration and quantity remain on the immutable order.' })]), node('button', { className: 'ps-operation-button', type: 'button', text: '+ Add medicine', onClick: add })]), lines])]), posDialogSubmit('Save draft prescription'));
    form.addEventListener('submit', async event => { event.preventDefault(); const items = [...lines.querySelectorAll('.ps-clinical-rx-line')].map(line => ({ drug_id: Number(line.querySelector('[name=drug_id]').value), dose: line.querySelector('[name=dose]').value, route: line.querySelector('[name=route]').value, frequency: line.querySelector('[name=frequency]').value, duration: line.querySelector('[name=duration]').value, quantity: Number(line.querySelector('[name=quantity]').value), repeats: Number(line.querySelector('[name=repeats]').value || 0) })); try { const result = await api('clinical/prescriptions', { method: 'POST', body: JSON.stringify({ patient_id: Number(patient.value), prescriber: form.elements.prescriber.value, prescription_date: form.elements.prescription_date.value, notes: form.elements.notes.value, items }) }); dialog.close(); await renderClinical(); state.clinicalPrescription = state.clinicalData.prescriptions.find(rx => Number(rx.id) === Number(result.data.id)); refreshClinicalSurface(); announce('Draft prescription recorded for review.'); } catch (error) { workflowError(form, error.message); } }); dialog.append(form); showPosDialog(dialog);
  }

  function clinicalPrescriptionLine(container) {
    const line = node('div', { className: 'ps-clinical-rx-line' }); const drug = node('select', { name: 'drug_id', required: 'required' }, node('option', { value: '', text: 'Medicine…', disabled: 'disabled', selected: 'selected' })); state.clinicalData.drugs.forEach(item => drug.append(node('option', { value: item.id, text: `${item.name} ${item.strength || ''} / ${formatQuantity(item.quantity_available)} available${Number(item.is_controlled) ? ' / CONTROLLED' : ''}` })));
    line.append(node('label', {}, [node('span', { text: 'Medicine' }), drug]), clinicalLineInput('Dose', 'dose', 'e.g. 1 tablet'), clinicalLineInput('Route', 'route', 'oral'), clinicalLineInput('Frequency', 'frequency', 'twice daily'), clinicalLineInput('Duration', 'duration', '7 days'), clinicalLineInput('Quantity', 'quantity', '1', 'number'), clinicalLineInput('Repeats', 'repeats', '0', 'number'), node('button', { className: 'ps-line-remove', type: 'button', text: 'Remove', onClick: () => { if (container.children.length > 1) line.remove(); } })); return line;
  }
  function clinicalLineInput(label, name, placeholder, type = 'text') { return node('label', {}, [node('span', { text: label }), node('input', { type, name, placeholder, min: type === 'number' ? '0' : null, step: name === 'quantity' ? '0.001' : null, required: ['dose', 'frequency', 'quantity'].includes(name) ? 'required' : null })]); }

  async function clinicalTransition(rx, action) { try { await api(`clinical/prescriptions/${rx.id}/${action}`, { method: 'POST', body: '{}' }); await renderClinical(); announce(`Prescription moved to ${action === 'submit' ? 'pharmacist review' : action}.`); } catch (error) { showError(error.message); } }

  function openClinicalReviewDialog(rx) {
    const dialog = clinicalDialog(`Review RX-${String(rx.id).padStart(6, '0')}`, 'Record explicit clinical checks and a defensible pharmacist decision.');
    const form = node('form', { method: 'dialog', className: 'ps-workflow-form' });
    const checks = node('div', { className: 'ps-clinical-review-checks' }, [workflowCheckbox('Patient allergies and adverse reactions checked', 'allergies_checked'), workflowCheckbox('Interactions and therapeutic duplication checked', 'interactions_checked'), workflowCheckbox('Dose, route, frequency and duration verified', 'dose_checked')]);
    if (Number(rx.controlled_items)) checks.append(workflowCheckbox('Controlled-drug legal requirements verified', 'controlled_drug_attested'));
    const order = node('div', { className: 'ps-clinical-review-order' });
    rx.items.forEach(item => order.append(node('article', {}, [node('strong', { text: `${item.drug_name} ${item.strength || ''}` }), node('span', { text: `${item.dose} · ${item.frequency} · ${formatQuantity(item.quantity)}` }), node('small', { text: `${formatQuantity(item.quantity_available)} available` })])));
    const footer = node('footer', { className: 'ps-workflow-form__submit' }, [node('button', { className: 'ps-operation-button ps-operation-button--danger', type: 'button', text: 'Reject prescription', onClick: async () => submitClinicalReview(form, dialog, rx, 'reject') }), node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'submit', text: 'Approve prescription' })]);
    form.append(node('div', { className: 'ps-workflow-form__body' }, [order, checks, workflowField('Clinical review rationale', 'review_notes', 'textarea', '', { required: true, wide: true })]), footer);
    form.addEventListener('submit', event => { event.preventDefault(); submitClinicalReview(form, dialog, rx, 'approve'); }); dialog.append(form); showPosDialog(dialog);
  }

  async function submitClinicalReview(form, dialog, rx, outcome) { const payload = { outcome, review_notes: form.elements.review_notes.value, allergies_checked: Boolean(form.elements.allergies_checked?.checked), interactions_checked: Boolean(form.elements.interactions_checked?.checked), dose_checked: Boolean(form.elements.dose_checked?.checked), controlled_drug_attested: Boolean(form.elements.controlled_drug_attested?.checked) }; try { await api(`clinical/prescriptions/${rx.id}/review`, { method: 'POST', body: JSON.stringify(payload) }); dialog.close(); await renderClinical(); announce(`Prescription ${outcome === 'approve' ? 'approved for dispensing' : 'rejected with rationale'}.`); } catch (error) { workflowError(form, error.message); } }

  function openClinicalDispenseDialog(rx) {
    const dialog = clinicalDialog(`Dispense RX-${String(rx.id).padStart(6, '0')}`, 'Confirm shelf availability, patient counselling and any controlled-drug dual control before committing stock.'); const form = node('form', { method: 'dialog', className: 'ps-workflow-form' }); const controlled = Number(rx.controlled_items) > 0;
    const itemList = node('div', { className: 'ps-clinical-dispense-items' });
    rx.items.forEach(item => itemList.append(node('article', {}, [node('div', {}, [node('strong', { text: `${item.drug_name} ${item.strength || ''}` }), node('small', { text: `${item.dose} · ${item.frequency}` })]), node('span', { text: `${formatQuantity(item.quantity)} / ${formatQuantity(item.quantity_available)} available` })])));
    const body = node('div', { className: 'ps-workflow-form__body' }, [itemList, workflowCheckbox('Counselling provided and patient understanding confirmed', 'counselling_provided'), workflowField('Counselling notes and key advice', 'counselling_notes', 'textarea', '', { required: true, wide: true })]); if (controlled) body.append(workflowField('Authorized witness user ID', 'controlled_witness_user_id', 'number', '', { required: true, min: 1, mono: true })); form.append(body, posDialogSubmit('Commit dispensing'));
    form.addEventListener('submit', async event => { event.preventDefault(); try { const result = await api(`clinical/prescriptions/${rx.id}/dispense`, { method: 'POST', body: JSON.stringify({ idempotency_key: uuid(), counselling_provided: Boolean(form.elements.counselling_provided.checked), counselling_notes: form.elements.counselling_notes.value, controlled_witness_user_id: Number(form.elements.controlled_witness_user_id?.value || 0) }) }); dialog.close(); await renderClinical(); openClinicalCompletionDialog(result.data); } catch (error) { workflowError(form, error.message); } }); dialog.append(form); showPosDialog(dialog);
  }

  function openClinicalCompletionDialog(result) {
    const dialog = clinicalDialog('Dispensing completed', 'Stock, clinical checks, historical cost and counselling evidence committed atomically.'); const actions = node('div', { className: 'ps-clinical-completion-actions' }, [node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Medication labels', onClick: () => printClinical('medication_label', result.prescription_id) }), node('button', { className: 'ps-operation-button', type: 'button', text: 'Dispensing summary', onClick: () => printClinical('dispensing_summary', result.prescription_id) })]); if (result.claim_ready && state.clinicalData.permissions.prepare_claims) actions.append(node('button', { className: 'ps-operation-button', type: 'button', text: 'Prepare medical-aid claim', onClick: () => prepareClinicalClaim(result, dialog) })); actions.append(node('button', { className: 'ps-operation-button', type: 'button', text: 'Done', onClick: () => dialog.close() })); dialog.append(node('div', { className: 'ps-clinical-completion' }, [node('span', { text: 'Dispensing reference' }), node('strong', { text: result.receipt_number }), node('small', { text: money(result.total_amount_minor, state.clinicalData.scope.currency) }), result.covers?.length ? node('p', { text: `${result.covers[0].insurer_name} / ${result.covers[0].scheme_name} cover is eligible for claim preparation.` }) : node('p', { text: 'No active medical-aid cover is attached to this patient.' }), actions])); showPosDialog(dialog);
  }

  async function prepareClinicalClaim(result, dialog) { const cover = result.covers?.[0]; if (!cover) return; try { const claim = await api('claims', { method: 'POST', body: JSON.stringify({ sale_id: Number(result.sale_id), patient_cover_id: Number(cover.id), idempotency_key: uuid(), service_date: today() }) }); dialog.close(); announce(`Claim ${claim.data.claim_number} prepared for validation.`); navigate('claims'); } catch (error) { showError(error.message); } }
  async function printClinical(type, prescriptionId) { await openPrintJob(type, prescriptionId); }
  function clinicalDialog(title, description) { const dialog = node('dialog', { className: 'ps-workflow-dialog ps-clinical-dialog', 'aria-label': title }); dialog.append(node('header', { className: 'ps-workflow-dialog__head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Clinical workflow' }), node('h2', { text: title }), node('p', { text: description })]), node('button', { className: 'ps-dialog-close', type: 'button', text: 'Close', onClick: () => dialog.close() })])); dialog.addEventListener('close', () => dialog.remove()); return dialog; }
  function formObject(form) { return Object.fromEntries([...new FormData(form).entries()]); }

  async function renderClaims() {
    const workspace = document.querySelector('#ps-workspace'); workspace.className = 'ps-workspace ps-claims-workspace'; workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(node('div', { className: 'ps-skeleton', style: 'height:720px' }));
    try { const query = new URLSearchParams(); if (state.claimsFilter) query.set('status', state.claimsFilter); if (state.claimsSearch) query.set('q', state.claimsSearch); state.claimsData = await api(`claims/workspace?${query}`); if (state.claimsSelection) state.claimsSelection = state.claimsData.claims.find(claim => Number(claim.id) === Number(state.claimsSelection.id)) || null; if (!state.claimsSelection) state.claimsSelection = state.claimsData.claims[0] || null; workspace.replaceChildren(claimsDashboard()); }
    catch (error) { workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message })); } finally { workspace.setAttribute('aria-busy', 'false'); }
  }

  function claimsDashboard() {
    const data = state.claimsData, currency = data.scope.currency, root = node('div', { className: 'ps-claims-root' });
    const search = node('input', { type: 'search', value: state.claimsSearch, placeholder: 'Claim, payer reference, member or patient', 'aria-label': 'Search claims' });
    search.addEventListener('input', debounce(() => { state.claimsSearch = search.value; renderClaims(); }, 300));
    root.append(node('header', { className: 'ps-claims-title' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Revenue cycle / payer control' }), node('h1', { text: 'Claims workspace' }), node('p', { text: `${data.scope.pharmacy} — ${data.scope.branch}. Coverage, submission, adjudication and settlement posture.` })]), node('div', { className: 'ps-claims-title__actions' }, [search, data.permissions.reconcile ? node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Record remittance', onClick: () => openClaimRemittanceDialog() }) : null]) ]));
    const m = data.metrics; root.append(node('section', { className: 'ps-claims-metrics', 'aria-label': 'Claims metrics' }, [claimMetric('Claims', m.total_claims || 0, 'Branch register'), claimMetric('Attention', m.attention || 0, 'Draft, query or rejection', 'warning'), claimMetric('Awaiting payer', m.awaiting_payer || 0, 'Submitted or acknowledged'), claimMetric('Receivable', money(m.receivable_minor || 0, currency), 'Approved and unpaid', 'emerald'), claimMetric('Paid', money(m.paid_minor || 0, currency), `of ${money(m.claimed_minor || 0, currency)} claimed`)]));
    const grid = node('section', { className: 'ps-claims-grid' }, [claimsQueue(), claimsInspector()]); root.append(grid, claimsOperations()); return root;
  }

  function claimMetric(label, value, note, tone = '') { return node('article', { className: `ps-claims-metric ${tone ? `is-${tone}` : ''}` }, [node('span', { text: label }), node('strong', { className: 'ps-number', text: value }), node('small', { text: note })]); }

  function claimsQueue() {
    const panel = node('section', { className: 'ps-panel ps-claims-queue' }); const filters = [['','All'],['draft','Draft'],['validated','Validated'],['ready_for_submission','Ready'],['submitted','Submitted'],['queried','Queried'],['approved','Approved'],['paid','Paid']];
    const tabs = node('div', { className: 'ps-claims-filters', role: 'toolbar', 'aria-label': 'Filter claims' }); filters.forEach(([value,label]) => tabs.append(node('button', { type: 'button', 'aria-pressed': String(state.claimsFilter === value), text: label, onClick: () => { state.claimsFilter = value; state.claimsSelection = null; renderClaims(); } })));
    const list = node('div', { className: 'ps-claims-list' }); if (!state.claimsData.claims.length) list.append(node('p', { className: 'ps-pos-empty-row', text: 'No claims match this branch view.' }));
    state.claimsData.claims.forEach(claim => list.append(node('button', { type: 'button', className: `ps-claim-row ${Number(state.claimsSelection?.id) === Number(claim.id) ? 'is-selected' : ''}`, onClick: () => { state.claimsSelection = claim; document.querySelector('.ps-claims-inspector')?.replaceWith(claimsInspector()); } }, [node('div', {}, [node('strong', { className: 'ps-number', text: claim.claim_number }), node('small', { text: `${claim.first_name} ${claim.last_name} · ${claim.insurer_name}` })]), statusBadge(titleCase(claim.status), claimTone(claim.status)), node('div', { className: 'ps-claim-row__money' }, [node('strong', { className: 'ps-number', text: money(claim.claimed_amount_minor, state.claimsData.scope.currency) }), node('small', { text: claim.payer_reference || claim.member_number })])])));
    panel.append(node('header', { className: 'ps-claims-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '01 / Revenue queue' }), node('h2', { text: 'Claim register' })]), node('span', { className: 'ps-record-count', text: `${state.claimsData.claims.length} shown` })]), tabs, list); return panel;
  }

  function claimTone(status) { if (['paid','approved','validated'].includes(status)) return 'success'; if (['rejected','cancelled','written_off'].includes(status)) return 'danger'; if (['queried','partially_approved','partially_paid','draft'].includes(status)) return 'warning'; return 'neutral'; }

  function claimsInspector() {
    const panel = node('aside', { className: 'ps-panel ps-claims-inspector' }), claim = state.claimsSelection; panel.append(node('span', { className: 'ps-eyebrow', text: '02 / Claim inspector' }));
    if (!claim) { panel.append(node('h2', { text: 'Select a claim' }), node('p', { text: 'Line evidence, coverage, payer history and permitted transitions remain beside the revenue queue.' })); return panel; }
    const currency = state.claimsData.scope.currency; panel.append(node('div', { className: 'ps-claims-inspector__title' }, [node('div', {}, [node('h2', { className: 'ps-number', text: claim.claim_number }), node('p', { text: `${claim.first_name} ${claim.last_name} · ${claim.patient_number}` })]), statusBadge(titleCase(claim.status), claimTone(claim.status))]));
    panel.append(node('section', { className: 'ps-claims-cover' }, [node('strong', { text: `${claim.insurer_name} / ${claim.scheme_name}` }), node('span', { text: `Member ${claim.member_number}` }), node('small', { text: `Cover ${claim.cover_valid_from} → ${claim.cover_valid_to || 'open-ended'}${Number(claim.authorization_required) ? ' · authorization required' : ''}` })]));
    if (claim.rejection_reason) panel.append(node('div', { className: 'ps-claims-alert', role: 'alert' }, [node('strong', { text: claim.rejection_code || 'Payer attention' }), node('span', { text: claim.rejection_reason })]));
    const totals = node('dl', { className: 'ps-claims-totals' }); [['Claimed',claim.claimed_amount_minor],['Approved',claim.approved_amount_minor],['Paid',claim.paid_amount_minor],['Written off',claim.writeoff_amount_minor]].forEach(([label,value]) => totals.append(node('div', {}, [node('dt', { text: label }), node('dd', { className: 'ps-number', text: money(value,currency) })]))); panel.append(totals);
    const lines = node('section', { className: 'ps-claims-lines' }, node('h3', { text: 'Claim lines' })); claim.items.forEach(item => lines.append(node('div', {}, [node('div', {}, [node('strong', { text: item.description }), node('small', { text: `${formatQuantity(item.quantity)} × ${money(item.unit_price_minor,currency)}` })]), node('b', { className: 'ps-number', text: money(item.claimed_amount_minor,currency) })]))); panel.append(lines, claimActions(claim), claimTimeline(claim)); return panel;
  }

  function claimActions(claim) {
    const actions = node('div', { className: 'ps-claims-actions' }), status = claim.status, p = state.claimsData.permissions;
    if (p.prepare && ['draft'].includes(status)) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Validate claim', onClick: () => claimCommand(`claims/${claim.id}/validate`, {}, 'Claim validated.') }));
    if (p.prepare && ['queried','rejected'].includes(status)) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Correct claim', onClick: () => openClaimCorrectionDialog(claim) }));
    if (p.submit && status === 'validated') actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Prepare submission', onClick: () => claimCommand(`claims/${claim.id}/prepare-submission`, {}, 'Submission handoff prepared.') }));
    if (p.submit && status === 'ready_for_submission') actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Record payer submission', onClick: () => openClaimSubmissionDialog(claim) }));
    if (p.submit && ['submitted','acknowledged','queried'].includes(status)) actions.append(node('button', { className: 'ps-operation-button', type: 'button', text: 'Record payer decision', onClick: () => openClaimDecisionDialog(claim) }));
    if (p.reconcile && ['approved','partially_approved','partially_paid'].includes(status)) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--primary', type: 'button', text: 'Allocate payment', onClick: () => openClaimRemittanceDialog(claim) }));
    if (p.reconcile && ['rejected','partially_approved','partially_paid'].includes(status)) actions.append(node('button', { className: 'ps-operation-button ps-operation-button--danger', type: 'button', text: 'Write off balance', onClick: () => openClaimWriteoffDialog(claim) })); return actions;
  }

  function claimTimeline(claim) { const section = node('section', { className: 'ps-claims-timeline' }, node('h3', { text: 'Audit timeline' })); claim.events.forEach(event => section.append(node('div', {}, [node('i', { 'aria-hidden': 'true' }), node('div', {}, [node('strong', { text: titleCase(event.event_type) }), node('small', { text: `${formatTime(event.created_at)}${event.reason ? ` · ${event.reason}` : ''}` })]) ]))); return section; }

  function claimsOperations() {
    const section = node('section', { className: 'ps-claims-operations' });
    const ready = node('div', { className: 'ps-panel' }, [node('header', { className: 'ps-claims-pane-head' }, [node('div', {}, [node('span', { className: 'ps-eyebrow', text: '03 / Coverage verified' }), node('h2', { text: 'Ready to claim' })]), node('span', { className: 'ps-record-count', text: `${state.claimsData.ready_sales.length} sales` })])]);
    if (!state.claimsData.ready_sales.length) ready.append(node('p', { className: 'ps-pos-empty-row', text: 'No covered dispensing is waiting for claim preparation.' }));
    state.claimsData.ready_sales.forEach(sale => ready.append(node('article', { className: 'ps-claims-ready' }, [node('div', {}, [node('strong', { text: sale.receipt_number }), node('small', { text: `${sale.first_name} ${sale.last_name} · ${sale.insurer_name}` })]), node('strong', { className: 'ps-number', text: money(sale.total_amount_minor,state.claimsData.scope.currency) }), node('button', { type: 'button', text: 'Prepare', onClick: () => prepareReadyClaim(sale) })])));
    const payers = node('div', { className: 'ps-panel' }, [node('header', { className: 'ps-claims-pane-head' }, node('div', {}, [node('span', { className: 'ps-eyebrow', text: 'Payer network' }), node('h2', { text: 'Insurers & settlement' })]))]);
    state.claimsData.insurers.forEach(insurer => payers.append(node('article', { className: 'ps-claims-payer' }, [node('div', {}, [node('strong', { text: insurer.name }), node('small', { text: `${insurer.scheme_count} schemes · ${insurer.member_count} active covers` })]), statusBadge(titleCase(insurer.submission_mode), 'neutral')])));
    state.claimsData.remittances.slice(0,5).forEach(remit => payers.append(node('article', { className: 'ps-claims-remit' }, [node('div', {}, [node('strong', { text: remit.reference }), node('small', { text: `${remit.insurer_name} · ${remit.received_date}` })]), node('b', { className: 'ps-number', text: money(remit.total_paid_minor,state.claimsData.scope.currency) })])));
    section.append(ready,payers); return section;
  }

  async function claimCommand(path, payload, message) { try { await api(path, { method:'POST', body:JSON.stringify(payload) }); await renderClaims(); announce(message); } catch(error) { showError(error.message); } }
  async function prepareReadyClaim(sale) { await claimCommand('claims', { sale_id:Number(sale.sale_id), patient_cover_id:Number(sale.cover_id), authorization_number:sale.authorization_number || '', service_date:today(), idempotency_key:uuid() }, `${sale.receipt_number} prepared as a claim.`); }
  function claimFormDialog(title, description, fields, submit, handler) { const dialog=claimsDialog(title,description), form=node('form',{method:'dialog',className:'ps-workflow-form'}); form.append(node('div',{className:'ps-workflow-form__body ps-workflow-grid'},fields),posDialogSubmit(submit)); form.addEventListener('submit',async event=>{event.preventDefault();try{await handler(form);dialog.close();await renderClaims();}catch(error){workflowError(form,error.message);}}); dialog.append(form); showPosDialog(dialog); }
  function claimsDialog(title,description){const dialog=node('dialog',{className:'ps-workflow-dialog ps-clinical-dialog','aria-label':title});dialog.append(node('header',{className:'ps-workflow-dialog__head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'Claims control'}),node('h2',{text:title}),node('p',{text:description})]),node('button',{className:'ps-dialog-close',type:'button',text:'Close',onClick:()=>dialog.close()})]));dialog.addEventListener('close',()=>dialog.remove());return dialog;}
  function openClaimCorrectionDialog(claim) { claimFormDialog(`Correct ${claim.claim_number}`,'Document the payer query or rejection correction before returning the claim to validation.',[workflowField('Correction evidence','reason','textarea','',{required:true,wide:true}),workflowField('Authorization number','authorization_number','text',claim.authorization_number || '',{wide:true})],'Save correction',async form=>{await api(`claims/${claim.id}/correct`,{method:'POST',body:JSON.stringify(formObject(form))});announce('Claim correction recorded.');}); }
  function openClaimSubmissionDialog(claim) { claimFormDialog(`Submit ${claim.claim_number}`,'Record the payer acknowledgement or portal reference. A claim is never marked submitted without this evidence.',[workflowField('Payer submission reference','payer_reference','text','',{required:true,wide:true})],'Mark submitted',async form=>{await api(`claims/${claim.id}/submitted`,{method:'POST',body:JSON.stringify(formObject(form))});announce('Payer submission evidence recorded.');}); }
  function openClaimDecisionDialog(claim) { const status=node('select',{name:'status',required:true},[['acknowledged','Acknowledged'],['approved','Approved in full'],['partially_approved','Partially approved'],['queried','Queried'],['rejected','Rejected'],['cancelled','Cancelled']].map(([value,text])=>node('option',{value,text}))); claimFormDialog(`Payer decision · ${claim.claim_number}`,'Capture the adjudication exactly as received from the insurer.',[node('label',{className:'ps-workflow-field'},[node('span',{text:'Decision'}),status]),workflowField('Approved amount','approved_amount','number',(Number(claim.claimed_amount_minor)/100).toFixed(2),{min:0,step:'0.01',mono:true}),workflowField('Reason / payer message','reason','textarea','',{wide:true}),workflowField('Payer code','code','text','')],'Record decision',async form=>{const values=formObject(form);await api(`claims/${claim.id}/adjudicate`,{method:'POST',body:JSON.stringify({...values,approved_amount_minor:majorToMinor(values.approved_amount)})});announce('Payer decision recorded.');}); }
  function openClaimRemittanceDialog(claim=null) { const choices=(state.claimsData.claims||[]).filter(item=>['approved','partially_approved','partially_paid'].includes(item.status)); if(!choices.length){showError('No approved claim currently has an allocatable balance.');return;} const selected=claim||choices[0], select=node('select',{name:'claim_id',required:true},choices.map(item=>node('option',{value:item.id,text:`${item.claim_number} · ${item.insurer_name}`}))); select.value=String(selected.id); const remaining=Math.max(0,Number(selected.approved_amount_minor)-Number(selected.paid_amount_minor)); claimFormDialog('Record remittance','Allocate payer cash only to an approved claim and preserve the remittance reference.',[node('label',{className:'ps-workflow-field'},[node('span',{text:'Approved claim'}),select]),workflowField('Remittance reference','reference','text','',{required:true}),workflowField('Received date','received_date','date',today(),{required:true}),workflowField('Paid amount','paid_amount','number',(remaining/100).toFixed(2),{required:true,min:.01,step:'.01',mono:true})],'Reconcile payment',async form=>{const values=formObject(form),chosen=choices.find(item=>Number(item.id)===Number(values.claim_id));await api('claims/remittances',{method:'POST',body:JSON.stringify({insurer_id:Number(chosen.insurer_id),reference:values.reference,received_date:values.received_date,idempotency_key:uuid(),items:[{claim_id:Number(values.claim_id),paid_amount_minor:majorToMinor(values.paid_amount)}]})});announce('Remittance allocated and reconciled.');}); }
  function openClaimWriteoffDialog(claim) { const balance=Math.max(0,Number(claim.claimed_amount_minor)-Number(claim.paid_amount_minor)-Number(claim.writeoff_amount_minor)); claimFormDialog(`Write off ${claim.claim_number}`,'This closes an evidenced uncollectible balance and remains visible in audit and margin reporting.',[workflowField('Amount','amount','number',(balance/100).toFixed(2),{required:true,min:.01,max:(balance/100).toFixed(2),step:'.01',mono:true}),workflowField('Write-off reason','reason','textarea','',{required:true,wide:true})],'Authorize write-off',async form=>{const values=formObject(form);await api(`claims/${claim.id}/write-off`,{method:'POST',body:JSON.stringify({amount_minor:majorToMinor(values.amount),reason:values.reason})});announce('Claim balance written off with evidence.');}); }

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
    return node('div', {}, [title, pulse, tabs, inventoryCommandBar(data.view, data.permissions || {}), node('div', { className: 'ps-inventory-body' }, [canvas, inspectorPanel])]);
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
    data.rows.forEach(row => body.append(inventoryRow(data.view, row, data.scope.currency, data.permissions || {})));
    table.append(body);
    return node('div', { className: 'ps-inventory-table-wrap', role: 'region', 'aria-label': `${inventoryMeta[data.view][1]} register`, tabindex: '0' }, table);
  }

  function inventoryRow(view, row, currency, permissions) {
    const tr = node('tr');
    let cells = [];
    if (view === 'catalogue') {
      const control = Number(row.is_controlled) ? ['Controlled', 'danger'] : (Number(row.requires_prescription) ? ['Prescription', 'purple'] : ['OTC', 'neutral']);
      cells = [identity(row.name, row.generic_name || 'Generic name not recorded', row.sku), identity(`${row.strength || ''} ${row.dosage_form || ''}`.trim() || 'Profile incomplete', row.category || 'Uncategorised'), quantityCell(row.quantity_available, row.unit_of_measure), numberCell(row.reorder_level), numberCell(money(row.selling_price_minor, currency)), statusCell(control[0], control[1]), permissions.manage_catalogue ? actionCell('Manage', () => openInventoryWorkflow('medicine', row)) : textCell('Read only')];
    } else if (view === 'batches') {
      cells = [identity(row.batch_number, `${row.name}${row.strength ? ` ${row.strength}` : ''}`, row.sku), textCell(row.supplier_name || 'Not recorded'), textCell(formatDate(row.expiry_date)), quantityCell(row.quantity_available, `of ${formatQuantity(row.quantity_received)} received`), numberCell(money(row.unit_cost_minor, currency)), numberCell(money(row.selling_price_minor, currency)), permissions.manage_stock ? actionCell('Disposition', () => openInventoryWorkflow('batch', row)) : textCell('Read only')];
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
      cells = [identity(row.batch_number, row.name, row.sku), textCell(row.supplier_name || 'Not recorded'), textCell(formatDate(row.expiry_date)), statusCell(days < 0 ? `${Math.abs(days)} days overdue` : `${days} days`, tone), numberCell(formatQuantity(row.quantity_available)), numberCell(money(Number(row.quantity_available) * Number(row.unit_cost_minor), currency)), permissions.manage_stock ? actionCell('Disposition', () => openInventoryWorkflow('batch', row)) : textCell('Read only')];
    } else if (view === 'suppliers') {
      const contact = node('div', { className: 'ps-cell-stack' });
      if (row.email) contact.append(node('a', { href: `mailto:${row.email}`, text: row.email }));
      contact.append(node('small', { text: row.phone || 'Phone not recorded' }));
      cells = [identity(row.name, row.contact_name || 'No contact assigned'), node('td', {}, contact), textCell(row.payment_terms || 'Not recorded'), numberCell(formatInteger(row.receipt_count)), textCell(row.last_receipt ? formatDate(row.last_receipt) : '—'), statusCell(titleCase(row.status), 'success')];
    }
    if (view === 'suppliers') cells.push(permissions.manage_catalogue ? actionCell('Manage', () => openInventoryWorkflow('supplier', row)) : textCell('Read only'));
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

  function inventoryCommandBar(activeView, permissions) {
    const bar = node('section', { className: 'ps-inventory-command-bar', 'aria-label': 'Inventory operations' });
    const intro = node('div', { className: 'ps-inventory-command-bar__intro' }, [
      node('span', { text: 'COMMAND LAYER' }), node('strong', { text: 'Controlled stock operations' })
    ]);
    const actions = node('div', { className: 'ps-inventory-command-bar__actions' });
    const available = [];
    if (permissions.manage_stock) available.push(['receipt', 'Receive stock', 'primary'], ['adjustment', 'Adjust stock', ''], ['transfer', 'Transfer', '']);
    if (permissions.manage_catalogue) available.push(['medicine', 'Add medicine', activeView === 'catalogue' ? 'context' : ''], ['supplier', 'Add supplier', activeView === 'suppliers' ? 'context' : '']);
    available.forEach(([type, label, tone]) => actions.append(node('button', {
      className: `ps-operation-button${tone ? ` ps-operation-button--${tone}` : ''}`,
      type: 'button', text: label, onClick: () => openInventoryWorkflow(type)
    })));
    if (!available.length) actions.append(node('span', { className: 'ps-record-count', text: 'Read-only access' }));
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

  async function renderReports() {
    const workspace = document.querySelector('#ps-workspace'); workspace.className = 'ps-workspace ps-reports-workspace'; workspace.setAttribute('aria-busy', 'true');
    workspace.replaceChildren(node('div', { className: 'ps-skeleton', style: 'height:720px' }));
    try { const query = new URLSearchParams({ type: state.reportType, from: state.reportFrom, to: state.reportTo }); state.reportsData = await api(`reports/workspace?${query}`); workspace.replaceChildren(reportsDashboard()); }
    catch (error) { workspace.replaceChildren(node('div', { className: 'ps-error', role: 'alert', text: error.message })); } finally { workspace.setAttribute('aria-busy', 'false'); }
  }

  function reportsDashboard() {
    const data=state.reportsData, dashboard=data.dashboard, currency=data.scope.currency, root=node('div',{className:'ps-reports-root'});
    const from=node('input',{type:'date',value:state.reportFrom,'aria-label':'Report period start'}),to=node('input',{type:'date',value:state.reportTo,'aria-label':'Report period end'});
    const apply=node('button',{className:'ps-operation-button',type:'button',text:'Apply period',onClick:()=>{state.reportFrom=from.value;state.reportTo=to.value;renderReports();}});
    root.append(node('header',{className:'ps-reports-title'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'Intelligence / operational evidence'}),node('h1',{text:'Reports & analysis'}),node('p',{text:`${data.scope.pharmacy} — ${data.scope.branch}. Branch-scoped performance with tenant-wide audit controls.`})]),node('div',{className:'ps-reports-title__actions'},[node('label',{},[node('span',{text:'From'}),from]),node('label',{},[node('span',{text:'To'}),to]),apply,data.permissions.schedule?node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Schedule delivery',onClick:openReportScheduleDialog}):null]) ]));
    root.append(node('section',{className:'ps-reports-metrics','aria-label':'Operational performance'},[
      reportMetric('Net sales',money(dashboard.sales.net_amount_minor,currency),`${formatInteger(dashboard.sales.transaction_count)} transactions`,'emerald'),
      reportMetric('Inventory at cost',money(dashboard.inventory.cost_value_minor,currency),`${formatQuantity(dashboard.inventory.units_available)} units`),
      reportMetric('Claims outstanding',money(dashboard.claims.outstanding_amount_minor,currency),`${formatInteger(dashboard.claims.exception_count)} exceptions`,Number(dashboard.claims.exception_count)?'warning':''),
      reportMetric('Expiring batches',dashboard.inventory.expiring_batch_count||0,'Within 90 days',Number(dashboard.inventory.expiring_batch_count)?'danger':'')
    ]));
    const tabs=node('nav',{className:'ps-report-tabs','aria-label':'Report views'});[['sales','Sales'],['margin','Historical margin'],['tenders','Tenders'],['inventory','Inventory'],['movements','Stock movements'],['clinical','Dispensing'],['claims','Claims'],['audit','Audit'],['security','Security']].forEach(([slug,label])=>{if(['audit','security'].includes(slug)&&!data.permissions.audit)return;tabs.append(node('button',{type:'button','aria-pressed':String(state.reportType===slug),text:label,onClick:()=>{state.reportType=slug;renderReports();}}));});
    root.append(tabs,node('section',{className:'ps-reports-grid'},[reportTable(),reportInspector()])); return root;
  }
  function reportMetric(label,value,note,tone=''){return node('article',{className:`ps-report-metric ${tone?`is-${tone}`:''}`},[node('span',{text:label}),node('strong',{className:'ps-number',text:value}),node('small',{text:note})]);}
  function reportTable(){const report=state.reportsData.report,currency=state.reportsData.scope.currency,panel=node('section',{className:'ps-panel ps-report-table-panel'});const actions=node('div',{className:'ps-report-export-actions'});if(state.reportsData.permissions.export){['csv','xlsx','pdf'].forEach(format=>actions.append(node('button',{type:'button',text:format.toUpperCase(),onClick:()=>exportReport(format)})));}panel.append(node('header',{className:'ps-report-pane-head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:`01 / ${titleCase(state.reportType)}`}),node('h2',{text:'Evidence register'}),node('p',{text:`${report.rows.length} rows for ${state.reportFrom} → ${state.reportTo}`})]),actions]));if(!report.rows.length){panel.append(node('div',{className:'ps-empty'},[node('strong',{text:'No activity in this period'}),node('span',{text:'Adjust the reporting period or select another analysis view.'})]));return panel;}const table=node('table',{className:'ps-report-table'}),head=node('tr');Object.values(report.columns).forEach(label=>head.append(node('th',{scope:'col',text:String(label).replace(' (minor)','')})));table.append(node('thead',{},head));const body=node('tbody');report.rows.forEach(row=>{const tr=node('tr');Object.keys(report.columns).forEach(key=>tr.append(node('td',{className:reportCellClass(key),text:reportValue(key,row[key],currency)})));body.append(tr);});table.append(body);panel.append(node('div',{className:'ps-table-wrap',role:'region','aria-label':`${titleCase(state.reportType)} report`,tabindex:'0'},table));return panel;}
  function reportCellClass(key){return /(_minor|quantity|count|days|bps|_id$)/.test(key)?'ps-number':'';}
  function reportValue(key,value,currency){if(value===null||value==='')return '—';if(key.endsWith('_minor'))return money(value,currency);if(key==='margin_bps')return `${(Number(value)/100).toFixed(2)}%`;if(/quantity/.test(key))return formatQuantity(value);if(/(^|_)is_resolved$/.test(key))return Number(value)?'Resolved':'Open';if(/status|movement_type|event_type/.test(key))return titleCase(value);return String(value);}
  function reportInspector(){const data=state.reportsData, report=data.report, summary=report.summary||{},aside=node('aside',{className:'ps-panel ps-report-inspector'});aside.append(node('span',{className:'ps-eyebrow',text:'02 / Delivery control'}),node('h2',{text:'Schedules & integrity'}),node('p',{text:'Exports preserve the selected period. Scheduled files remain locked to this pharmacy and working branch.'}));const facts=node('dl',{className:'ps-report-facts'});Object.entries(summary).slice(0,6).forEach(([key,value])=>facts.append(node('div',{},[node('dt',{text:titleCase(key).replace(' Minor','')}),node('dd',{className:'ps-number',text:reportValue(key,value,data.scope.currency)})])));if(Object.keys(summary).length)aside.append(facts);const schedules=node('section',{className:'ps-report-schedules'},node('h3',{text:'Scheduled delivery'}));if(!data.schedules.length)schedules.append(node('p',{text:'No scheduled reports for this branch.'}));data.schedules.slice(0,6).forEach(schedule=>schedules.append(node('article',{},[node('div',{},[node('strong',{text:schedule.name}),node('small',{text:`${titleCase(schedule.frequency)} · ${schedule.format.toUpperCase()} · ${schedule.recipients.length} recipient(s)`})]),node('button',{type:'button',className:schedule.status==='active'?'is-active':'',text:schedule.status==='active'?'Active':'Paused',onClick:()=>toggleReportSchedule(schedule)})])));aside.append(schedules);if(data.runs.length)aside.append(node('div',{className:'ps-report-last-run'},[node('strong',{text:'Latest delivery'}),node('span',{text:`${data.runs[0].name} · ${titleCase(data.runs[0].status)}`}),node('small',{text:data.runs[0].finished_at||data.runs[0].started_at})]));aside.append(node('div',{className:'ps-security-note'},[node('strong',{text:'Immutable financial basis'}),node('span',{text:'Historical margin uses sale-time cost snapshots and refund cost snapshots—not today’s catalogue cost.'})]));return aside;}
  async function exportReport(format){try{const query=new URLSearchParams({format,from:state.reportFrom,to:state.reportTo});const result=await api(`reports/${state.reportType}/export?${query}`),file=result.data,binary=atob(file.content_base64),bytes=new Uint8Array(binary.length);for(let i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);const url=URL.createObjectURL(new Blob([bytes],{type:file.mime_type})),link=node('a',{href:url,download:file.filename});document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);announce(`${format.toUpperCase()} export prepared.`);}catch(error){showError(error.message);}}
  function openReportScheduleDialog(){const dialog=workspaceDialog('Scheduled report delivery','Create a branch-scoped recurring export. Recipient addresses are stored only with this tenant schedule.'),form=node('form',{method:'dialog',className:'ps-workflow-form'});form.append(node('div',{className:'ps-workflow-form__body ps-workflow-grid'},[workflowField('Schedule name','name','text',`${titleCase(state.reportType)} / ${state.reportsData.scope.branch}`,{required:true,wide:true}),workflowSelect('Report','report_type',[['sales','Sales'],['margin','Historical margin'],['tenders','Tenders'],['inventory','Inventory'],['movements','Movements'],['clinical','Dispensing'],['claims','Claims'],...(state.reportsData.permissions.audit?[['audit','Audit'],['security','Security']]:[])],x=>x[0],x=>x[1],{required:true}),workflowSelect('Format','format',[['csv','CSV'],['xlsx','XLSX'],['pdf','PDF']],x=>x[0],x=>x[1],{required:true}),workflowSelect('Frequency','frequency',[['daily','Daily'],['weekly','Weekly'],['monthly','Monthly']],x=>x[0],x=>x[1],{required:true}),workflowField('Recipients','recipients','text','',{required:true,wide:true})]),posDialogSubmit('Create schedule'));form.querySelector('[name=report_type]').value=state.reportType;form.addEventListener('submit',async event=>{event.preventDefault();try{await api('reports/schedules',{method:'POST',body:JSON.stringify({name:form.elements.name.value,report_type:form.elements.report_type.value,format:form.elements.format.value,frequency:form.elements.frequency.value,recipients:form.elements.recipients.value.split(',').map(x=>x.trim()).filter(Boolean)})});dialog.close();await renderReports();announce('Scheduled report delivery created.');}catch(error){workflowError(form,error.message);}});dialog.append(form);showPosDialog(dialog);}
  async function toggleReportSchedule(schedule){try{await api(`reports/schedules/${schedule.id}/status`,{method:'PATCH',body:JSON.stringify({status:schedule.status==='active'?'paused':'active'})});await renderReports();announce(`Schedule ${schedule.status==='active'?'paused':'resumed'}.`);}catch(error){showError(error.message);}}

	async function renderOffline(){
		const workspace=document.querySelector('#ps-workspace');workspace.className='ps-workspace ps-offline-workspace';workspace.setAttribute('aria-busy','true');workspace.replaceChildren(node('div',{className:'ps-skeleton',style:'height:700px'}));
		try{const query=new URLSearchParams();if(state.offlineStatus)query.set('status',state.offlineStatus);state.offlineData=await api(`offline/workspace${query.toString()?`?${query}`:''}`);if(state.offlineSelection&&!state.offlineData.mutations.some(item=>Number(item.id)===Number(state.offlineSelection.id))){state.offlineSelection=null;state.offlineDetail=null;}if(state.offlineView==='queue'&&!state.offlineSelection&&state.offlineData.mutations.length){await selectOfflineMutation(state.offlineData.mutations[0],false);}workspace.replaceChildren(offlineDashboard());}
		catch(error){workspace.replaceChildren(node('div',{className:'ps-error',role:'alert',text:error.message}));}finally{workspace.setAttribute('aria-busy','false');}
	}

	function offlineDashboard(){
		const data=state.offlineData,root=node('div',{className:'ps-offline-root'});
		root.append(node('header',{className:'ps-offline-title'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'Continuity / controlled replay'}),node('h1',{text:'Offline operations'}),node('p',{text:`${data.scope.pharmacy} - ${data.scope.branch}. Signed devices, authoritative replay and conflict review.`})]),node('div',{className:'ps-offline-title__actions'},[data.permissions.manage_devices?node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Register device',onClick:openOfflineDeviceDialog}):null,node('button',{className:'ps-operation-button',type:'button',text:'Refresh',onClick:renderOffline})]) ]));
		root.append(node('section',{className:'ps-offline-metrics','aria-label':'Offline operational posture'},[reportMetric('Awaiting replay',data.metrics.pending,data.oldest_pending_at?`Oldest ${formatTime(data.oldest_pending_at)}`:'Queue clear',data.metrics.pending?'warning':'emerald'),reportMetric('Conflicts',data.metrics.conflicts,'Require manager review',data.metrics.conflicts?'danger':'emerald'),reportMetric('Active devices',data.metrics.active_devices,'Current working branch'),reportMetric('Critical alerts',data.metrics.critical_alerts,'Tenant-wide unresolved',data.metrics.critical_alerts?'danger':'emerald')]));
		const tabs=node('nav',{className:'ps-offline-tabs','aria-label':'Offline operation views'});[['queue','Conflict queue'],['devices','Devices'],['security','Replay & security']].forEach(([slug,label])=>tabs.append(node('button',{type:'button','aria-pressed':String(state.offlineView===slug),text:label,onClick:()=>{state.offlineView=slug;document.querySelector('.ps-offline-body')?.replaceWith(offlineBody());}})));root.append(tabs,offlineBody());return root;
	}

	function offlineBody(){if(state.offlineView==='devices')return offlineDevices();if(state.offlineView==='security')return offlineSecurity();return offlineQueue();}

	function offlineQueue(){
		const body=node('section',{className:'ps-offline-body ps-offline-grid'}),list=node('section',{className:'ps-panel ps-offline-list'}),filters=node('div',{className:'ps-offline-filters'});
		[['','All'],['requires_online_replay','Queued'],['retry','Retry'],['conflict','Conflict'],['applied','Applied'],['discarded','Discarded']].forEach(([status,label])=>filters.append(node('button',{type:'button','aria-pressed':String(state.offlineStatus===status),text:label,onClick:()=>{state.offlineStatus=status;state.offlineSelection=null;state.offlineDetail=null;renderOffline();}})));
		list.append(node('header',{className:'ps-offline-pane-head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'01 / Mutation register'}),node('h2',{text:'Conflict & replay queue'})]),node('span',{className:'ps-record-count',text:`${state.offlineData.mutations.length} records`})]),filters);
		const rows=node('div',{className:'ps-offline-rows'});if(!state.offlineData.mutations.length)rows.append(node('div',{className:'ps-empty'},[node('strong',{text:'No offline mutations in this view'}),node('span',{text:'Signed activity will appear here when a device reconnects.'})]));
		state.offlineData.mutations.forEach(item=>rows.append(node('button',{type:'button',className:Number(state.offlineSelection?.id)===Number(item.id)?'is-selected':'',onClick:()=>selectOfflineMutation(item)},[node('span',{className:`ps-offline-status is-${item.status}`,text:offlineStatusLabel(item.status)}),node('div',{},[node('strong',{text:titleCase(item.mutation_type)}),node('small',{text:`${item.device_name||'Unknown device'} - ${compactId(item.client_mutation_id)}`})]),node('div',{className:'ps-offline-row__meta'},[node('code',{text:`#${item.id}`}),node('small',{text:formatTime(item.received_at)})])])));list.append(rows);body.append(list,offlineMutationInspector());return body;
	}

	async function selectOfflineMutation(item,rerender=true){state.offlineSelection=item;try{const response=await api(`offline/mutations/${item.id}`);state.offlineDetail=response.data;}catch(error){state.offlineDetail=null;showError(error.message);}if(rerender)document.querySelector('.ps-offline-inspector')?.replaceWith(offlineMutationInspector());}

	function offlineMutationInspector(){
		const item=state.offlineDetail||state.offlineSelection,aside=node('aside',{className:'ps-panel ps-offline-inspector'});aside.append(node('span',{className:'ps-eyebrow',text:'02 / Mutation inspector'}));if(!item){aside.append(node('h2',{text:'Select a mutation'}),node('p',{text:'Review payload, device posture and conflict evidence before taking action.'}));return aside;}
		aside.append(node('div',{className:'ps-offline-inspector__head'},[node('div',{},[node('h2',{text:titleCase(item.mutation_type)}),node('p',{text:`Mutation #${item.id} - ${item.device_name||'Unknown device'}`})]),node('span',{className:`ps-offline-status is-${item.status}`,text:offlineStatusLabel(item.status)})]));
		const facts=node('dl',{className:'ps-offline-facts'},[node('div',{},[node('dt',{text:'Client mutation'}),node('dd',{className:'ps-number',text:item.client_mutation_id})]),node('div',{},[node('dt',{text:'Device client'}),node('dd',{className:'ps-number',text:compactId(item.client_id)})]),node('div',{},[node('dt',{text:'Attempts'}),node('dd',{className:'ps-number',text:item.attempt_count||0})]),node('div',{},[node('dt',{text:'Base version'}),node('dd',{className:'ps-number',text:compactId(item.base_version)})]),node('div',{},[node('dt',{text:'Received'}),node('dd',{text:formatTime(item.received_at)})])]);aside.append(facts);
		if(item.conflict_code)aside.append(node('div',{className:'ps-offline-conflict',role:'status'},[node('strong',{text:titleCase(item.conflict_code)}),node('span',{text:item.conflict_details||'Authoritative state differs from the offline base version.'})]));
		if(item.payload!==undefined)aside.append(node('section',{className:'ps-offline-payload'},[node('h3',{text:'Redacted operation payload'}),node('pre',{tabindex:'0',text:JSON.stringify(item.payload,null,2)})]));
		if(state.offlineData.permissions.resolve_conflicts){const actions=node('div',{className:'ps-offline-actions'});if(['requires_online_replay','retry'].includes(item.status))actions.append(node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Replay now',onClick:()=>runOfflineMutationAction(item,'replay')}));if(item.status==='conflict')actions.append(node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Rebase & queue',onClick:()=>openOfflineReasonDialog(item,'rebase')}));if(['requires_online_replay','retry','conflict'].includes(item.status))actions.append(node('button',{className:'ps-operation-button ps-operation-button--danger',type:'button',text:'Safe discard',onClick:()=>openOfflineReasonDialog(item,'discard')}));if(actions.childElementCount)aside.append(actions);}
		aside.append(node('div',{className:'ps-security-note'},[node('strong',{text:'Authoritative writes only'}),node('span',{text:'Offline sales, dispensing, stock and payments are never applied directly. Replay invokes the tenant- and branch-scoped online domain service.'})]));return aside;
	}

	function offlineStatusLabel(status){return ({requires_online_replay:'Queued',retry:'Retry',processing:'Processing',conflict:'Conflict',applied:'Applied',discarded:'Discarded'})[status]||titleCase(status);}

	async function runOfflineMutationAction(item,action,reason=''){try{await api(`offline/mutations/${item.id}/${action}`,{method:'POST',body:JSON.stringify(reason?{reason}:{})});state.offlineSelection=null;state.offlineDetail=null;await renderOffline();announce(action==='replay'?'Replay completed.':`Mutation ${action} completed.`);}catch(error){showError(error.message);}}

	function openOfflineReasonDialog(item,action){const title=action==='discard'?'Safely discard mutation':'Rebase against authoritative state',dialog=workspaceDialog(title,action==='discard'?'This keeps an immutable manager decision without applying the offline write.':'Capture a new authoritative base version and place the mutation back in the replay queue.'),form=node('form',{method:'dialog',className:'ps-workflow-form'});form.append(node('div',{className:'ps-workflow-form__body'},workflowField('Manager reason','reason','text','',{required:true,wide:true})),posDialogSubmit(action==='discard'?'Confirm discard':'Rebase & queue',action==='discard'));form.addEventListener('submit',async event=>{event.preventDefault();try{await runOfflineMutationAction(item,action,form.elements.reason.value);dialog.close();}catch(error){workflowError(form,error.message);}});dialog.append(form);showPosDialog(dialog);}

	function offlineDevices(){
		const body=node('section',{className:'ps-offline-body ps-offline-grid'}),panel=node('section',{className:'ps-panel ps-offline-list'});panel.append(node('header',{className:'ps-offline-pane-head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'01 / Device registry'}),node('h2',{text:'Branch device credentials'})]),state.offlineData.permissions.manage_devices?node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Register device',onClick:openOfflineDeviceDialog}):null]));const rows=node('div',{className:'ps-offline-devices'});if(!state.offlineData.devices.length)rows.append(node('div',{className:'ps-empty'},[node('strong',{text:'No registered offline devices'}),node('span',{text:'Register a trusted branch workstation to provision one-time credentials.'})]));state.offlineData.devices.forEach(device=>rows.append(node('article',{},[node('span',{className:`ps-offline-device-signal is-${device.status}`,'aria-hidden':'true'}),node('div',{},[node('strong',{text:device.device_name}),node('code',{text:device.client_id}),node('small',{text:`Fingerprint ${device.secret_fingerprint} - expires ${formatTime(device.expires_at)}`})]),node('div',{className:'ps-offline-device-counts'},[node('span',{text:`${device.pending_count||0} pending`}),node('span',{text:`${device.conflict_count||0} conflicts`})]),state.offlineData.permissions.manage_devices&&device.status==='active'?node('button',{className:'ps-operation-button ps-operation-button--danger',type:'button',text:'Revoke',onClick:()=>revokeOfflineDevice(device)}):node('span',{className:`ps-offline-status is-${device.status}`,text:titleCase(device.status)})])));panel.append(rows);
		const aside=node('aside',{className:'ps-panel ps-offline-inspector'},[node('span',{className:'ps-eyebrow',text:'02 / Credential posture'}),node('h2',{text:'Scoped device trust'}),node('p',{text:'Every credential is fixed to this authenticated pharmacy and working branch.'}),node('dl',{className:'ps-offline-facts'},[node('div',{},[node('dt',{text:'Active'}),node('dd',{className:'ps-number',text:state.offlineData.metrics.active_devices})]),node('div',{},[node('dt',{text:'Last queue age'}),node('dd',{text:state.offlineData.oldest_pending_at?formatTime(state.offlineData.oldest_pending_at):'Queue clear'})])]),node('div',{className:'ps-security-note'},[node('strong',{text:'Secret handling'}),node('span',{text:'Client secrets are encrypted at rest, displayed once at registration, and never returned in device lists or mutation details.'})])]);body.append(panel,aside);return body;
	}

	function openOfflineDeviceDialog(){const dialog=workspaceDialog('Register offline device',`Provision a credential for ${state.offlineData.scope.branch}. The secret is displayed exactly once.`),form=node('form',{method:'dialog',className:'ps-workflow-form'});form.append(node('div',{className:'ps-workflow-form__body ps-workflow-grid'},[workflowField('Device name','device_name','text','',{required:true,wide:true}),workflowField('Credential life (days)','expires_in_days','number','30',{required:true})]),posDialogSubmit('Create credential'));form.addEventListener('submit',async event=>{event.preventDefault();try{const response=await api('offline/devices',{method:'POST',body:JSON.stringify({device_name:form.elements.device_name.value,expires_in_days:Number(form.elements.expires_in_days.value)})});dialog.close();showOfflineCredential(response.data);await renderOffline();}catch(error){workflowError(form,error.message);}});dialog.append(form);showPosDialog(dialog);}

	function showOfflineCredential(credential){const dialog=workspaceDialog('One-time device credential','Copy both values into the trusted device now. The secret cannot be recovered after this window closes.'),block=node('div',{className:'ps-offline-credential'},[node('label',{},[node('span',{text:'Client ID'}),node('code',{text:credential.client_id})]),node('label',{},[node('span',{text:'Client secret'}),node('code',{text:credential.client_secret})]),node('small',{text:`Expires ${credential.expires_at}`})]),copy=node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Copy credential',onClick:async()=>{try{await navigator.clipboard.writeText(`Client ID: ${credential.client_id}\nClient secret: ${credential.client_secret}`);announce('Device credential copied.');}catch(error){announce('Select and copy the credential values manually.');}}});dialog.append(block,node('footer',{className:'ps-offline-credential__actions'},[copy,node('button',{className:'ps-operation-button',type:'button',text:'I have stored it',onClick:()=>dialog.close()})]));showPosDialog(dialog);}

	async function revokeOfflineDevice(device){if(!window.confirm(`Revoke ${device.device_name}? The device will no longer authenticate.`))return;try{await api(`offline/devices/${device.id}/revoke`,{method:'POST',body:'{}'});await renderOffline();announce('Offline device revoked.');}catch(error){showError(error.message);}}

	function offlineSecurity(){
		const body=node('section',{className:'ps-offline-body ps-offline-grid'}),panel=node('section',{className:'ps-panel ps-offline-list'});panel.append(node('header',{className:'ps-offline-pane-head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'01 / Security event stream'}),node('h2',{text:'Signature & replay alerts'})]),node('span',{className:'ps-record-count',text:`${state.offlineData.security_alerts.length} events`})]));const alerts=node('div',{className:'ps-offline-alerts'});if(!state.offlineData.security_alerts.length)alerts.append(node('div',{className:'ps-empty'},[node('strong',{text:'No offline security alerts'}),node('span',{text:'Repeated signature and nonce failures are escalated here.'})]));state.offlineData.security_alerts.forEach(alert=>alerts.append(node('article',{className:`is-${alert.severity} ${Number(alert.is_resolved)?'is-resolved':''}`},[node('span',{className:'ps-offline-alert-mark','aria-hidden':'true'}),node('div',{},[node('div',{className:'ps-offline-alert-title'},[node('strong',{text:titleCase(alert.event_type)}),node('span',{className:`ps-offline-status is-${alert.severity}`,text:alert.severity})]),node('p',{text:alert.description}),node('small',{text:`${formatTime(alert.created_at)} - ${alert.ip_address||'system'}`})]),state.offlineData.permissions.resolve_conflicts&&!Number(alert.is_resolved)?node('button',{className:'ps-operation-button',type:'button',text:'Resolve',onClick:()=>resolveOfflineAlert(alert)}):node('span',{className:'ps-offline-status is-applied',text:'Resolved'})])));panel.append(alerts);
		const counts=state.offlineData.counts,aside=node('aside',{className:'ps-panel ps-offline-inspector'},[node('span',{className:'ps-eyebrow',text:'02 / Replay monitor'}),node('h2',{text:'Queue state'}),node('p',{text:'Live branch replay posture with tenant-wide signed-request alerts.'}),node('dl',{className:'ps-offline-facts'},[['Queued',counts.requires_online_replay||0],['Retry',counts.retry||0],['Processing',counts.processing||0],['Conflict',counts.conflict||0],['Applied',counts.applied||0],['Discarded',counts.discarded||0]].map(([label,value])=>node('div',{},[node('dt',{text:label}),node('dd',{className:'ps-number',text:value})]))),node('div',{className:'ps-security-note'},[node('strong',{text:'Escalation threshold'}),node('span',{text:'Repeated device authentication, signature and nonce-replay failures escalate from info to warning and critical within a 15-minute window.'})])]);body.append(panel,aside);return body;
	}

	async function resolveOfflineAlert(alert){try{await api(`offline/security-alerts/${alert.id}/resolve`,{method:'POST',body:'{}'});await renderOffline();announce('Security alert resolved and audited.');}catch(error){showError(error.message);}}

  async function renderAccounts(){const workspace=document.querySelector('#ps-workspace');workspace.className='ps-workspace ps-accounts-workspace';workspace.setAttribute('aria-busy','true');workspace.replaceChildren(node('div',{className:'ps-skeleton',style:'height:700px'}));try{state.accountsData=await api('accounts/workspace');if(state.accountSelection)state.accountSelection=state.accountsData.members.find(m=>Number(m.id)===Number(state.accountSelection.id))||null;if(!state.accountSelection)state.accountSelection=state.accountsData.members[0]||null;workspace.replaceChildren(accountsDashboard());}catch(error){workspace.replaceChildren(node('div',{className:'ps-error',role:'alert',text:error.message}));}finally{workspace.setAttribute('aria-busy','false');}}
  function accountsDashboard(){const data=state.accountsData,root=node('div',{className:'ps-accounts-root'}),actions=node('div',{className:'ps-accounts-title__actions'},[node('button',{className:'ps-operation-button',type:'button',text:'Change my password',onClick:openPasswordDialog}),node('button',{className:'ps-operation-button ps-operation-button--primary',type:'button',text:'Create account',onClick:()=>openAccountDialog()})]);root.append(node('header',{className:'ps-accounts-title'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'Identity / least privilege'}),node('h1',{text:'Accounts & access'}),node('p',{text:`${data.scope.pharmacy}. Staff identities, application roles and explicit branch boundaries.`})]),actions]));root.append(node('section',{className:'ps-accounts-metrics'},[reportMetric('Tenant accounts',data.metrics.total,'Visible only in this pharmacy'),reportMetric('Active',data.metrics.active,'Can authenticate','emerald'),reportMetric('Administrators',data.metrics.administrators,'All-branch authority'),reportMetric('Branch restricted',data.metrics.branch_restricted,'Explicit assignments')]));root.append(node('section',{className:'ps-accounts-grid'},[accountList(),accountInspector()]));return root;}
  function accountList(){const panel=node('section',{className:'ps-panel ps-account-list'});panel.append(node('header',{className:'ps-account-pane-head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'01 / Directory'}),node('h2',{text:'Pharmacy team'})]),node('span',{className:'ps-record-count',text:`${state.accountsData.members.length} accounts`})]));const list=node('div',{className:'ps-account-rows'});state.accountsData.members.forEach(member=>list.append(node('button',{type:'button',className:Number(state.accountSelection?.id)===Number(member.id)?'is-selected':'',onClick:()=>{state.accountSelection=member;document.querySelector('.ps-account-inspector')?.replaceWith(accountInspector());}},[node('span',{className:'ps-account-avatar',text:member.display_name.slice(0,2).toUpperCase()}),node('div',{},[node('strong',{text:member.display_name}),node('small',{text:member.user_email}),node('code',{text:member.branches.length?member.branches.map(b=>b.code).join(' · '):member.is_admin?'ALL BRANCHES':'UNASSIGNED'})]),node('span',{className:`ps-account-status ${Number(member.is_active)?'is-active':''}`,text:Number(member.is_active)?titleCase(member.role):'Inactive'})])));panel.append(list);return panel;}
  function accountInspector(){const member=state.accountSelection,panel=node('aside',{className:'ps-panel ps-account-inspector'});panel.append(node('span',{className:'ps-eyebrow',text:'02 / Access inspector'}));if(!member){panel.append(node('h2',{text:'Select an account'}));return panel;}panel.append(node('div',{className:'ps-account-inspector__head'},[node('span',{className:'ps-account-avatar ps-account-avatar--large',text:member.display_name.slice(0,2).toUpperCase()}),node('div',{},[node('h2',{text:member.display_name}),node('p',{text:member.user_email})])]),node('dl',{className:'ps-account-facts'},[node('div',{},[node('dt',{text:'Application role'}),node('dd',{text:titleCase(member.role)})]),node('div',{},[node('dt',{text:'Status'}),node('dd',{text:Number(member.is_active)?'Active':'Inactive'})]),node('div',{},[node('dt',{text:'Branch scope'}),node('dd',{text:member.is_admin?'All tenant branches':member.branches.map(b=>b.name).join(', ')||'None'})]),node('div',{},[node('dt',{text:'Activated'}),node('dd',{text:member.activated_at||'Pending'})])]),node('button',{className:'ps-operation-button',type:'button',text:'Edit role & branch access',onClick:()=>openAccountDialog(member)}),node('div',{className:'ps-security-note'},[node('strong',{text:'No cross-tenant identity reuse'}),node('span',{text:'New account emails cannot be silently attached to another pharmacy. Every membership query is scoped from the authenticated tenant context.'})]));return panel;}
  function openAccountDialog(member=null){const dialog=workspaceDialog(member?'Edit tenant access':'Create tenant account',member?'Change the role, active posture and branch boundary for this pharmacy only.':'Create a new identity that belongs exclusively to the authenticated pharmacy.'),form=node('form',{method:'dialog',className:'ps-workflow-form'}),body=node('div',{className:'ps-workflow-form__body ps-workflow-grid'});if(!member)body.append(workflowField('Display name','display_name','text','',{required:true}),workflowField('Email address','email','email','',{required:true}),workflowField('Temporary password','temporary_password','password','',{required:true,wide:true}));body.append(workflowSelect('Application role','role',state.accountsData.roles.map(x=>[x,titleCase(x)]),x=>x[0],x=>x[1],{required:true}));if(member)body.append(workflowCheckbox('Account is active','is_active',Number(member.is_active)===1));const branches=node('fieldset',{className:'ps-account-branch-picker'},[node('legend',{text:'Branch access'}),node('p',{text:'Owner and manager roles receive all branches. Other roles require explicit assignments.'})]);state.accountsData.branches.forEach(branch=>{const checked=member?.branches.some(item=>Number(item.id)===Number(branch.id));branches.append(node('label',{},[node('input',{type:'checkbox',name:'branch_ids',value:branch.id,checked:checked?'':null}),node('span',{text:`${branch.name} / ${branch.code}`})]));});body.append(branches);form.append(body,posDialogSubmit(member?'Save tenant access':'Create tenant account'));if(member)form.querySelector('[name=role]').value=member.role;form.addEventListener('submit',async event=>{event.preventDefault();const branch_ids=[...form.querySelectorAll('[name=branch_ids]:checked')].map(input=>Number(input.value)),payload={role:form.elements.role.value,branch_ids};if(member)payload.is_active=Boolean(form.elements.is_active.checked);else Object.assign(payload,{display_name:form.elements.display_name.value,email:form.elements.email.value,temporary_password:form.elements.temporary_password.value});try{await api(member?`accounts/${member.id}`:'accounts',{method:member?'PATCH':'POST',body:JSON.stringify(payload)});dialog.close();state.accountSelection=null;await renderAccounts();announce(member?'Tenant access updated.':'Tenant-only account created.');}catch(error){workflowError(form,error.message);}});dialog.append(form);showPosDialog(dialog);}
  function openPasswordDialog(){const dialog=workspaceDialog('Change my password','Replace your temporary or current password without leaving the secure PharmaSure workspace.'),form=node('form',{method:'dialog',className:'ps-workflow-form'}),body=node('div',{className:'ps-workflow-form__body ps-workflow-grid'},[workflowField('Current password','current_password','password','',{required:true,wide:true}),workflowField('New password','new_password','password','',{required:true}),workflowField('Confirm new password','confirm_password','password','',{required:true})]);form.append(body,posDialogSubmit('Change password'));form.addEventListener('submit',async event=>{event.preventDefault();const payload={current_password:form.elements.current_password.value,new_password:form.elements.new_password.value,confirm_password:form.elements.confirm_password.value};try{await api('account/security',{method:'POST',body:JSON.stringify(payload)});form.reset();dialog.close();announce('Password changed successfully.');}catch(error){workflowError(form,error.message);}});dialog.append(form);showPosDialog(dialog);}
  function workspaceDialog(title,description){const dialog=node('dialog',{className:'ps-workflow-dialog ps-workspace-dialog','aria-label':title});dialog.append(node('header',{className:'ps-workflow-dialog__head'},[node('div',{},[node('span',{className:'ps-eyebrow',text:'PharmaSure administration'}),node('h2',{text:title}),node('p',{text:description})]),node('button',{className:'ps-dialog-close',type:'button',text:'Close',onClick:()=>dialog.close()})]));dialog.addEventListener('close',()=>dialog.remove());return dialog;}

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
    if (state.route === 'pos' && event.key === 'F2') { event.preventDefault(); document.querySelector('#ps-pos-search')?.focus(); }
    if (state.route === 'pos' && (event.ctrlKey || event.metaKey) && event.key === 'Enter' && !document.querySelector('dialog[open]')) { const checkout = document.querySelector('.ps-pos-checkout:not(:disabled)'); if (checkout) { event.preventDefault(); checkout.click(); } }
    if (state.route === 'clinical' && event.key === 'F3') { event.preventDefault(); document.querySelector('#ps-clinical-patient-search')?.focus(); }
    if (state.route === 'clinical' && event.altKey && event.key.toLowerCase() === 'n' && state.clinicalData?.permissions?.manage_prescriptions && !document.querySelector('dialog[open]')) { event.preventDefault(); openClinicalPrescriptionDialog(); }
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
