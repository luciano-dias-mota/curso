const root = document.querySelector('[data-lab-root]');

if (root) {
    const manifestNode = document.getElementById('lab-manifest');
    let manifest = {};

    try {
        manifest = JSON.parse(manifestNode?.textContent || '{}');
    } catch {
        manifest = {};
    }

    const engineKey = root.dataset.engineKey || '';
    const submitUrl = root.dataset.submitUrl || '';
    const hintUrl = root.dataset.hintUrl || '';
    const csrfToken = root.dataset.csrfToken || '';

    const status = root.querySelector('[data-lab-status]');
    const hintBox = root.querySelector('[data-lab-hint-box]');
    const hintButton = root.querySelector('[data-lab-hint]');

    const showStatus = (message, kind = '') => {
        if (!status) return;
        status.hidden = false;
        status.textContent = message;
        status.className = 'alert' + (kind ? ' ' + kind : '');
    };

    if (hintButton && hintUrl) {
        hintButton.addEventListener('click', async () => {
            hintButton.disabled = true;

            try {
                const response = await fetch(hintUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Não foi possível liberar a dica.');
                }

                if (hintBox) {
                    hintBox.hidden = false;
                    hintBox.innerHTML = '';

                    const title = document.createElement('strong');
                    title.textContent = data.hint?.title || ('Dica ' + (data.hint?.position || ''));

                    const text = document.createElement('p');
                    text.textContent = data.hint?.content || '';

                    hintBox.append(title, text);
                }
            } catch (error) {
                showStatus(error instanceof Error ? error.message : 'Falha ao carregar a dica.', 'error');
            } finally {
                hintButton.disabled = false;
            }
        });
    }

    const waitForRuntime = async () => {
        const attempts = 40;

        for (let index = 0; index < attempts; index += 1) {
            const candidate = window.CursoMILabEngines?.[engineKey];

            if (candidate && typeof candidate.mount === 'function') {
                return candidate;
            }

            await new Promise((resolve) => window.setTimeout(resolve, 75));
        }

        return null;
    };

    const runtime = await waitForRuntime();

    if (!runtime) {
        showStatus(
            'O editor está disponível, mas o motor PostgreSQL não terminou de carregar. Atualize a página se o botão Executar continuar indisponível.',
            'warning'
        );
    } else {
        try {
            await runtime.mount({
                root,
                manifest,
                submitUrl,
                csrfToken,
                showStatus,
            });
        } catch (error) {
            showStatus(
                error instanceof Error ? error.message : 'Falha ao iniciar o motor do laboratório.',
                'error'
            );
        }
    }
}
