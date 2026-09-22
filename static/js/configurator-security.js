/* Authenticated, CSRF-protected, sequential batches; no mutations through GET. */
(() => {
    const form = document.querySelector('[data-integrity-scan]');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    const button = form.querySelector('button');
    const progress = document.querySelector('[data-integrity-progress]');
    const csrf = form.elements.namedItem('csrf');
    const action = form.elements.namedItem('securityAction');
    let running = false;
    async function run() {
        if (running || button.disabled) return;
        running = true;
        button.disabled = true;
        try {
            while (form.isConnected) {
                const response = await fetch(form.action || location.href, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: new FormData(form),
                });
                if (!response.headers.get('content-type')?.includes('application/json')) throw new Error();
                const result = await response.json();
                if (typeof result.csrf === 'string') csrf.value = result.csrf;
                if (!response.ok || !['running', 'completed'].includes(result.status)) throw new Error();
                if (!form.isConnected) return;
                progress.textContent = `${result.checked} / ${result.total}`;
                if (result.status === 'completed') { location.reload(); return; }
                action.value = 'continue';
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        } catch {
            if (form.isConnected) progress.textContent = form.dataset.error;
            // Stop on auth/network/lock errors. Never retry blindly or loop on login HTML.
        } finally {
            running = false;
            button.disabled = false;
        }
    }
    form.addEventListener('submit', event => { event.preventDefault(); run(); });
    if (form.dataset.running === '1') run();
})();
