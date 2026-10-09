(() => {
  'use strict';

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;

    const input = document.getElementById(button.dataset.passwordToggle || '');
    if (!(input instanceof HTMLInputElement)) return;

    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
    button.setAttribute('title', showing ? 'Mostrar senha' : 'Ocultar senha');
  });
})();
