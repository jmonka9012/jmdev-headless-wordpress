(function ($) {
  let language;
  const select = (locale) => {
    language = locale;
    $('.portfolio-admin [class*="portfolio-locale-"]').each(function () {
      this.hidden = !this.classList.contains(`portfolio-locale-${locale}`);
    });
    $('[data-portfolio-language]').each(function () {
      this.setAttribute('aria-pressed', String(this.dataset.portfolioLanguage === locale));
    });
  };
  $(document).on('click', '[data-portfolio-language]', function () { select(this.dataset.portfolioLanguage); });
  $(function () {
    const buttons = [...document.querySelectorAll('[data-portfolio-language]')];
    const requested = new URLSearchParams(window.location.search).get('language');
    const active = buttons.find(button => button.dataset.portfolioLanguage === requested) || buttons[0];
    if (active) select(active.dataset.portfolioLanguage);
  });
  acf.addAction('append', () => { if (language) select(language); });
  let dirty = false;
  $(document).on('input change', '[data-portfolio-unsaved]', function () { dirty = true; });
  $(document).on('submit', '[data-portfolio-unsaved]', function () { if (window.tinymce) window.tinymce.triggerSave(); dirty = false; });
  acf.addAction('wysiwyg_tinymce_init', function (editor, id, settings, field) {
    if (field.$el.closest('[data-portfolio-unsaved]').length) { editor.on('change keyup undo redo', function () { dirty = true; }); }
  });
  window.addEventListener('beforeunload', function (event) { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})(jQuery);
