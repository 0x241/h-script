const submitMarkedPaymentForm = (root = document) => {
  const form = root instanceof HTMLFormElement && root.matches('form[data-hs-auto-submit="1"]')
    ? root
    : root.querySelector?.('form[data-hs-auto-submit="1"]');
  if (!(form instanceof HTMLFormElement) || form.dataset.hsSubmitted === '1') return;
  form.dataset.hsSubmitted = '1';
  form.requestSubmit();
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

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const button = target.closest('[data-hs-password-toggle]');
  if (button instanceof HTMLButtonElement) togglePasswordVisibility(button);
});

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
  startClock();
  initializeBulkSelection();
});
document.addEventListener('htmx:afterSwap', (event) => {
  submitMarkedPaymentForm(event.target);
  initializeBulkSelection(event.target);
});
