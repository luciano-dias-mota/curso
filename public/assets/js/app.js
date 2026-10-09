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

  applyTheme(root.dataset.theme || localStorage.getItem('pmmta-theme') || 'dark');

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
  });

  // Busca visual na tela atual. Não altera dados nem faz requisição ao servidor.
  const search = document.querySelector('[data-global-search]');
  if (search) {
    search.addEventListener('input', () => {
      const term = search.value.trim().toLocaleLowerCase('pt-BR');
      const targets = [...document.querySelectorAll('[data-searchable], .map-item')];

      targets.forEach((target) => {
        const haystack = (target.textContent || '').toLocaleLowerCase('pt-BR');
        target.hidden = term !== '' && !haystack.includes(term);
      });
    });
  }

  // Modo foco da aula.
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

  // Setas do teclado nas aulas.
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

  // Prova/exercício: uma questão por vez em desktop e mobile.
  const quizPage = document.querySelector('[data-quiz-attempt]');
  if (quizPage) {
    const cards = [...quizPage.querySelectorAll('[data-quiz-question]')];
    const jumps = [...quizPage.querySelectorAll('[data-quiz-jump]')];
    const prev = quizPage.querySelector('[data-quiz-prev]');
    const next = quizPage.querySelector('[data-quiz-next]');
    const counter = quizPage.querySelector('[data-quiz-counter]');
    const bar = quizPage.querySelector('[data-quiz-progress-bar]');
    const submitBar = quizPage.querySelector('[data-quiz-submit-bar]');
    const form = quizPage.querySelector('[data-quiz-form]');
    let index = 0;

    const questionAnswered = (card) => !!card?.querySelector('input[type="radio"]:checked');

    const syncJumpStates = () => {
      jumps.forEach((button, i) => {
        button.classList.toggle('active', i === index);
        button.classList.toggle('answered', questionAnswered(cards[i]));
        if (i === index) button.classList.remove('answered');
      });
    };

    const showQuestion = (newIndex, scroll = true) => {
      if (!cards.length) return;
      index = Math.max(0, Math.min(cards.length - 1, newIndex));
      cards.forEach((card, i) => card.classList.toggle('is-active', i === index));

      if (counter) counter.textContent = `Questão ${index + 1} de ${cards.length}`;
      if (bar) bar.style.width = `${((index + 1) / cards.length) * 100}%`;
      if (prev) prev.disabled = index === 0;
      if (next) next.hidden = index === cards.length - 1;
      if (submitBar) submitBar.classList.toggle('is-visible', index === cards.length - 1);
      syncJumpStates();

      if (scroll) cards[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const moveNext = () => {
      const card = cards[index];
      if (!questionAnswered(card)) {
        card?.classList.add('needs-answer');
        setTimeout(() => card?.classList.remove('needs-answer'), 450);
        card?.querySelector('input[type="radio"]')?.focus();
        return;
      }
      showQuestion(index + 1);
    };

    next?.addEventListener('click', moveNext);
    prev?.addEventListener('click', () => showQuestion(index - 1));

    jumps.forEach((button) => {
      button.addEventListener('click', () => {
        const target = Number(button.dataset.quizJump || 0);
        showQuestion(target);
      });
    });

    form?.addEventListener('submit', (event) => {
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
      card.addEventListener('change', () => {
        card.classList.remove('needs-answer');
        syncJumpStates();
      });
    });

    showQuestion(0, false);
  }
});
