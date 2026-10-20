/* Registers the offline cache and handles the "install as app" prompt. Kept separate from app.js so chat errors cannot block it. */
(function () {
  if ('serviceWorker' in navigator && window.matinPWA && window.matinPWA.sw) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(window.matinPWA.sw, { scope: '/' }).catch(function () {});
    });
  }
  var deferredPrompt = null;
  function buttons() { return document.querySelectorAll('[data-install-app]'); }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    buttons().forEach(function (button) { button.hidden = false; });
  });
  buttons().forEach(function (button) {
    button.addEventListener('click', function () {
      if (!deferredPrompt) { return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        buttons().forEach(function (b) { b.hidden = true; });
      });
    });
  });
  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    buttons().forEach(function (b) { b.hidden = true; });
  });
})();
