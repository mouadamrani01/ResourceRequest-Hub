'use strict';

document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
  select.addEventListener('change', () => select.form?.requestSubmit());
});

document.querySelectorAll('button[data-confirm]').forEach((button) => {
  button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  });
});
