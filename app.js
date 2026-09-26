const currentUrl = new URL(window.location.href);
if (currentUrl.searchParams.has('s')) {
  currentUrl.searchParams.delete('s');
  window.history.replaceState(null, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);
}

document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    const status = document.querySelector('.copy-status');
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      button.textContent = 'Kopieret!';
      if (status) status.textContent = 'Linket ligger klar til at blive sendt.';
      window.setTimeout(() => { button.textContent = 'Kopiér link'; }, 1800);
    } catch {
      if (status) status.textContent = 'Markér linket og kopiér det manuelt.';
    }
  });
});

const submitCounter = () => {
  const token = document.querySelector('meta[name="counter-token"]')?.content;
  if (!token || document.visibilityState !== 'visible') return;

  fetch('/counter', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: new URLSearchParams({ token }),
    credentials: 'same-origin',
    keepalive: true,
  }).then(async (response) => {
    if (response.status !== 200) return;
    const result = await response.json();
    const count = document.querySelector('[data-visit-count]');
    if (count && Number.isInteger(result.visits)) {
      count.textContent = new Intl.NumberFormat('da-DK').format(result.visits);
    }
  }).catch(() => {});
};

const scheduleCounter = () => window.setTimeout(submitCounter, 3000);
if (document.readyState === 'complete') {
  scheduleCounter();
} else {
  window.addEventListener('load', scheduleCounter, { once: true });
}
