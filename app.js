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
