document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
const service = document.querySelector('#service');
const passengers = document.querySelector('#passengers');
if (service && passengers) {
  const update = () => { passengers.max = service.selectedOptions[0]?.dataset.max || '50'; };
  service.addEventListener('change', update);
  update();
}
