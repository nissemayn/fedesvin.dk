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
const deleteDialog = document.querySelector('[data-delete-dialog]');
let pendingDeleteForm = null;
if (deleteDialog) {
  deleteDialog.addEventListener('close', () => {
    const form = pendingDeleteForm;
    pendingDeleteForm = null;
    if (deleteDialog.returnValue !== 'confirm' || !form) return;
    form.dataset.confirmed = 'true';
    form.requestSubmit();
  });
  document.querySelectorAll('[data-delete-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.confirmed === 'true') {
        delete form.dataset.confirmed;
        return;
      }
      event.preventDefault();
      pendingDeleteForm = form;
      deleteDialog.showModal();
    });
  });
}

const guestbookForm = document.querySelector('.guestbook-form');
if (guestbookForm) {
  const browserCheck = guestbookForm.querySelector('[name="js_check"]');
  const notice = guestbookForm.querySelector('.guestbook-notice');
  if (browserCheck) {
    const markBrowserReady = () => {
      if (document.visibilityState === 'visible') {
        browserCheck.value = 'ready';
      } else {
        const onVisible = () => {
          if (document.visibilityState !== 'visible') return;
          browserCheck.value = 'ready';
          document.removeEventListener('visibilitychange', onVisible);
        };
        document.addEventListener('visibilitychange', onVisible);
      }
    };
    window.setTimeout(markBrowserReady, 2500);
    guestbookForm.addEventListener('submit', (event) => {
      if (browserCheck.value === 'ready') return;
      event.preventDefault();
      if (notice) notice.textContent = 'Lige et øjeblik — formularen er ved at blive klar.';
    });
  }
}

document.querySelectorAll('.retro-banner').forEach((banner) => {
  const toggle = banner.querySelector('.marquee-toggle');
  if (!toggle) return;
  const setRunning = (running) => {
    banner.dataset.running = String(running);
    toggle.textContent = running ? 'Pause ❚❚' : 'Rul tekst ►';
    toggle.setAttribute('aria-label', running ? 'Sæt rulleteksten på pause' : 'Start rulleteksten');
    toggle.setAttribute('aria-pressed', String(running));
  };
  setRunning(true);
  toggle.hidden = false;
  toggle.addEventListener('click', () => setRunning(banner.dataset.running !== 'true'));
});

const retroToggle = document.querySelector('.retro-toggle');
const retroPreferenceKey = 'fedesvin-retro';
const usesSharedRetroCookie = window.location.hostname === 'fedesvin.dk' || window.location.hostname.endsWith('.fedesvin.dk');
const readRetroPreference = () => {
  if (usesSharedRetroCookie) {
    const sharedCookie = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${retroPreferenceKey}=`));
    if (sharedCookie) return sharedCookie.slice(retroPreferenceKey.length + 1);
  }
  const legacyPreference = readPreference(retroPreferenceKey);
  if (legacyPreference !== null && usesSharedRetroCookie) saveRetroPreference(legacyPreference);
  return legacyPreference;
};
const saveRetroPreference = (value) => {
  savePreference(retroPreferenceKey, value);
  if (usesSharedRetroCookie) {
    document.cookie = `${retroPreferenceKey}=${value}; Domain=.fedesvin.dk; Path=/; Max-Age=31536000; SameSite=Lax${window.location.protocol === 'https:' ? '; Secure' : ''}`;
  }
};
const setRetro = (enabled) => {
  document.body.dataset.retro = String(enabled);
  if (retroToggle) {
    retroToggle.setAttribute('aria-pressed', String(enabled));
    retroToggle.textContent = `Retro-mode: ${enabled ? 'TIL' : 'FRA'}`;
  }
};
setRetro(readRetroPreference() === 'true');
if (retroToggle) {
  retroToggle.hidden = false;
  retroToggle.addEventListener('click', () => {
    const enabled = document.body.dataset.retro !== 'true';
    setRetro(enabled);
    saveRetroPreference(String(enabled));
  });
}

const pig = document.querySelector('[data-pig]');
if (pig) {
  const pigAudio = new Audio('/assets/pig-oink.ogg');
  pigAudio.preload = 'auto';
  const playOink = () => {
    pigAudio.currentTime = 0;
    void pigAudio.play().catch(() => {});
  };
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
  let lastLine = -1;
  const lines = [
    'ØF! Hallo, personlig svinezone!',
    'Jeg er ikke tyk. Jeg er widescreen.',
    'Den sidste kage? Aner ikke noget.',
    'Tryk igen. Det tæller som motion.',
    'Min sixpack er bare vakuumpakket.',
    'Jeg er ikke doven. Jeg er på strømsparetilstand.',
    'Det her er min vinterkrop. Hele året.',
    'Du trykker. Jeg tænker på snacks.',
    'Jeg har store planer. De fleste involverer sovs.',
    'Min personlige træner hedder Just Eat.',
    'Det er ikke en dobbelthage. Det er en nødreserve.',
    'Jeg løber kun, hvis nogen siger buffet.',
    'Flæsk er også en slags polstring.',
    'Stop med at prikke. Jeg taber koncentrationen om kagen.',
    'Jeg har cookies. Desværre kun i browseren.',
    '404: Selvdisciplin blev ikke fundet.',
    'Jeg er bygget til hygge, ikke hastighed.',
    'Tryn Ctrl+Alt+Del. Det hjælper ikke, men prøv.',
    'Jeg har ikke taget på. Jeg er blevet opgraderet.',
    'Et øf om dagen holder seriøsiteten væk.',
    'Min yndlingssport? Kagekast. Direkte i munden.',
    'Jeg er ikke rund. Jeg er aerodynamisk udfordret.',
    'Du har nu rørt mere gris end en landmand på hjemmekontor.',
    'Der er ingen fortryd-knap på den sidste frikadelle.',
  ];
  pig.addEventListener('click', () => {
    playOink();
    pokes += 1;
    const party = pokes % 5 === 0;
    const caption = document.querySelector('.pig-caption');
    if (party) {
      if (caption) caption.textContent = 'FEM ØF! Grisen kræver en snackpause.';
    } else {
      // Pick uniformly from every line except the previous one.
      const choice = Math.floor(Math.random() * (lines.length - (lastLine === -1 ? 0 : 1)));
      lastLine = choice >= lastLine && lastLine !== -1 ? choice + 1 : choice;
      if (caption) caption.textContent = lines[lastLine];
    }
    window.clearTimeout(resetAnimation);
    pig.classList.remove('is-poked', 'is-party');
    // Restart the same short animation on subsequent clicks.
    void pig.offsetWidth;
    pig.classList.add(party ? 'is-party' : 'is-poked');
    resetAnimation = window.setTimeout(() => pig.classList.remove('is-poked', 'is-party'), 1150);
    if (party) {
      // Bound the number of elements even if somebody goes all-in on the snout.
      document.querySelectorAll('.pig-confetti').forEach((item) => item.remove());
      for (let i = 0; i < 10; i += 1) {
        const confetti = document.createElement('span');
        confetti.className = 'pig-confetti';
        confetti.setAttribute('aria-hidden', 'true');
        confetti.textContent = ['✦', '♥', 'ØF', '★'][i % 4];
        confetti.style.setProperty('--confetti-x', `${(i - 4.5) * 28}px`);
        confetti.style.setProperty('--confetti-turn', `${(i - 4.5) * 20}deg`);
        confetti.style.setProperty('--confetti-y', `${-20 - (i % 4) * 18}px`);
        pig.parentElement.append(confetti);
        window.setTimeout(() => confetti.remove(), 1500);
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
