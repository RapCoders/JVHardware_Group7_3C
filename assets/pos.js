/* Record Sale screen: product picker + cart + live totals */
(function () {
  var P = window.JV_PRODUCTS || [], byId = {}, cart = {}, cat = '';
  P.forEach(function (p) { byId[p.id] = p; });
  var $ = function (id) { return document.getElementById(id); };
  var q = $('q'), grid = $('pgrid'), lines = $('lines'), none = $('no-results');
  var esc = JV.esc, money = JV.money;

  function stockClass(p) { return p.quantity <= 0 ? 'out' : (p.quantity <= p.reorder_level ? 'low' : ''); }
  function matches(p, t) { return !t || (p.name + ' ' + p.sku + ' ' + p.category).toLowerCase().indexOf(t) > -1; }
  function visible() {
    var t = q.value.trim().toLowerCase();
    return P.filter(function (p) { return (!cat || p.category === cat) && matches(p, t); });
  }

  function renderGrid() {
    var list = visible();
    none.classList.toggle('hide', list.length > 0);
    grid.innerHTML = list.map(function (p) {
      var inCart = cart[p.id] || 0, sc = stockClass(p);
      return '<button type="button" class="pcard' + (inCart ? ' in-cart' : '') + '" data-id="' + p.id + '"' + (p.quantity <= 0 ? ' disabled' : '') + '>' +
        '<span class="stk ' + sc + '">' + (p.quantity <= 0 ? 'Out' : p.quantity + ' left') + '</span>' +
        '<b>' + esc(p.name) + '</b><span class="meta">' + esc(p.sku) + ' · ' + esc(p.unit) + '</span>' +
        '<span class="price">' + money(p.price) + '</span>' + (inCart ? '<span class="inc">× ' + inCart + '</span>' : '') + '</button>';
    }).join('');
  }

  function add(id, n) {
    var p = byId[id]; if (!p) return;
    var cur = cart[id] || 0, next = cur + (n || 1);
    if (next > p.quantity) { JV.toast('Only ' + p.quantity + ' ' + p.unit + ' of ' + p.name + ' in stock.', 'err'); next = p.quantity; }
    if (next <= 0) delete cart[id]; else cart[id] = next;
    render();
  }
  function setQty(id, v) {
    var p = byId[id], n = parseInt(v, 10);
    if (isNaN(n) || n <= 0) { delete cart[id]; }
    else { if (n > p.quantity) { JV.toast('Only ' + p.quantity + ' ' + p.unit + ' in stock.', 'err'); n = p.quantity; } cart[id] = n; }
    render();
  }

  function total() { var s = 0; Object.keys(cart).forEach(function (id) { s += cart[id] * byId[id].price; }); return Math.round(s * 100) / 100; }
  function count() { var s = 0; Object.keys(cart).forEach(function (id) { s += cart[id]; }); return s; }
  function method() { return document.querySelector('input[name=payment_method]:checked').value; }

  function renderCart() {
    var ids = Object.keys(cart);
    if (!ids.length) {
      lines.innerHTML = '<div class="cart-empty"><svg class="i"><use href="#i-cart"/></svg>Cart is empty.<br>Tap a product to add it.</div>';
    } else {
      lines.innerHTML = ids.map(function (id) {
        var p = byId[id], q = cart[id];
        return '<div class="cline" data-id="' + id + '"><div><div class="nm">' + esc(p.name) + '</div><div class="un">' + money(p.price) + ' / ' + esc(p.unit) + '</div></div>' +
          '<div class="lt">' + money(q * p.price) + '</div>' +
          '<button type="button" class="rm" data-rm>Remove</button>' +
          '<div class="qty"><button type="button" data-dec aria-label="Less"><svg class="i"><use href="#i-minus"/></svg></button>' +
          '<input type="number" min="0" max="' + p.quantity + '" value="' + q + '" aria-label="Quantity">' +
          '<button type="button" data-inc aria-label="More"><svg class="i"><use href="#i-plus"/></svg></button></div></div>';
      }).join('');
    }
  }

  function totals() {
    var t = total(), isCash = method() === 'Cash', paidEl = $('paid');
    $('cash-box').classList.toggle('hide', !isCash);
    $('gcash-box').classList.toggle('hide', isCash);
    $('t-items').textContent = count(); $('t-total').textContent = money(t);
    $('mb-items').textContent = count() + ' item' + (count() === 1 ? '' : 's'); $('mb-total').textContent = money(t);
    var paid = parseFloat(paidEl.value) || 0, ok = t > 0;
    var row = $('chg-row');
    if (isCash) {
      var diff = Math.round((paid - t) * 100) / 100;
      row.classList.toggle('short', diff < 0 && t > 0);
      $('chg-label').textContent = diff < 0 && t > 0 ? 'Still needed' : 'Change';
      $('t-change').textContent = money(t > 0 ? Math.abs(diff) : 0);
      if (diff < 0) ok = false;
    } else { row.classList.remove('short'); $('chg-label').textContent = 'Change'; $('t-change').textContent = money(0); }
    $('complete').disabled = !ok;
    $('f-cart').value = JSON.stringify(cart);
    $('f-paid').value = isCash ? (paid || 0) : t;
  }

  function render() { renderGrid(); renderCart(); totals(); }

  /* events */
  grid.addEventListener('click', function (e) { var c = e.target.closest('.pcard'); if (c && !c.disabled) add(parseInt(c.dataset.id, 10), 1); });
  lines.addEventListener('click', function (e) {
    var row = e.target.closest('.cline'); if (!row) return; var id = row.dataset.id;
    if (e.target.closest('[data-inc]')) add(id, 1);
    else if (e.target.closest('[data-dec]')) add(id, -1);
    else if (e.target.closest('[data-rm]')) { delete cart[id]; render(); }
  });
  lines.addEventListener('change', function (e) { var row = e.target.closest('.cline'); if (row && e.target.matches('input')) setQty(row.dataset.id, e.target.value); });
  q.addEventListener('input', renderGrid);
  q.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return; e.preventDefault();
    var t = q.value.trim().toLowerCase(); if (!t) return;
    var exact = P.filter(function (p) { return p.sku.toLowerCase() === t; })[0];
    var hit = exact || visible().filter(function (p) { return p.quantity > 0; })[0];
    if (hit) { add(hit.id, 1); q.value = ''; renderGrid(); } else JV.toast('No product found for "' + q.value + '".', 'err');
  });
  $('cats').addEventListener('click', function (e) {
    var b = e.target.closest('.chip'); if (!b) return;
    document.querySelectorAll('#cats .chip').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active');
    cat = b.dataset.cat; renderGrid();
  });
  $('clear').addEventListener('click', function () { if (count() && confirm('Clear the whole cart?')) { cart = {}; render(); } });
  document.querySelectorAll('input[name=payment_method]').forEach(function (r) { r.addEventListener('change', totals); });
  var copyGcash = $('copy-gcash-number');
  if (copyGcash) copyGcash.addEventListener('click', function () {
    var number = copyGcash.dataset.number;
    function copied() { copyGcash.textContent = 'Copied'; setTimeout(function () { copyGcash.textContent = 'Copy number'; }, 1600); }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(number).then(copied).catch(function () { JV.toast('Could not copy the GCash number.', 'err'); });
    else {
      var field = document.createElement('textarea'); field.value = number; field.style.position = 'fixed'; field.style.opacity = '0';
      document.body.appendChild(field); field.select();
      try { if (document.execCommand('copy')) copied(); else JV.toast('Could not copy the GCash number.', 'err'); }
      catch (error) { JV.toast('Could not copy the GCash number.', 'err'); }
      field.remove();
    }
  });
  $('paid').addEventListener('input', totals);
  document.querySelector('.quickpay').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    $('paid').value = b.hasAttribute('data-exact') ? total().toFixed(2) : b.dataset.amt; totals();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); q.focus(); q.select(); }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !$('complete').disabled) $('cart-card').requestSubmit();
  });
  $('cart-card').addEventListener('submit', function (e) { if (!count()) e.preventDefault(); });

  render();
})();
