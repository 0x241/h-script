const submitMarkedPaymentForm = (root = document) => {
  const form = root instanceof HTMLFormElement && root.matches('form[data-hs-auto-submit="1"]')
    ? root
    : root.querySelector?.('form[data-hs-auto-submit="1"]');
  if (!(form instanceof HTMLFormElement) || form.dataset.hsSubmitted === '1') return;
  form.dataset.hsSubmitted = '1';
  form.requestSubmit();
};

const markReadReceipts = (root = document) => {
  const forms = [
    ...(root instanceof HTMLFormElement && root.matches('[data-hs-read-receipt]') ? [root] : []),
    ...(root.querySelectorAll?.('form[data-hs-read-receipt]') ?? []),
  ];
  forms.forEach((form) => {
    if (form.dataset.hsSubmitted === '1' || new URL(form.action).origin !== window.location.origin) return;
    form.dataset.hsSubmitted = '1';
    void fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      redirect: 'error',
    }).catch(() => {});
  });
};

const startClock = () => {
  const clock = document.querySelector('[data-hs-clock]');
  if (!(clock instanceof HTMLElement)) return;
  const offsetSeconds = Number(clock.dataset.offsetSeconds ?? 0);
  const render = () => {
    const date = new Date(Date.now() + offsetSeconds * 1000);
    clock.textContent = date.toISOString().slice(11, 19);
  };
  render();
  window.setInterval(render, 1000);
};

const bulkSelectionItems = (selectAll) => {
  const scope = selectAll.closest('table') ?? selectAll.closest('form');
  if (!(scope instanceof HTMLElement)) return [];
  return [...scope.querySelectorAll('[data-hs-select-item]:not(:disabled)')];
};

const syncBulkSelection = (selectAll) => {
  if (!(selectAll instanceof HTMLInputElement)) return;
  const items = bulkSelectionItems(selectAll);
  const selected = items.filter((item) => item.checked).length;
  selectAll.checked = items.length > 0 && selected === items.length;
  selectAll.indeterminate = selected > 0 && selected < items.length;
};

const initializeBulkSelection = (root = document) => {
  if (root instanceof HTMLInputElement && root.matches('[data-hs-select-all]')) {
    syncBulkSelection(root);
  }
  root.querySelectorAll?.('[data-hs-select-all]').forEach(syncBulkSelection);
};

const togglePasswordVisibility = (button) => {
  if (!(button instanceof HTMLButtonElement)) return;
  const inputId = button.getAttribute('aria-controls');
  const input = inputId ? document.getElementById(inputId) : null;
  if (!(input instanceof HTMLInputElement)) return;

  const showValue = input.type === 'password';
  input.type = showValue ? 'text' : 'password';
  button.setAttribute('aria-pressed', showValue ? 'true' : 'false');
  const label = showValue ? button.dataset.hideLabel : button.dataset.showLabel;
  if (label) {
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
  }
  button.classList.toggle('text-blue-500', showValue);
  const icon = button.querySelector('[data-hs-password-icon]');
  if (icon instanceof HTMLElement) {
    icon.classList.toggle('fa-eye', !showValue);
    icon.classList.toggle('fa-eye-slash', showValue);
  }
};

const copyMarkedField = async (element) => {
  const target = document.getElementById(element.dataset.copyTarget ?? '');
  if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
  target.focus();
  target.select();
  target.setSelectionRange(0, target.value.length);
  if (window.isSecureContext && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(target.value);
      return;
    } catch {
      // Keep the selection for the manual copy fallback.
    }
  }
  document.execCommand('copy');
};

const paymentWatchers = new Map();
const initializePaymentWatchers = (root = document) => {
  const elements = [
    ...(root instanceof HTMLElement && root.matches('[data-hs-payment-watch]') ? [root] : []),
    ...(root.querySelectorAll?.('[data-hs-payment-watch]') ?? []),
  ];
  elements.forEach((element) => {
    if (paymentWatchers.has(element)) return;
    const url = new URL(element.dataset.statusUrl, document.baseURI);
    const success = new URL(element.dataset.successUrl, document.baseURI);
    if (url.origin !== location.origin || success.origin !== location.origin) return;
    const status = element.querySelector('[data-payment-status]');
    const hint = element.querySelector('[data-payment-hint]');
    const button = element.querySelector('[data-payment-refresh]');
    const confirmations = element.querySelector('[data-payment-confirmations]');
    if (!status || !hint || !button) return;
    const automatic = hint.textContent;
    let timer, controller, last = 0, failures = 0, stopped = false, until = Date.now() + 30 * 60 * 1000;
    const schedule = (delay = 8000) => {
      clearTimeout(timer);
      if (!stopped) timer = setTimeout(() => void poll(), delay);
    };
    const poll = async () => {
      clearTimeout(timer);
      if (!element.isConnected) { stopped = true; paymentWatchers.delete(element); return; }
      if (stopped || controller) return;
      if (Date.now() > until) { hint.textContent = element.dataset.paused; return; }
      if (document.hidden) { schedule(); return; }
      if (Date.now() - last < 5000) { schedule(5000); return; }
      last = Date.now();
      button.disabled = true;
      controller = new AbortController();
      const timeout = setTimeout(() => controller?.abort(), 10000);
      try {
        const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: {Accept: 'application/json'}});
        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Payment status unavailable');
        const data = await response.json();
        if (!['waiting', 'confirming', 'credited', 'closed'].includes(data.state)) throw new Error('Invalid payment status');
        failures = 0;
        element.dataset.state = data.state;
        status.textContent = element.dataset[data.state];
        hint.textContent = automatic;
        if (confirmations) {
          const count = data.confirmations;
          confirmations.hidden = !Number.isSafeInteger(count) || count < 0;
          confirmations.textContent = confirmations.hidden ? '' : element.dataset.confirmations.replace('{{count}}', String(count));
        }
        if (data.state === 'credited') {
          stopped = true;
          setTimeout(() => { if (element.isConnected) location.assign(success.href); }, 1200);
        } else if (data.state === 'closed') {
          stopped = true;
          hint.textContent = '';
          element.querySelectorAll('.hs-payment-button').forEach((item) => { item.disabled = true; });
        }
      } catch {
        failures++;
        hint.textContent = element.dataset.offline;
      } finally {
        clearTimeout(timeout);
        controller = null;
        button.disabled = stopped;
        schedule(Math.min(60000, 8000 * (1 + failures)));
      }
    };
    const refresh = () => { until = Date.now() + 30 * 60 * 1000; void poll(); };
    const stop = () => { stopped = true; clearTimeout(timer); controller?.abort(); };
    paymentWatchers.set(element, {refresh, stop});
    schedule();
  });
};

// The name attribute provides native exclusivity; toggle also covers older Safari.
document.addEventListener('toggle', (event) => {
  const opened = event.target;
  if (!(opened instanceof HTMLDetailsElement) || !opened.open) return;
  opened.closest('[data-hs-accordion]')?.querySelectorAll('details[open]').forEach((item) => {
    if (item !== opened) item.open = false;
  });
}, true);

window.addEventListener('pagehide', () => {
  paymentWatchers.forEach(({stop}) => stop());
  paymentWatchers.clear();
});
window.addEventListener('pageshow', () => initializePaymentWatchers());

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const button = target.closest('[data-hs-password-toggle]');
  if (button instanceof HTMLButtonElement) togglePasswordVisibility(button);
  const copy = target.closest('[data-copy-target]');
  if (copy instanceof HTMLElement) void copyMarkedField(copy);
  const refresh = target.closest('[data-payment-refresh]');
  if (refresh) paymentWatchers.get(refresh.closest('[data-hs-payment-watch]'))?.refresh();
});

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
    event.preventDefault();
    event.stopImmediatePropagation();
  }
}, true);

document.addEventListener('change', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;

  if (target.matches('[data-hs-select-all]')) {
    bulkSelectionItems(target).forEach((item) => {
      item.checked = target.checked;
    });
    target.indeterminate = false;
    return;
  }

  if (!target.matches('[data-hs-select-item]')) return;
  const scope = target.closest('table') ?? target.closest('form');
  syncBulkSelection(scope?.querySelector('[data-hs-select-all]'));
});

document.addEventListener('DOMContentLoaded', () => {
  submitMarkedPaymentForm();
  markReadReceipts();
  startClock();
  initializeBulkSelection();
  initializePaymentWatchers();
});
document.addEventListener('htmx:afterSwap', (event) => {
  submitMarkedPaymentForm(event.target);
  markReadReceipts(event.target);
  initializeBulkSelection(event.target);
  initializePaymentWatchers(event.target);
});

// Provider catalogs contain plain text; filtering never renders provider HTML.
document.addEventListener('input', (event) => {
  const field = event.target;
  if (!(field instanceof HTMLInputElement) || !field.matches('[data-token-search]')) return;
  const query = field.value.trim().toLocaleLowerCase();
  field.closest('[data-token-catalog]')?.querySelectorAll('[data-token-option]').forEach((item) => {
    item.hidden = !item.textContent.toLocaleLowerCase().includes(query);
    item.style.display = item.hidden ? 'none' : '';
  });
});
