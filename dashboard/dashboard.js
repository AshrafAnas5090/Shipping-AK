'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('[data-password-toggle]');
  const input = document.getElementById('admin-password');
  if (!toggle || !input) return;

  toggle.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    toggle.classList.toggle('is-visible', visible);
    input.focus({ preventScroll: true });
  });
});
