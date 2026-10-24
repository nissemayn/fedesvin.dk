const currentUrl = new URL(window.location.href);
if (currentUrl.searchParams.has('s')) {
  // Keep a personal link reloadable without leaving the old tracking query visible.
  const personalCode = document.querySelector('meta[property="og:url"]')?.content.match(/\/s\/([a-z0-9-]{1,50})$/)?.[1];
  if (personalCode) currentUrl.pathname = `/p/${personalCode}`;
  currentUrl.searchParams.delete('s');
  window.history.replaceState(null, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);
}

const readPreference = (key) => {
  try { return localStorage.getItem(key); } catch { return null; }
};
const savePreference = (key, value) => {
  try { localStorage.setItem(key, value); } catch {}
};

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
document.querySelectorAll('.retro-banner').forEach((banner) => {
  const toggle = banner.querySelector('.marquee-toggle');
  if (!toggle) return;
  const setRunning = (running) => {
    banner.dataset.running = String(running);
    toggle.textContent = running ? 'Pause ❚❚' : 'Rul tekst ►';
    toggle.setAttribute('aria-label', running ? 'Sæt rulleteksten på pause' : 'Start rulleteksten');
    toggle.setAttribute('aria-pressed', String(running));
  };
  setRunning(!reducedMotion.matches);
  toggle.hidden = false;
  toggle.addEventListener('click', () => setRunning(banner.dataset.running !== 'true'));
  reducedMotion.addEventListener('change', () => setRunning(!reducedMotion.matches));
});

const retroToggle = document.querySelector('.retro-toggle');
const setRetro = (enabled) => {
  document.body.dataset.retro = String(enabled);
  if (retroToggle) {
    retroToggle.setAttribute('aria-pressed', String(enabled));
    retroToggle.textContent = `Retro-mode: ${enabled ? 'TIL' : 'FRA'}`;
  }
};
setRetro(readPreference('fedesvin-retro') === 'true');
if (retroToggle) {
  retroToggle.hidden = false;
  retroToggle.addEventListener('click', () => {
    const enabled = document.body.dataset.retro !== 'true';
    setRetro(enabled);
    savePreference('fedesvin-retro', String(enabled));
  });
}

const pig = document.querySelector('[data-pig]');
if (pig) {
  const variants = ['classic', 'rose', 'mint', 'business', 'party', 'royal', 'goth', 'pirate', 'space'];
  const previous = readPreference('fedesvin-last-pig');
  const choices = variants.filter((variant) => variant !== previous);
  const variant = choices[Math.floor(Math.random() * choices.length)];
  pig.dataset.pig = variant;
  savePreference('fedesvin-last-pig', variant);
  document.querySelectorAll('[data-image-download]').forEach((link) => {
    const url = new URL(link.href);
    url.searchParams.set('v', variant);
    link.href = url.href;
  });

  let pokes = 0;
  let resetAnimation;
  pig.addEventListener('click', () => {
    pokes += 1;
    const party = pokes % 5 === 0;
    const lines = ['ØF! Hallo, personlig svinezone!', 'Jeg er ikke tyk. Jeg er widescreen.', 'Den sidste kage? Aner ikke noget.', 'Tryk igen. Det tæller som motion.'];
    const caption = document.querySelector('.pig-caption');
    if (caption) caption.textContent = party ? 'SVINEFEST! Du har fundet trynens hemmelighed.' : lines[(pokes - 1) % lines.length];
    window.clearTimeout(resetAnimation);
    pig.classList.remove('is-poked', 'is-party');
    // Restart the same short animation on subsequent clicks.
    void pig.offsetWidth;
    pig.classList.add(party ? 'is-party' : 'is-poked');
    resetAnimation = window.setTimeout(() => pig.classList.remove('is-poked', 'is-party'), 1150);
    if (party && !reducedMotion.matches) {
      // Bound the number of elements even if somebody goes all-in on the snout.
      document.querySelectorAll('.pig-confetti').forEach((item) => item.remove());
      for (let i = 0; i < 10; i += 1) {
        const confetti = document.createElement('span');
        confetti.className = 'pig-confetti';
        confetti.setAttribute('aria-hidden', 'true');
        confetti.textContent = ['✦', '♥', 'ØF', '★'][i % 4];
        confetti.style.setProperty('--confetti-x', `${(i - 4.5) * 28}px`);
        confetti.style.setProperty('--confetti-turn', `${(i - 4.5) * 20}deg`);
        pig.parentElement.append(confetti);
        window.setTimeout(() => confetti.remove(), 1000);
      }
    }
  });
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
