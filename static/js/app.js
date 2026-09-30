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

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const button = target.closest('[data-hs-password-toggle]');
  if (button instanceof HTMLButtonElement) togglePasswordVisibility(button);
  const copy = target.closest('[data-copy-target]');
  if (copy instanceof HTMLElement) void copyMarkedField(copy);
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
});
document.addEventListener('htmx:afterSwap', (event) => {
  submitMarkedPaymentForm(event.target);
  markReadReceipts(event.target);
  initializeBulkSelection(event.target);
});
