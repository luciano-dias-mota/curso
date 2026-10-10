(() => {
    'use strict';

    const forms = document.querySelectorAll('.study-scope-form');

    const filterOptions = (select, predicate, selectedValue = '') => {
        if (!select) return;

        let visibleCount = 0;
        [...select.options].forEach((option, index) => {
            if (index === 0 || option.value === '') {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const visible = predicate(option);
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible) visibleCount += 1;
        });

        select.disabled = visibleCount === 0;

        if (selectedValue && [...select.options].some((o) => o.value === selectedValue && !o.disabled)) {
            select.value = selectedValue;
        } else if (select.value && select.selectedOptions[0]?.disabled) {
            select.value = '';
        }
    };

    forms.forEach((form) => {
        const course = form.querySelector('[data-scope-course]');
        const module = form.querySelector('[data-scope-module]');
        const phase = form.querySelector('[data-scope-phase]');
        const popTopic = form.querySelector('[data-pop-topic]');
        const processTopic = form.querySelector('[data-process-topic]');
        const procedureTopic = form.querySelector('[data-procedure-topic]');

        if (!course) return;

        const resetSelect = (select) => {
            if (!select) return;
            select.value = '';
            select.disabled = true;
            [...select.options].forEach((option, index) => {
                option.hidden = index !== 0 && option.value !== '';
                option.disabled = index !== 0 && option.value !== '';
            });
        };

        const syncProcedureTopic = () => {
            if (!procedureTopic) return;
            const processId = processTopic?.value || '';
            if (!processId) {
                resetSelect(procedureTopic);
                return;
            }
            filterOptions(
                procedureTopic,
                (option) => option.dataset.parentId === processId
            );
        };

        const syncProcessTopic = () => {
            if (!processTopic) return;
            const popId = popTopic?.value || '';
            if (!popId) {
                resetSelect(processTopic);
                resetSelect(procedureTopic);
                return;
            }
            filterOptions(
                processTopic,
                (option) => option.dataset.parentId === popId
            );
            syncProcedureTopic();
        };

        const syncPopTopics = () => {
            if (!popTopic) return;
            const selectedModule = module?.selectedOptions?.[0];
            const isPopModule = Boolean(module?.value) && selectedModule?.dataset.isPop === '1';
            if (!isPopModule) {
                resetSelect(popTopic);
                resetSelect(processTopic);
                resetSelect(procedureTopic);
                return;
            }

            let available = 0;
            [...popTopic.options].forEach((option, index) => {
                const visible = true;
                option.hidden = !visible;
                option.disabled = !visible;
                if (index > 0 && option.value) available += 1;
            });
            popTopic.disabled = available === 0;
            syncProcessTopic();
        };

        const syncPhase = (initial = false) => {
            if (!phase) return;
            const courseId = course.value;
            const moduleId = module?.value || '';
            const selected = initial ? String(phase.dataset.selected || phase.value || '') : '';

            if (!courseId || !moduleId) {
                phase.value = '';
                phase.disabled = true;
                [...phase.options].forEach((option, index) => {
                    option.hidden = index !== 0 && option.value !== '';
                    option.disabled = index !== 0 && option.value !== '';
                });
                return;
            }

            filterOptions(
                phase,
                (option) => option.dataset.courseId === courseId && option.dataset.moduleId === moduleId,
                selected
            );
        };

        const syncModule = (initial = false) => {
            if (!module) return;
            const courseId = course.value;
            const selected = initial ? String(module.dataset.selected || module.value || '') : '';

            if (!courseId) {
                module.value = '';
                module.disabled = true;
                [...module.options].forEach((option, index) => {
                    option.hidden = index !== 0 && option.value !== '';
                    option.disabled = index !== 0 && option.value !== '';
                });
                syncPhase();
                return;
            }

            filterOptions(module, (option) => option.dataset.courseId === courseId, selected);
            syncPhase(initial);
        };

        course.addEventListener('change', () => {
            if (module) module.value = '';
            if (phase) phase.value = '';
            resetSelect(popTopic);
            resetSelect(processTopic);
            resetSelect(procedureTopic);
            syncModule();
            syncPopTopics();
        });
        module?.addEventListener('change', () => {
            if (phase) phase.value = '';
            if (popTopic) popTopic.value = '';
            if (processTopic) processTopic.value = '';
            if (procedureTopic) procedureTopic.value = '';
            syncPhase();
            syncPopTopics();
        });
        popTopic?.addEventListener('change', () => {
            if (processTopic) processTopic.value = '';
            if (procedureTopic) procedureTopic.value = '';
            syncProcessTopic();
        });
        processTopic?.addEventListener('change', () => {
            if (procedureTopic) procedureTopic.value = '';
            syncProcedureTopic();
        });

        syncModule(true);
        syncPopTopics();
    });

    const answerForm = document.querySelector('[data-exercise-answer-form]');
    if (!answerForm) return;

    const feedback = document.querySelector('[data-exercise-feedback]');
    const confirmButton = answerForm.querySelector('[data-exercise-confirm]');
    const nextLink = feedback?.querySelector('[data-exercise-next]');

    answerForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const selected = answerForm.querySelector('input[name="alternative_id"]:checked');
        if (!selected) {
            window.alert('Selecione uma alternativa antes de confirmar.');
            return;
        }

        confirmButton.disabled = true;
        confirmButton.textContent = 'Corrigindo...';

        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const payload = {
            session_question_id: Number(answerForm.querySelector('input[name="session_question_id"]')?.value || 0),
            alternative_id: Number(selected.value),
        };

        try {
            const response = await fetch(answerForm.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': token,
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json().catch(() => ({
                ok: false,
                message: 'Resposta inválida do servidor.',
            }));

            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'Não foi possível registrar a resposta.');
            }

            answerForm.querySelectorAll('input[name="alternative_id"]').forEach((input) => {
                input.disabled = true;
            });

            confirmButton.hidden = true;
            feedback.hidden = false;
            feedback.classList.toggle('is-correct', Boolean(data.correct));
            feedback.classList.toggle('is-wrong', !data.correct);

            const icon = feedback.querySelector('[data-exercise-feedback-icon]');
            const title = feedback.querySelector('[data-exercise-feedback-title]');
            const correct = feedback.querySelector('[data-exercise-feedback-correct]');
            const explanation = feedback.querySelector('[data-exercise-feedback-explanation]');
            const source = feedback.querySelector('[data-exercise-feedback-source]');

            if (icon) icon.textContent = data.correct ? '✅' : '📘';
            if (title) title.textContent = data.message || (data.correct ? 'Resposta correta.' : 'Revise este conceito.');
            if (correct) {
                correct.textContent = data.correct
                    ? 'Você marcou a alternativa correta.'
                    : `Correta: ${data.correct_alternative?.label || ''}) ${data.correct_alternative?.text || ''}`;
            }
            if (explanation) {
                explanation.textContent = data.explanation || 'Sem explicação cadastrada para esta questão.';
            }
            if (source) {
                source.textContent = data.source ? `Fonte: ${data.source}` : '';
                source.hidden = !data.source;
            }

            if (nextLink) {
                nextLink.href = data.finished ? answerForm.dataset.resultUrl : answerForm.dataset.nextUrl;
                nextLink.textContent = data.finished ? 'Ver resultado →' : 'Próxima questão →';
            }
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Não foi possível registrar a resposta.');
            confirmButton.disabled = false;
            confirmButton.textContent = 'Confirmar resposta';
        }
    });
})();
