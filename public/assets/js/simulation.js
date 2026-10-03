document.addEventListener('DOMContentLoaded', () => {
  const appUrl = window.APP_URL || '';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const createPage = document.querySelector('[data-simulation-create]');
  if (createPage) {
    const course = createPage.querySelector('[data-simulation-course]');
    const module = createPage.querySelector('[data-simulation-module]');
    const totalEl = createPage.querySelector('[data-pool-total]');
    const mediumEl = createPage.querySelector('[data-pool-medium]');
    const hardEl = createPage.querySelector('[data-pool-hard]');

    const syncModules = () => {
      if (!course || !module) return;
      const courseId = course.value;
      [...module.options].forEach((option) => {
        const general = option.dataset.general === '1';
        const sameCourse = option.dataset.course === courseId;
        option.hidden = !general && !sameCourse;
        option.disabled = !general && !sameCourse;
      });
      if (module.selectedOptions[0]?.disabled) module.value = '0';
      const general = module.querySelector('[data-general="1"]');
      if (general) general.dataset.course = courseId;
      syncPool();
    };

    const syncPool = () => {
      const moduleOption = module?.selectedOptions?.[0];
      const courseOption = course?.selectedOptions?.[0];
      const source = moduleOption && moduleOption.value !== '0' ? moduleOption : courseOption;
      if (!source) return;
      if (totalEl) totalEl.textContent = source.dataset.total || '0';
      if (mediumEl) mediumEl.textContent = source.dataset.medium || '0';
      if (hardEl) hardEl.textContent = source.dataset.hard || '0';
    };

    course?.addEventListener('change', syncModules);
    module?.addEventListener('change', syncPool);
    syncModules();
  }

  const page = document.querySelector('[data-simulation-attempt]');
  if (!page) return;

  const cards = [...page.querySelectorAll('[data-simulation-question]')];
  const jumps = [...page.querySelectorAll('[data-simulation-jump]')];
  const prev = page.querySelector('[data-simulation-prev]');
  const next = page.querySelector('[data-simulation-next]');
  const counter = page.querySelector('[data-simulation-counter]');
  const answeredLabel = page.querySelector('[data-simulation-answered]');
  const progress = page.querySelector('[data-simulation-progress]');
  const saveStatus = page.querySelector('[data-simulation-save-status]');
  const finishForm = page.querySelector('[data-simulation-finish-form]');
  const timers = [page.querySelector('[data-simulation-timer]'), page.querySelector('[data-simulation-timer-mirror]')].filter(Boolean);
  const saveUrl = page.dataset.saveUrl || '';
  let remaining = Number(page.dataset.remainingSeconds || 0);
  let index = 0;
  let timerHandle = null;
  let autoSubmitting = false;

  const answered = (card) => !!card?.querySelector('input[type="radio"]:checked');
  const answeredCount = () => cards.filter(answered).length;

  const setSaveStatus = (message, state = '') => {
    if (!saveStatus) return;
    saveStatus.textContent = message;
    saveStatus.dataset.state = state;
  };

  const sync = () => {
    const done = answeredCount();
    if (answeredLabel) answeredLabel.textContent = `${done}/${cards.length} respondidas`;
    jumps.forEach((button, i) => {
      button.classList.toggle('active', i === index);
      button.classList.toggle('answered', i !== index && answered(cards[i]));
    });
  };

  const show = (target, scroll = true) => {
    if (!cards.length) return;
    index = Math.max(0, Math.min(cards.length - 1, target));
    cards.forEach((card, i) => card.classList.toggle('is-active', i === index));
    if (counter) counter.textContent = `Questão ${index + 1} de ${cards.length}`;
    if (progress) progress.style.width = `${((index + 1) / cards.length) * 100}%`;
    if (prev) prev.disabled = index === 0;
    if (next) next.hidden = index === cards.length - 1;
    sync();
    if (scroll) cards[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const saveAnswer = async (card, input) => {
    if (!saveUrl || !csrf) return;
    const questionId = card.dataset.questionId;
    setSaveStatus('Salvando resposta...', 'saving');

    const body = new URLSearchParams();
    body.set('_token', csrf);
    body.set('question_id', questionId || '0');
    body.set('alternative_id', input.value);

    try {
      const response = await fetch(saveUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Falha ao salvar.');
      setSaveStatus('Resposta salva automaticamente.', 'saved');
      sync();
      if (typeof data.remaining_seconds === 'number') remaining = Math.min(remaining, data.remaining_seconds);
    } catch (error) {
      setSaveStatus(error.message || 'Não foi possível salvar a resposta.', 'error');
    }
  };

  cards.forEach((card) => {
    card.addEventListener('change', (event) => {
      const input = event.target.closest('input[type="radio"]');
      if (!input) return;
      saveAnswer(card, input);
      sync();
    });
  });

  prev?.addEventListener('click', () => show(index - 1));
  next?.addEventListener('click', () => show(index + 1));
  jumps.forEach((button) => button.addEventListener('click', () => show(Number(button.dataset.simulationJump || 0))));

  finishForm?.addEventListener('submit', (event) => {
    if (autoSubmitting) return;
    const unanswered = cards.length - answeredCount();
    if (unanswered > 0 && !window.confirm(`Ainda existem ${unanswered} questão(ões) sem resposta. Elas contarão como erro. Finalizar mesmo assim?`)) {
      event.preventDefault();
    }
  });

  const renderTimer = () => {
    const value = Math.max(0, remaining);
    const minutes = Math.floor(value / 60);
    const seconds = value % 60;
    const text = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    timers.forEach((timer) => {
      const strong = timer.querySelector('strong');
      if (strong) strong.textContent = text;
      timer.classList.toggle('urgent', value <= 300);
    });
  };

  renderTimer();
  timerHandle = window.setInterval(() => {
    remaining -= 1;
    renderTimer();
    if (remaining <= 0) {
      window.clearInterval(timerHandle);
      setSaveStatus('Tempo encerrado. Finalizando o simulado...', 'error');
      if (finishForm && !autoSubmitting) {
        autoSubmitting = true;
        finishForm.submit();
      }
    }
  }, 1000);

  show(0, false);
});
