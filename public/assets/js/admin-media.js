document.addEventListener('DOMContentLoaded', () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.dataset.confirm || 'Confirmar esta ação?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-video-file]').forEach((input) => {
        input.addEventListener('change', () => {
            const output = input.closest('label')?.querySelector('[data-file-name]');
            if (!output) return;
            const file = input.files?.[0];
            output.textContent = file
                ? `${file.name} • ${formatBytes(file.size)}`
                : 'Nenhum arquivo selecionado.';
        });
    });

    const list = document.querySelector('[data-block-list]');
    if (!list) return;

    const status = document.querySelector('[data-reorder-status]');
    let dragging = null;
    let saving = false;

    const blocks = () => Array.from(list.querySelectorAll('[data-block-id]'));

    const refreshPositions = () => {
        blocks().forEach((block, index) => {
            const badge = block.querySelector('.block-position');
            if (badge) badge.textContent = String(index + 1);
        });
    };

    const showStatus = (message, type = '') => {
        if (!status) return;
        status.textContent = message;
        status.className = `reorder-status ${type}`.trim();
    };

    const saveOrder = async () => {
        if (saving) return;

        const url = list.dataset.reorderUrl;
        if (!url) return;

        const order = blocks().map((block) => Number(block.dataset.blockId));
        saving = true;
        showStatus('Salvando nova ordem...');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({order}),
            });

            let data = {};
            try {
                data = await response.json();
            } catch (_) {}

            if (!response.ok || data.ok === false) {
                throw new Error(data.message || 'Não foi possível salvar a ordem.');
            }

            refreshPositions();
            showStatus('Ordem salva.', 'ok');
        } catch (error) {
            showStatus(error.message || 'Falha ao salvar a ordem.', 'error');
        } finally {
            saving = false;
        }
    };

    blocks().forEach((block) => {
        block.addEventListener('dragstart', () => {
            dragging = block;
            block.classList.add('is-dragging');
        });

        block.addEventListener('dragend', () => {
            block.classList.remove('is-dragging');
            blocks().forEach((item) => item.classList.remove('is-drop-target'));
            dragging = null;
        });

        block.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (!dragging || dragging === block) return;

            blocks().forEach((item) => item.classList.remove('is-drop-target'));
            block.classList.add('is-drop-target');

            const rect = block.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;

            if (after) {
                block.after(dragging);
            } else {
                block.before(dragging);
            }
        });

        block.addEventListener('drop', async (event) => {
            event.preventDefault();
            blocks().forEach((item) => item.classList.remove('is-drop-target'));
            refreshPositions();
            await saveOrder();
        });
    });

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-move]');
        if (!button) return;

        const block = button.closest('[data-block-id]');
        if (!block) return;

        const direction = button.dataset.move;
        const siblings = blocks();
        const index = siblings.indexOf(block);

        if (direction === 'up' && index > 0) {
            siblings[index - 1].before(block);
        } else if (direction === 'down' && index < siblings.length - 1) {
            siblings[index + 1].after(block);
        } else {
            return;
        }

        refreshPositions();
        await saveOrder();
    });

    refreshPositions();
});

function formatBytes(bytes) {
    if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
    if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${bytes} B`;
}
