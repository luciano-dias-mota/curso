(() => {
    const modal = document.querySelector('[data-access-modal]');
    if (!modal) return;

    const titleNode = modal.querySelector('[data-access-course]');
    const closeButton = modal.querySelector('[data-access-close]');
    let trigger = null;

    const openModal = (button) => {
        trigger = button;
        const courseTitle = button.dataset.courseTitle || 'este curso';

        if (titleNode) {
            titleNode.textContent = courseTitle;
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('portal-modal-open');
        closeButton?.focus();
    };

    const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('portal-modal-open');
        trigger?.focus();
        trigger = null;
    };

    document.querySelectorAll('[data-course-locked]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });

    modal.querySelectorAll('[data-access-dismiss]').forEach((element) => {
        element.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
