/* Shared behaviour: menu, clock, toasts, dialogs, confirm, double-submit guard (needs jv.js) */
JV.toast = function (msg, type) {
  var box = document.getElementById('toasts'); if (!box) return;
  var t = document.createElement('div'); t.className = 'toast ' + (type || 'ok');
  t.innerHTML = '<span>' + JV.esc(msg) + '</span><button aria-label="Dismiss">✕</button>';
  box.appendChild(t); setup(t);
};
function setup(t) {
  var b = t.querySelector('button'); if (b) b.addEventListener('click', function () { t.remove(); });
  setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; setTimeout(function () { t.remove(); }, 450); }, t.classList.contains('err') ? 9000 : 5500);
}
document.querySelectorAll('.toast').forEach(setup);

/* mobile menu */
(function () {
  var sb = document.getElementById('sidebar'), sc = document.getElementById('scrim'), mb = document.getElementById('menu-btn');
  if (!sb || !mb) return;
  function toggle(open) { sb.classList.toggle('open', open); sc.classList.toggle('show', open); }
  mb.addEventListener('click', function () { toggle(!sb.classList.contains('open')); });
  sc.addEventListener('click', function () { toggle(false); });
})();

/* live clock */
(function () {
  var c = document.getElementById('clock'); if (!c) return;
  var timer;
  var formatter = new Intl.DateTimeFormat('en-PH', {
    weekday: 'short', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true
  });
  function tick() {
    var now = new Date();
    var parts = {};
    formatter.formatToParts(now).forEach(function (part) { parts[part.type] = part.value; });
    c.textContent = parts.weekday + ' · ' + parts.month + ' ' + parts.day + ', ' + parts.hour + ':' + parts.minute + ' ' + parts.dayPeriod;
    c.dateTime = now.toISOString();
    clearTimeout(timer);
    timer = setTimeout(tick, 60000 - (now.getSeconds() * 1000 + now.getMilliseconds()));
  }
  tick();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();

/* dialogs: <button data-open="id"> / <button data-close> */
document.addEventListener('click', function (ev) {
  var o = ev.target.closest('[data-open]');
  if (o) { var d = document.getElementById(o.getAttribute('data-open')); if (d && d.showModal) { d.showModal(); var f = d.querySelector('input:not([type=hidden]),select'); if (f && !o.hasAttribute('data-nofocus')) setTimeout(function () { f.focus(); }, 30); } return; }
  if (ev.target.closest('[data-close]')) { var dd = ev.target.closest('dialog'); if (dd) dd.close(); return; }
  if (ev.target.tagName === 'DIALOG') ev.target.close(); // click on backdrop
  var c = ev.target.closest('[data-confirm]');
  if (c && !confirm(c.getAttribute('data-confirm'))) ev.preventDefault();
});

/* stop double submits */
document.addEventListener('submit', function (ev) {
  var f = ev.target; if (ev.defaultPrevented) return;
  var b = f.querySelector('button[type=submit],button:not([type])');
  if (b && f.method && f.method.toLowerCase() === 'post') setTimeout(function () { b.disabled = true; }, 0);
});
