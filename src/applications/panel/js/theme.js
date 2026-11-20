// Dark / light ("denný režim") toggle. The server renders <html data-theme> from the cookie panel_theme,
// so the page never flashes the wrong theme; this script only flips the attribute and stores the choice for 1 year.
(function () {
  var root = document.documentElement;
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-theme-toggle]') : null;
    if (!btn) return;
    var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    document.cookie = 'panel_theme=' + next + '; path=' + (root.getAttribute('data-theme-path') || '/') +
      '; max-age=31536000; SameSite=Lax';
  });
})();
