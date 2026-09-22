document.addEventListener('click', (e) => {
  const trigger = e.target.closest('[data-confirm]');
  if (trigger && !confirm(trigger.dataset.confirm || 'Are you sure?')) e.preventDefault();
});
