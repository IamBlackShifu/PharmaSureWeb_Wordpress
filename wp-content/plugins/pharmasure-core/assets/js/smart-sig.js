/* Reusable Smart SIG Builder. All clinical parsing and quantities come from the server. */
(() => {
  'use strict';
  let counter = 0;
  function el(tag, text = '', attrs = {}) {
    const e = document.createElement(tag); e.textContent = text;
    Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v)); return e;
  }
  function field(host, label, input) { const wrapper = el('label', '', { class: `ps-workflow-field${input.type === 'checkbox' ? ' ps-sig-checkbox' : ''}` }); wrapper.append(el('span', label), input); host.append(wrapper); return input; }
  function button(text, click) { const b = el('button', text, { type: 'button', class: 'ps-operation-button' }); b.addEventListener('click', click); return b; }
  function select(entries) { const s = el('select'); s.append(el('option', 'Select…', { value: '' })); entries.forEach(([v, label]) => s.append(el('option', label, { value: v }))); return s; }
  const post = (api, path, body) => api(path, { method: 'POST', body: JSON.stringify(body) });

  function setup(host, api, data, complete) {
    const card = el('section', '', {class:'ps-sig-setup'});
    card.append(el('h3', 'Start using Smart SIG'), el('p', 'Set up this pharmacy once. Start with dose, frequency and duration selections; abbreviations and reusable templates can be added later.'));
    const allowed = data.permissions.manage && data.permissions.approve;
    if (!allowed) {
      card.append(el('p', 'A pharmacist or an authorized clinical manager must activate Smart SIG for this pharmacy. You can continue with manual directions while they complete setup.'));
      card.append(button('Check if setup is complete', async () => {
        try { await complete(await api('sig/library')); } catch(e) { message.textContent=e.message; }
      }));
    } else {
      const launch = button('Set up Smart SIG', () => { form.hidden=false; launch.hidden=true; note.focus(); });
      card.append(launch);
      const form=el('div', '', {class:'ps-sig-setup-form'}); form.hidden=true; card.append(form);
      const note=field(form, 'Clinical acceptance note / reference', el('textarea','',{rows:'2',placeholder:'Record your review of the directions, quantities and dispensing workflow.'}));
      const accepted=field(form, 'I have reviewed this workflow and approve Smart SIG use at this pharmacy', el('input','',{type:'checkbox'}));
      const activate=button('Activate and start using Smart SIG', async () => {
        if (!accepted.checked || !note.value.trim()) { message.textContent='Enter your acceptance note and confirm your clinical review.'; return; }
        activate.disabled=true;
        try {
          await post(api,'sig/settings',{enabled:true,personal_shortcuts:Boolean(data.settings.personal_shortcuts),acceptance_note:note.value,clinical_acceptance:true});
          await complete(await api('sig/library'));
        } catch(e) { message.textContent=e.message; activate.disabled=false; }
      }); form.append(activate);
    }
    const message=el('p','',{role:'status','aria-live':'polite'});card.append(message);host.append(card);
    return card;
  }

  function mount(host, { api, drug, quantity, legacyFields = [] }) {
    const root = el('section', '', { class: 'ps-smart-sig' }); host.append(root);
    const enabled = el('input', '', { type: 'checkbox' }); enabled.disabled = true;
    field(root, 'Use Smart SIG Builder', enabled);
    const panel = el('div', '', { class: 'ps-sig-panel' }); panel.hidden = true; root.append(panel);
    const status = el('p', 'Loading approved terminology…', { role: 'status', 'aria-live': 'polite' }); root.append(status);
    let setupCard;
    let library, result, sequence = 0, timer, dirty = true, edited = false, references = [], hits = [], active = -1, errorText = '';
    const fields = {};
    const grid = el('div', '', { class: 'ps-workflow-grid' }); panel.append(grid);
    const more=el('details','',{class:'ps-sig-more'});more.append(el('summary','More instructions and templates'));
    const advanced=el('div','',{class:'ps-workflow-grid'});more.append(advanced);
    fields.original = field(grid, 'Original prescription directions', el('textarea', '', { rows: '2' }));
    fields.sig = field(grid, 'Shorthand SIG (approved codes only)', el('input', '', { type: 'text', autocomplete: 'off', role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false' }));
    const listId = `ps-sig-list-${++counter}`; const suggestions = el('div', '', { id: listId, role: 'listbox', class: 'ps-sig-suggestions' }); grid.append(suggestions); fields.sig.setAttribute('aria-controls', listId);
    fields.dose = field(grid, 'Dose (decimal or fraction)', el('input', '', { type: 'text', placeholder: '1 or 1/2' }));
    fields.unit = field(grid, 'Dosage unit', select(['tablet','capsule','mL','drop','puff','sachet','suppository','application','patch','spray','unit'].map(x => [x,x])));
    fields.route = field(grid, 'Route (must match medicine)', select([]));
    fields.site = field(advanced, 'Prescribed site / eye / ear / nostril', el('input', '', { type: 'text' }));
    fields.frequency = field(grid, 'Frequency', select([]));
    fields.duration_days = field(grid, 'Duration in days (blank if not specified)', el('input', '', { type: 'number', min: '1', max: '3660', step: '1' }));
    fields.timing = field(advanced, 'Timing', select([]));
    fields.max_administrations_per_day = field(advanced, 'Prescribed PRN maximum administrations/day', el('input', '', { type: 'number', min: '1', max: '24' }));
    fields.additional = field(advanced, 'Additional prescribed instructions / custom schedule', el('textarea', '', { rows: '2' }));
    fields.template = field(advanced, 'Approved templates / personal shortcuts', select([]));
    panel.append(more);
    const parse = button('Expand shorthand', () => refresh(true)); panel.append(parse);
    const preview = el('p', '', { class: 'ps-sig-preview', role: 'status', 'aria-live': 'polite' }); panel.append(preview);
    fields.directions = field(panel, 'Patient directions for pharmacist review', el('textarea', '', { rows: '3' }));
    fields.reconciliation = field(panel, 'Reconciliation reason for edited patient directions', el('textarea', '', { rows: '2' }));
    fields.override = field(panel, 'Manual stock quantity / conversion verification reason', el('textarea', '', { rows: '2' }));
    fields.reconciliation.closest('label').hidden=true; fields.override.closest('label').hidden=true;
    const quantityPreview = el('p'); panel.append(quantityPreview);
    const useQuantity = button('Use calculated stock quantity', () => { if (result?.quantity !== null && !result?.manual_reason && !dirty) { quantity.value = result.quantity; quantity.dispatchEvent(new Event('input', { bubbles: true })); } }); useQuantity.disabled = true; panel.append(useQuantity);
    const templateName = field(advanced, 'Save reusable template name', el('input', '', { type: 'text', maxlength: '100' }));
    const shared = el('input', '', { type: 'checkbox' }); const sharedLabel = el('label', 'Save as clinically approved pharmacy template '); sharedLabel.append(shared); advanced.append(sharedLabel);
    const saveTemplate = button('Save template', async () => {
      try { const value = getValue(); await post(api, 'sig/templates', { ...value, drug_id: Number(drug.value), name: templateName.value, shared: shared.checked, clinical_approval: shared.checked }); library = await api('sig/library'); loadTemplates(); status.textContent = 'Template saved. Every use requires prescription-specific review.'; }
      catch (e) { status.textContent = e.message; }
    }); advanced.append(saveTemplate);
    function showReasons() {
      fields.reconciliation.closest('label').hidden=!result || fields.directions.value===result.directions;
      fields.override.closest('label').hidden=!result || (!result.manual_reason && (result.quantity===null || Math.abs(Number(quantity.value)-Number(result.quantity))<=0.0001));
    }
    quantity.addEventListener('input',showReasons);
    const input = () => Object.fromEntries(['dose','unit','route','site','frequency','duration_days','timing','additional','max_administrations_per_day'].map(k => [k, fields[k].value]));
    function invalidate() { dirty = true; result = null; useQuantity.disabled = true; ++sequence; clearTimeout(timer); status.textContent = 'Directions changed; awaiting server validation.'; }
    function schedule() { invalidate(); timer = setTimeout(() => refresh(false), 300); }
    async function refresh(parseSig) {
      if (!enabled.checked) return;
      clearTimeout(timer); const ticket = ++sequence; dirty = true; result = null; useQuantity.disabled = true; status.textContent = 'Validating directions…';
      try {
        const response = await post(api, 'sig/preview', { drug_id: Number(drug.value), original_sig: fields.sig.value, parse_sig: parseSig, structured: { ...input(), definition_versions: references } });
        if (ticket !== sequence || !root.isConnected) return;
        result = response; dirty = false; errorText = ''; references = response.structured.definition_versions || [];
        if (parseSig) { Object.entries(response.structured).forEach(([key, value]) => { if (fields[key]) fields[key].value = value ?? ''; }); edited = false; }
        preview.textContent = response.directions;
        if (!edited) fields.directions.value = response.directions;
        showReasons();
        quantityPreview.textContent = `Regimen quantity: ${response.quantity ?? 'not calculated'} ${response.quantity_unit}. Stock unit: ${response.stock_unit || 'not configured'}. ${response.manual_reason || ''}${response.maximum_theoretical_quantity !== null ? ` PRN maximum theoretical quantity: ${response.maximum_theoretical_quantity} ${response.quantity_unit}; expected consumption is not inferred.` : ''}`;
        useQuantity.disabled = response.quantity === null || Boolean(response.manual_reason);
        status.textContent = fields.directions.value !== response.directions ? 'Patient directions differ from structured dosage. Document reconciliation for pharmacist review.' : response.validation_scope;
      } catch (e) { if (ticket !== sequence) return; errorText = e.message; status.textContent = e.message; preview.textContent = ''; quantityPreview.textContent = ''; }
    }
    function choose(index) {
      const hit = hits[index]; if (!hit) return;
      fields.sig.value = fields.sig.value.replace(/\S*$/, hit.code) + ' '; suggestions.replaceChildren(); fields.sig.setAttribute('aria-expanded', 'false'); active = -1; invalidate(); fields.sig.focus();
      post(api, 'sig/preferences', { definition_id: Number(hit.id) }).then(p => { library.preferences = p; }).catch(() => {});
    }
    function search() {
      invalidate(); const token = fields.sig.value.toUpperCase().split(/\s+/).pop(); suggestions.replaceChildren(); active = -1;
      hits = (library?.definitions || []).filter(d => d.status === 'active' && d.approval_status === 'approved' && !d.ambiguous && (!d.routes?.length || d.routes.includes(fields.route.value)) && (!d.forms?.length || d.forms.includes(selectedDrug()?.dosage_form?.toLowerCase())) && (!token || [d.code, ...d.aliases].some(c => c.startsWith(token)) || d.description.toUpperCase().includes(token)))
        .sort((a,b) => rank(b) - rank(a) || a.code.localeCompare(b.code)).slice(0, 8);
      hits.forEach((d,i) => { const option = el('div', '', { role: 'option', id: `${listId}-${i}`, 'aria-selected': 'false' }); const pick = button(`${d.code} — ${d.description} (${d.category})`, () => choose(i)); pick.tabIndex = -1; option.append(pick, button(library.preferences.favourites.map(Number).includes(Number(d.id)) ? '★ Favourite' : '☆ Favourite', async () => { try { library.preferences = await post(api, 'sig/preferences', { definition_id: Number(d.id), favourite: !library.preferences.favourites.map(Number).includes(Number(d.id)) }); search(); } catch(e) { status.textContent = e.message; } })); suggestions.append(option); });
      fields.sig.setAttribute('aria-expanded', hits.length ? 'true' : 'false'); fields.sig.removeAttribute('aria-activedescendant');
    }
    function rank(d) { const p = library.preferences; return Number(d.priority || 0) + (p.favourites.map(Number).includes(Number(d.id)) ? 1000 : 0) + (p.recent.map(Number).includes(Number(d.id)) ? 100 : 0) + Math.min(50, Number(p.counts[d.id] || 0)); }
    fields.sig.addEventListener('input', search);
    fields.sig.addEventListener('keydown', e => {
      if (['ArrowDown','ArrowUp'].includes(e.key) && hits.length) { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + hits.length) % hits.length; [...suggestions.children].forEach((c,i) => c.setAttribute('aria-selected', String(i === active))); fields.sig.setAttribute('aria-activedescendant', `${listId}-${active}`); }
      if (['Enter','Tab'].includes(e.key) && active >= 0 && suggestions.childElementCount) { e.preventDefault(); choose(active); }
      if (e.key === 'Escape') { suggestions.replaceChildren(); fields.sig.setAttribute('aria-expanded','false'); active = -1; }
    });
    Object.entries(fields).forEach(([key, control]) => { if (['dose','unit','route','site','frequency','duration_days','timing','additional','max_administrations_per_day'].includes(key)) control.addEventListener('input', schedule); });
    fields.directions.addEventListener('input', () => { edited = true; showReasons(); status.textContent = 'Edited patient directions require reconciliation and pharmacist review.'; });
    function selectedDrug() { return (drug._sigProducts || []).find(d => Number(d.id) === Number(drug.value)); }
    function loadTemplates() { fields.template.replaceChildren(el('option','Select…',{value:''})); (library.templates || []).filter(t => t.structured?.dosage_form === selectedDrug()?.dosage_form && (Number(t.owner_id) === 0 || library.settings.personal_shortcuts)).forEach(t => fields.template.append(el('option',t.name,{value:t.id}))); }
    let profileSequence = 0;
    async function loadProfile() {
      const ticket = ++profileSequence; invalidate(); fields.unit.disabled = true; fields.route.disabled = true;
      try {
        const product = await api(`sig/profile?drug_id=${encodeURIComponent(drug.value)}`); if (ticket !== profileSequence || !root.isConnected) return;
        const unit = fields.unit.value, route = fields.route.value;
        fields.unit.replaceChildren(el('option','Select…',{value:''})); product.profile.units.forEach(v => fields.unit.append(el('option',v,{value:v})));
        fields.route.replaceChildren(el('option','Select…',{value:''})); product.profile.routes.forEach(v => fields.route.append(el('option',v,{value:v})));
        if (product.profile.units.includes(unit)) fields.unit.value = unit;
        else if (product.profile.units.length===1) fields.unit.value=product.profile.units[0];
        if (product.profile.routes.includes(route)) fields.route.value = route;
        else if (product.profile.routes.length===1) fields.route.value=product.profile.routes[0];
        fields.unit.disabled = false; fields.route.disabled = false;
        if (!product.profile.routes.length) status.textContent = 'Medication form has no validated compatibility profile. Configure the product profile before using Smart SIG.';
        else if (enabled.checked) schedule();
      } catch(e) { if(ticket === profileSequence) status.textContent = e.message; }
    }
    drug.addEventListener('change', () => { references = []; edited = false; loadTemplates(); loadProfile(); });
    fields.template.addEventListener('change', () => { const t = library.templates.find(t => Number(t.id) === Number(fields.template.value)); if (!t) return; fields.sig.value = ''; references = t.structured.definition_versions || []; Object.entries(t.structured).forEach(([k,v]) => { if (fields[k]) fields[k].value = v ?? ''; }); edited = false; schedule(); });
    enabled.addEventListener('change', () => {
      panel.hidden = !enabled.checked; legacyFields.forEach(control => { control.closest('label').hidden = enabled.checked; control.required = enabled.checked ? false : ['dose','frequency'].includes(control.name); });
      if (enabled.checked) schedule(); else { invalidate(); status.textContent = 'Manual directions entry selected.'; }
    });
    function applyLibrary(data) {
      library = data; enabled.disabled = !data.settings.enabled;
      enabled.closest('label').hidden=!data.settings.enabled;
      if(setupCard) { setupCard.hidden=true; }
      if(!data.settings.enabled) setupCard=setup(root,api,data,async fresh=>{applyLibrary(fresh);if(fresh.settings.enabled){enabled.checked=true;enabled.dispatchEvent(new Event('change'));}});
      const chosenFrequency=fields.frequency.value,chosenTiming=fields.timing.value;
      fields.route.replaceChildren(el('option','Select…',{value:''}));fields.frequency.replaceChildren(el('option','Select…',{value:''}));fields.timing.replaceChildren(el('option','Select…',{value:''}));
      Object.entries(data.routes).forEach(([k]) => fields.route.append(el('option', k, { value:k })));
      Object.entries(data.frequencies).forEach(([k,v]) => fields.frequency.append(el('option', v[0], { value:k })));
      data.timing.forEach(v => fields.timing.append(el('option', v, { value:v })));
      fields.frequency.value=chosenFrequency;fields.timing.value=chosenTiming;
      sharedLabel.hidden = !data.permissions.manage || !data.permissions.approve;
      saveTemplate.hidden = !data.settings.personal_shortcuts && sharedLabel.hidden;
      loadTemplates(); status.textContent = data.settings.enabled ? 'Select a medicine, enter the original directions, then choose dose, frequency and duration. Shorthand is optional.' : '';
      fields.sig.placeholder='Optional: use approved abbreviations, e.g. 2 TDS X 5/7';
      if (drug.value) loadProfile();
    }
    const ready = api('sig/library').then(applyLibrary).catch(e => { status.textContent = e.message; });
    function getValue() {
      if (!enabled.checked) return null;
      if (dirty || !result) throw new Error(errorText || 'Wait for valid Smart SIG directions before saving.');
      if (!fields.original.value.trim()) throw new Error('Enter the original prescription directions.');
      if (!fields.directions.value.trim()) throw new Error('Enter patient directions.');
      if (fields.directions.value !== result.directions && !fields.reconciliation.value.trim()) throw new Error('Reconcile patient directions with structured dosage.');
      if ((result.manual_reason || (result.quantity !== null && Math.abs(Number(quantity.value) - Number(result.quantity)) > 0.0001)) && !fields.override.value.trim()) throw new Error('Document manual quantity verification or stock conversion.');
      return { original_sig: fields.sig.value, parse_sig: false, original_prescription: fields.original.value, structured: result.structured, verified_directions: fields.directions.value, reconciliation_reason: fields.reconciliation.value, quantity_override_reason: fields.override.value };
    }
    async function load(snapshot) {
      await ready; if (!library?.settings.enabled) { status.textContent = 'Smart SIG is disabled. The repeat draft requires manual directions and review.'; return; }
      enabled.checked = true; enabled.dispatchEvent(new Event('change')); await loadProfile();
      references = snapshot.structured.definition_versions || [];
      Object.entries(snapshot.structured).forEach(([k,v]) => { if(fields[k]) fields[k].value = v ?? ''; });
      fields.original.value = snapshot.original_prescription; fields.sig.value = snapshot.original_sig;
      fields.directions.value = snapshot.verified_directions; fields.reconciliation.value = snapshot.reconciliation_reason || ''; fields.override.value = '';
      edited = true; schedule();
    }
    panel.append(button('Rebuild using entered structured fields', () => { references = []; schedule(); }));
    return { value: getValue, load, root };
  }

  async function management(host, api) {
    host.classList.add('ps-sig-management');
    host.replaceChildren(); const status = el('p', '', { role:'status' }); host.append(status);
    try {
      const data = await api('sig/library'); if (!data.permissions.manage) throw new Error('SIG configuration permission is required.');
      if(!data.settings.enabled) setup(host,api,data,async()=>management(host,api));
      const pending = data.definitions.filter(d => ['draft','pending_approval'].includes(d.status));
      host.append(el('p', `${pending.length} terminology ${pending.length === 1 ? 'version awaits' : 'versions await'} review. Select Review, check the code and its meaning, record an approval rationale, and explicitly confirm clinical approval before activating it.`, {class:'ps-sig-approval-guide'}));
      const settingsDetails=el('details','',{class:'ps-sig-more'});settingsDetails.append(el('summary','Pharmacy settings and acceptance record'));host.append(settingsDetails);
      const settings = el('section', '', { class:'ps-workflow-grid' }); settingsDetails.append(settings);
      const enabled = field(settings, 'Enable Smart SIG after clinical acceptance', el('input','',{type:'checkbox'})); enabled.checked = data.settings.enabled;
      const personal = field(settings, 'Allow personal shortcuts', el('input','',{type:'checkbox'})); personal.checked = data.settings.personal_shortcuts;
      const note = field(settings, 'Clinical acceptance reference and rationale', el('textarea')); note.value = data.settings.acceptance_note || '';
      const accepted = field(settings, 'I confirm pharmacist-supervised clinical acceptance', el('input','',{type:'checkbox'}));
      settings.append(button('Save configuration', async () => { try { await post(api,'sig/settings',{ enabled:enabled.checked, personal_shortcuts:personal.checked, acceptance_note:note.value, clinical_acceptance:accepted.checked }); status.textContent = 'Configuration saved.'; } catch(e) {status.textContent=e.message;} }));
      host.append(button('Load starter codes as drafts', async () => { try { await post(api,'sig/seed',{}); await management(host,api); } catch(e) {status.textContent=e.message;} }));
      const editorDetails=el('details','',{class:'ps-sig-more'});editorDetails.append(el('summary','Add or review an abbreviation'));host.append(el('h3','Approved abbreviations'),el('p','Abbreviations are optional. You can start creating Smart SIG directions with dose and frequency selections immediately after activation.'),editorDetails);
      const editor = el('section','',{class:'ps-workflow-grid'}); editorDetails.append(editor);
      const f = {}; ['code','value','description','aliases','routes','forms','priority','approval_note'].forEach(k => { f[k]=field(editor,k.replaceAll('_',' '),el('input','',{type:'text'})); });
      f.category = field(editor,'Category',select(['frequency','route','timing','additional'].map(k=>[k,k])));
      f.category.value='frequency';
      function canonicalValues(value='') {
        const wrapper=f.value.closest('label');
        const entries=f.category.value==='frequency'?Object.entries(data.frequencies).map(([k,v])=>[k,v[0]]):f.category.value==='route'?Object.keys(data.routes).map(k=>[k,k.replaceAll('_',' ')]):f.category.value==='timing'?data.timing.map(k=>[k,k]):null;
        f.value=entries?select(entries):el('input','',{type:'text'});f.value.value=value;
        wrapper.replaceChildren(el('span',f.category.value==='frequency'?'Frequency meaning':'Meaning'),f.value);
      }
      canonicalValues();f.category.addEventListener('change',()=>canonicalValues());
      f.status = field(editor,'Lifecycle',select(['draft','pending_approval','active','deprecated','disabled'].map(k=>[k,k]))); f.status.value='draft';
      const approval = field(editor,'Clinically reviewed and approved',el('input','',{type:'checkbox'}));
      const reference = el('aside', '', { class:'ps-sig-reference', 'aria-label':'Supported canonical values' });
      reference.append(el('h4', 'Canonical values'));
      const values = el('dl');
      [['Frequency',Object.keys(data.frequencies)],['Route',Object.keys(data.routes)],['Timing',data.timing]].forEach(([label,entries]) => { const row = el('div'); row.append(el('dt',label),el('dd',entries.join(', '))); values.append(row); });
      reference.append(values,el('p','OD and other high-risk abbreviations require an unambiguous replacement.')); editor.append(reference);
      editor.append(button('Save new version',async()=>{try {const payload=Object.fromEntries(Object.entries(f).map(([k,v])=>[k,v.value])); ['aliases','routes','forms'].forEach(k=>{payload[k]=payload[k].split(',').map(v=>v.trim()).filter(Boolean);}); payload.clinical_approval=approval.checked; await post(api,'sig/definitions',payload); await management(host,api);}catch(e){status.textContent=e.message;}}));
      const table = el('table','',{class:'ps-ledger ps-sig-table'}); const head=el('tr'); ['Code','Meaning / category','State','Version','Action'].forEach(t=>head.append(el('th',t,{scope:'col'}))); table.append(head);
      data.definitions.forEach(d=>{const needsReview=['draft','pending_approval'].includes(d.status);const row=el('tr'); row.append(el('td',`${d.code}${d.aliases.length ? ' / '+d.aliases.join(', ') : ''}`),el('td',`${d.description} / ${d.category}`),el('td',d.status),el('td',d.version));const cell=el('td');cell.append(button(needsReview ? 'Review' : 'Edit',()=>{editorDetails.open=true;f.category.value=d.category;canonicalValues(d.value);Object.entries(f).forEach(([k,v])=>{v.value=Array.isArray(d[k])?d[k].join(', '):d[k]??'';}); if(needsReview && data.permissions.approve) f.status.value='active'; approval.checked=false; if(needsReview) f.approval_note.value='';status.textContent=needsReview ? `Reviewing ${d.code}. Check all values, enter your rationale and confirm clinical approval. Saving creates a new approved version.` : `Editing ${d.code}. Clinical approval must be confirmed again for an active version.`;f.code.focus();}));row.append(cell);table.append(row);});host.append(table);
      host.append(el('h3','Reusable templates'));
      data.templates.forEach(t=>{const row=el('div',t.name+' ');row.append(button('Disable template',async()=>{try{await post(api,'sig/template_status',{id:Number(t.id)});await management(host,api);}catch(e){status.textContent=e.message;}}));host.append(row);});
      host.append(button('View version history and aggregate usage',async()=>{try{const history=await api('sig/history');const box=el('pre',JSON.stringify(history,null,2));host.append(box);}catch(e){status.textContent=e.message;}}));
      host.append(el('p','Only active, clinically approved definitions appear in dispensing. Structured syntax checks do not establish clinical safety. Historical directions retain their saved versions.'));
    } catch(e) { status.textContent=e.message; }
  }
  window.PharmaSureSmartSig = { mount, management };
})();
