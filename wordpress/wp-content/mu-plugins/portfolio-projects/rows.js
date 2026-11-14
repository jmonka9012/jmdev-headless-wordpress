(function ($) {
  let counter = 0;
  const setExpanded = (row, expanded) => {
    row.children('.portfolio-rows__header').find('.portfolio-rows__toggle').attr('aria-expanded', String(expanded));
    row.children('.portfolio-rows__body').prop('hidden', !expanded);
  };
  const refresh = (container) => {
    const rows = container.children('.portfolio-rows__list').children('.portfolio-rows__row');
    container.children('.portfolio-rows__toolbar').find('.portfolio-rows__count').text(`Liczba elementów: ${rows.length}`);
    container.children('.portfolio-rows__empty').prop('hidden', rows.length > 0);
    rows.each(function (index) {
      const row = $(this);
      const header = row.children('.portfolio-rows__header');
      const value = row.children('.portfolio-rows__body').children('.acf-field').find('input[name$="[title]"], input[name$="[caption]"], input[name$="_title]"], input[name$="_caption]"]').filter(function () { return $(this).closest('.portfolio-rows')[0] === container[0]; }).first().val();
      header.find('.portfolio-rows__number').text(`${container.attr('data-row-label')} ${index + 1}`);
      header.find('.portfolio-rows__title').text(value?.trim() || (row.hasClass('portfolio-rows__row--image') ? 'Zdjęcie bez podpisu' : 'Nowe zagadnienie'));
      header.find('[data-portfolio-action="up"]').prop('disabled', index === 0);
      header.find('[data-portfolio-action="down"]').prop('disabled', index === rows.length - 1);
    });
  };
  const initialize = (context) => {
    $(context).find('.portfolio-rows').addBack('.portfolio-rows').each(function () { refresh($(this)); });
  };
  $(function () { initialize(document); });
  acf.addAction('append', initialize);
  acf.addAction('invalid_field', function (field) {
    field.$el.find('.portfolio-rows__row').add(field.$el.parents('.portfolio-rows__row')).each(function () { setExpanded($(this), true); });
  });
  $(document).on('input', '.portfolio-rows input[name$="[title]"], .portfolio-rows input[name$="[caption]"]', function () {
    refresh($(this).closest('.portfolio-rows'));
  });
  $(document).on('click', '[data-portfolio-action]', function () {
    const button = $(this);
    const container = button.closest('.portfolio-rows');
    const list = container.children('.portfolio-rows__list');
    const row = button.closest('.portfolio-rows__row');
    const action = button.attr('data-portfolio-action');
    if (action === 'toggle') {
      setExpanded(row, button.attr('aria-expanded') !== 'true');
      return;
    }
    if (action === 'expand' || action === 'collapse') {
      list.children('.portfolio-rows__row').each(function () { setExpanded($(this), action === 'expand'); });
      return;
    }
    if (action === 'add') {
      const id = `new_${Date.now()}_${counter++}`;
      const template = container.children('template')[0];
      // ACF WYSIWYG generates its own editor ID independently of the field key.
      const markup = template.innerHTML.replaceAll(container.attr('data-row-token'), id)
        .replace(/acf-editor-[a-f0-9]+/g, (editorId) => `${editorId}_${id}`);
      const added = $(markup);
      list.append(added);
      setExpanded(added, true);
      acf.doAction('append', added);
      added.find('input[type="text"], textarea').first().trigger('focus');
    } else if (action === 'remove') {
      const focusTarget = row.next().find('.portfolio-rows__toggle').first();
      const previous = row.prev().find('.portfolio-rows__toggle').first();
      acf.doAction('remove', row);
      row.remove();
      (focusTarget.length ? focusTarget : previous.length ? previous : container.children('[data-portfolio-action="add"]')).trigger('focus');
    } else {
      const sibling = action === 'up' ? row.prev() : row.next();
      if (!sibling.length) return;
      acf.doAction('sortstart', row);
      if (action === 'up') row.insertBefore(sibling);
      else row.insertAfter(sibling);
      acf.doAction('sortstop', row);
      button.trigger('focus');
    }
    refresh(container);
    // ACF validation must run again after changes to the row structure.
    container.trigger('change');
  });
})(jQuery);
