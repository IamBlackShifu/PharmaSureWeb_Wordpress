(() => {
  'use strict';
  const form = document.querySelector('#pharmasure-tenant-form');
  if (!form) return;
  const tradingName = form.querySelector('#trading_name');
  const slug = form.querySelector('#slug');
  const branchCode = form.querySelector('#branch_code');
  const country = form.querySelector('#country');
  const currency = form.querySelector('#currency');
  let slugEdited = Boolean(slug?.value);

  slug?.addEventListener('input', () => { slugEdited = Boolean(slug.value); });
  tradingName?.addEventListener('input', () => {
    if (slugEdited || !slug) return;
    slug.value = tradingName.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  });
  [branchCode, country, currency].forEach((field) => field?.addEventListener('input', () => { field.value = field.value.toUpperCase(); }));
  form.addEventListener('submit', () => {
    form.classList.add('is-busy');
    form.setAttribute('aria-busy', 'true');
    const button = form.querySelector('button[type="submit"]');
    if (button) button.textContent = 'Provisioning isolated pharmacy...';
  });
})();
