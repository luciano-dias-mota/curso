(() => {
  'use strict';

  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';

  function randomPassword(length = 14) {
    const values = new Uint32Array(length);
    crypto.getRandomValues(values);
    return Array.from(values, value => alphabet[value % alphabet.length]).join('');
  }

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-generate-password]');
    if (!trigger) return;

    const password = randomPassword();
    const target = document.getElementById(trigger.dataset.passwordTarget || '');
    const confirmation = document.getElementById(trigger.dataset.confirmTarget || '');
    if (target) {
      target.value = password;
      target.type = 'text';
    }
    if (confirmation) {
      confirmation.value = password;
      confirmation.type = 'text';
    }
    trigger.textContent = 'Senha gerada e preenchida';
  });

  document.addEventListener('submit', event => {
    const form = event.target.closest('form[data-confirm]');
    if (!form) return;
    if (!window.confirm(form.dataset.confirm || 'Confirmar esta ação?')) {
      event.preventDefault();
    }
  });
})();
