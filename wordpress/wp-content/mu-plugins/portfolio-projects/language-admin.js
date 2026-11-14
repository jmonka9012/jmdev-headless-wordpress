(function ($) {
  let language;
  const select = (locale) => {
    language = locale;
    $('.portfolio-editor [class*="portfolio-locale-"]').each(function () {
      this.hidden = !this.classList.contains(`portfolio-locale-${locale}`);
    });
    $('[data-portfolio-language]').each(function () {
      this.setAttribute('aria-pressed', String(this.dataset.portfolioLanguage === locale));
    });
  };
  $(document).on('click', '[data-portfolio-language]', function () { select(this.dataset.portfolioLanguage); });
  $(function () { const first = document.querySelector('[data-portfolio-language]'); if (first) select(first.dataset.portfolioLanguage); });
  acf.addAction('append', () => { if (language) select(language); });
})(jQuery);
