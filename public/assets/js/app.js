(() => {
  const root = document.documentElement;
  const saved = localStorage.getItem('pmmta_theme');

  if (saved === 'light' || saved === 'dark') {
    root.dataset.theme = saved;
  }

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', async () => {
      const current = root.dataset.theme === 'light' ? 'light' : 'dark';
      const next = current === 'light' ? 'dark' : 'light';

      root.dataset.theme = next;
      localStorage.setItem('pmmta_theme', next);

      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      if (!token) return;

      try {
        await fetch(`${window.APP_URL}/api/perfil/tema`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token
          },
          body: JSON.stringify({ theme: next })
        });
      } catch (_) {
        // O tema continua local mesmo se a persistência remota falhar.
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') {
      document.querySelector('[data-prev]')?.click();
    }

    if (event.key === 'ArrowRight') {
      document.querySelector('[data-next]')?.click();
    }
  });
})();
