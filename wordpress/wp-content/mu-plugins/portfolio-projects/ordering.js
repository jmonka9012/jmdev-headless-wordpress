(function ($) {
  $(function () {
    const form = document.querySelector('.portfolio-order-form');
    if (!form) return;
    const list = form.querySelector('.portfolio-order-list');
    const initial = [...list.children];
    const status = form.querySelector('[data-order-status]');
    let dirty = false;

    const refresh = (announcement = '') => {
      const rows = [...list.children];
      dirty = rows.some((row, index) => row !== initial[index]);
      rows.forEach((row, index) => {
        row.querySelector('.portfolio-order-number').textContent = index + 1;
        row.querySelector('[data-order-move="up"]').disabled = index === 0;
        row.querySelector('[data-order-move="down"]').disabled = index === rows.length - 1;
      });
      form.querySelector('[data-order-save]').disabled = !dirty;
      form.querySelector('[data-order-reset]').disabled = !dirty;
      status.textContent = [announcement, dirty ? 'Niezapisane zmiany.' : 'Brak niezapisanych zmian.'].filter(Boolean).join(' ');
    };

    $(list).sortable({
      handle: '.portfolio-order-grip',
      axis: 'y',
      tolerance: 'pointer',
      placeholder: 'portfolio-order-placeholder',
      forcePlaceholderSize: true,
      update: function (_, ui) {
        const title = ui.item[0].querySelector('.portfolio-order-title a').textContent;
        refresh(`${title}: pozycja ${[...list.children].indexOf(ui.item[0]) + 1}.`);
      },
    });

    form.addEventListener('click', (event) => {
      const button = event.target.closest('[data-order-move]');
      if (!button) return;
      const row = button.closest('[data-order-id]');
      const up = button.dataset.orderMove === 'up';
      const sibling = up ? row.previousElementSibling : row.nextElementSibling;
      if (!sibling) return;
      if (up) sibling.before(row);
      else sibling.after(row);
      const title = row.querySelector('.portfolio-order-title a').textContent;
      refresh(`${title}: pozycja ${[...list.children].indexOf(row) + 1}.`);
      const focus = button.disabled ? row.querySelector(`[data-order-move="${up ? 'down' : 'up'}"]`) : button;
      focus.focus();
    });
    form.querySelector('[data-order-reset]').addEventListener('click', () => {
      initial.forEach(row => list.append(row));
      refresh('Przywrócono zapisaną kolejność.');
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });
    refresh();
  });
})(jQuery);
