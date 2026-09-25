(function () {
  'use strict';

  var pad = function (n) { return (n < 10 ? '0' : '') + n; };

  // Orologio live nella pagina "Oggi"
  var clock = document.getElementById('clock');
  if (clock) {
    setInterval(function () {
      var d = new Date();
      clock.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
    }, 1000);
  }

  // Ore lavorate in tempo reale mentre il turno è aperto
  var live = document.getElementById('live');
  if (live && live.dataset.start) {
    var start = parseInt(live.dataset.start, 10) * 1000;
    var closed = parseInt(live.dataset.closed || '0', 10);
    var pausa = parseInt(live.dataset.pausa || '0', 10);
    var tick = function () {
      var min = closed + Math.max(0, Math.floor((Date.now() - start) / 60000)) - pausa;
      min = Math.max(0, min);
      live.textContent = Math.floor(min / 60) + ':' + pad(min % 60);
    };
    tick();
    setInterval(tick, 15000);
  }

  // Pulsanti "Ora" accanto ai campi orario
  document.querySelectorAll('[data-now]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentNode.querySelector('input');
      var d = new Date();
      input.value = pad(d.getHours()) + ':' + pad(d.getMinutes());
      input.dispatchEvent(new Event('change'));
    });
  });

  // Mostra i campi orario solo per i tipi di giornata lavorativi
  var tipo = document.getElementById('tipo');
  var work = document.querySelector('.work-fields');
  if (tipo && work) {
    var workTypes = (work.dataset.work || '').split(',');
    var sync = function () { work.classList.toggle('hidden', workTypes.indexOf(tipo.value) === -1); };
    tipo.addEventListener('change', sync);
    sync();
  }

  // Evita doppi invii (es. doppio tocco sul pulsante di timbratura)
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.method.toLowerCase() !== 'post') return;
      setTimeout(function () {
        if (e.defaultPrevented) return;
        f.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });

  // Service worker (richiede HTTPS) e pulsante di installazione su Android
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  }
  var deferred = null;
  var installBtn = document.getElementById('install-btn');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (installBtn) installBtn.hidden = false;
  });
  if (installBtn) {
    installBtn.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred = null;
      installBtn.hidden = true;
    });
  }
})();
