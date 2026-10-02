document.addEventListener('DOMContentLoaded', () => {
  const root = document.documentElement;
  const body = document.body;
  const appUrl = window.APP_URL || '';

  const resolveTheme = (theme) => {
    if (theme === 'system') {
      return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    return ['light', 'dark'].includes(theme) ? theme : 'dark';
  };

  const persistThemeRemote = (theme) => {
    if (!appUrl) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;

    fetch(`${appUrl}/api/perfil/tema`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ theme })
    }).catch(() => {});
  };

  const applyTheme = (theme, remote = false) => {
    const normalized = resolveTheme(theme);
    root.dataset.theme = normalized;
    localStorage.setItem('pmmta-theme', normalized);

    document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
      icon.textContent = normalized === 'dark' ? '☀' : '◐';
    });

    if (remote) persistThemeRemote(normalized);
  };

  applyTheme(localStorage.getItem('pmmta-theme') || root.dataset.theme || 'dark');

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
  });

  // Modo foco da aula
  const focusButtons = [...document.querySelectorAll('[data-focus-toggle]')];
  const setFocusMode = (enabled) => {
    body.classList.toggle('focus-mode', enabled);
    focusButtons.forEach((button) => {
      button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      const label = button.querySelector('[data-focus-label]');
      if (label) label.textContent = enabled ? 'Sair do foco' : 'Modo foco';
    });
  };

  focusButtons.forEach((button) => {
    button.addEventListener('click', () => setFocusMode(!body.classList.contains('focus-mode')));
  });

  // Navegação por teclado nas aulas
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && body.classList.contains('focus-mode')) {
      setFocusMode(false);
      return;
    }

    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;

    if (event.key === 'ArrowLeft') {
      const previous = document.querySelector('[data-prev]');
      if (previous) {
        event.preventDefault();
        previous.click();
      }
    }

    if (event.key === 'ArrowRight') {
      const next = document.querySelector('[data-next]');
      if (next) {
        event.preventDefault();
        next.click();
      }
    }
  });

  // Exercícios: uma questão por vez no mobile, sem alterar o submit do backend.
  const quizPage = document.querySelector('[data-quiz-attempt]');
  if (quizPage) {
    const cards = [...quizPage.querySelectorAll('[data-quiz-question]')];
    const prev = quizPage.querySelector('[data-quiz-prev]');
    const next = quizPage.querySelector('[data-quiz-next]');
    const counter = quizPage.querySelector('[data-quiz-counter]');
    const bar = quizPage.querySelector('[data-quiz-progress-bar]');
    const submitBar = quizPage.querySelector('[data-quiz-submit-bar]');
    const form = quizPage.querySelector('[data-quiz-form]');
    const mobileQuery = window.matchMedia('(max-width: 800px)');
    let index = 0;

    const questionAnswered = (card) => !!card?.querySelector('input[type="radio"]:checked');

    const showQuestion = (newIndex) => {
      if (!cards.length) return;
      index = Math.max(0, Math.min(cards.length - 1, newIndex));
      cards.forEach((card, i) => card.classList.toggle('is-active', i === index));

      if (counter) counter.textContent = `Questão ${index + 1} de ${cards.length}`;
      if (bar) bar.style.width = `${((index + 1) / cards.length) * 100}%`;
      if (prev) prev.disabled = index === 0;
      if (next) next.hidden = index === cards.length - 1;
      if (submitBar) submitBar.classList.toggle('is-visible', index === cards.length - 1);

      cards[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const syncQuizMode = () => {
      const mobile = mobileQuery.matches;
      quizPage.classList.toggle('quiz-one-by-one', mobile);
      if (mobile) showQuestion(index);
      else {
        cards.forEach((card) => card.classList.add('is-active'));
        if (submitBar) submitBar.classList.remove('is-visible');
      }
    };

    next?.addEventListener('click', () => {
      const card = cards[index];
      if (!questionAnswered(card)) {
        card?.classList.add('needs-answer');
        setTimeout(() => card?.classList.remove('needs-answer'), 450);
        card?.querySelector('input[type="radio"]')?.focus();
        return;
      }
      showQuestion(index + 1);
    });

    prev?.addEventListener('click', () => showQuestion(index - 1));

    form?.addEventListener('submit', (event) => {
      if (!mobileQuery.matches) return;
      const unansweredIndex = cards.findIndex((card) => !questionAnswered(card));
      if (unansweredIndex !== -1) {
        event.preventDefault();
        showQuestion(unansweredIndex);
        const card = cards[unansweredIndex];
        card?.classList.add('needs-answer');
        setTimeout(() => card?.classList.remove('needs-answer'), 450);
      }
    });

    cards.forEach((card) => {
      card.addEventListener('change', () => card.classList.remove('needs-answer'));
    });

    if (typeof mobileQuery.addEventListener === 'function') mobileQuery.addEventListener('change', syncQuizMode);
    else mobileQuery.addListener(syncQuizMode);

    syncQuizMode();
  }
});
