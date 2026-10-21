<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['product_id'] ?? 0);
    $mode = $_POST['mode'] ?? 'IN';                       // IN | OUT | COUNT
    $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $note = trim($_POST['note'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if (!in_array($mode, ['IN', 'OUT', 'COUNT'], true)) $mode = 'IN';
    if ($mode !== 'IN' && !is_owner()) { flash('Only the owner can remove stock or correct counts.', 'err'); redirect('stock.php'); }
    if ($id < 1 || $qty === false || $qty < ($mode === 'COUNT' ? 0 : 1)) {
        flash('Choose a product from the list and enter a valid quantity.', 'err'); redirect('stock.php');
    }
    global $pdo;
    try {
        $pdo->beginTransaction();
        $cur = db_q('SELECT quantity FROM products WHERE id=?' . lock_clause(), [$id])->fetch();
        if (!$cur) throw new RuntimeException('Product not found.');
        if ($mode === 'IN') {
            $r = apply_stock($id, $qty, 'IN', $note ?: 'Restock received');
        } elseif ($mode === 'OUT') {
            $r = apply_stock($id, -$qty, 'OUT', trim(($reason ?: 'Stock out') . ($note ? ' — ' . $note : '')));
        } else {
            $delta = $qty - (int)$cur['quantity'];
            if ($delta === 0) throw new RuntimeException('The count matches the system. No change needed.');
            $r = apply_stock($id, $delta, $delta > 0 ? 'IN' : 'OUT', 'Stock count correction: ' . $cur['quantity'] . ' → ' . $qty . ($note ? ' — ' . $note : ''));
        }
        $pdo->commit();
        flash($r['name'] . ': ' . $r['old'] . ' → ' . $r['new'] . ' in stock.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e instanceof RuntimeException ? $e->getMessage() : 'Could not save the stock change.', 'err');
    }
    redirect('stock.php');
}

$products = db_q('SELECT id,sku,name,unit,quantity,reorder_level FROM products WHERE is_active=1 ORDER BY name')->fetchAll();
foreach ($products as &$p) { $p['id'] = (int)$p['id']; $p['quantity'] = (int)$p['quantity']; $p['reorder_level'] = (int)$p['reorder_level']; }
unset($p);
$restock = db_q('SELECT id,name,unit,quantity,reorder_level FROM products WHERE is_active=1 AND quantity<=reorder_level ORDER BY quantity ASC, name LIMIT 10')->fetchAll();

// history filters
$ft = $_GET['t'] ?? ''; $fq = trim($_GET['q'] ?? '');
$where = []; $args = [];
if (in_array($ft, ['IN', 'OUT', 'SALE', 'VOID'], true)) { $where[] = 'sm.movement_type=?'; $args[] = $ft; }
if ($fq !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $args[] = "%$fq%"; $args[] = "%$fq%"; }
$moves = db_q('SELECT sm.*, p.name, p.sku, p.unit, u.full_name AS by_user FROM stock_movements sm JOIN products p ON p.id=sm.product_id LEFT JOIN users u ON u.id=sm.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sm.id DESC LIMIT 100', $args)->fetchAll();

$pre = ['product' => (int)($_GET['product'] ?? 0), 'type' => in_array($_GET['type'] ?? '', ['IN', 'OUT', 'COUNT'], true) ? $_GET['type'] : 'IN', 'qty' => (int)($_GET['qty'] ?? 0)];

$pageTitle = 'Stock In / Out'; $active = 'stock';
require __DIR__ . '/includes/header.php';
?>
<script>window.JV_STOCK = <?= json_encode(['products' => $products, 'pre' => $pre, 'owner' => is_owner()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<div class="split">
  <section class="card" id="stock-card">
    <div class="card-head"><div><h2>Record a stock change</h2><p>Deliveries from suppliers, damaged goods, or a physical count.</p></div></div>
    <form method="post" id="stock-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="product_id" id="s-id">
      <div class="form-grid">
        <label class="f full">Product <small>Type the name or scan the code, then pick from the list</small>
          <div class="input-ico"><?= icon('search') ?><input type="text" id="s-search" list="s-list" placeholder="Start typing a product name or code…" required></div></label>
        <datalist id="s-list"><?php foreach ($products as $p): ?><option value="<?= e($p['name'] . ' — ' . $p['sku']) ?>"><?php endforeach; ?></datalist>
        <div class="full hide" id="s-info" style="background:#faf8f3;border:1px solid var(--line);border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"></div>
        <div class="full seg" id="s-mode">
          <label><input type="radio" name="mode" value="IN" checked><span>＋ Stock in</span></label>
          <?php if (is_owner()): ?><label><input type="radio" name="mode" value="OUT"><span>− Stock out</span></label>
          <label><input type="radio" name="mode" value="COUNT"><span>✓ Correct count</span></label><?php endif; ?>
        </div>
        <label class="f"><span id="q-label">Quantity received</span><input type="number" name="quantity" id="s-qty" min="1" step="1" required placeholder="0"></label>
        <label class="f hide" id="reason-wrap">Reason<select name="reason"><option>Damaged</option><option>Lost / missing</option><option>Returned to supplier</option><option>Used in store</option><option>Other</option></select></label>
        <div class="full quickpay" id="s-quick" style="display:flex;gap:6px"><button type="button" data-n="1">+1</button><button type="button" data-n="5">+5</button><button type="button" data-n="10">+10</button><button type="button" data-n="20">+20</button><button type="button" data-n="50">+50</button></div>
        <label class="f full">Note <small>(optional) e.g. supplier name or delivery receipt no.</small><input type="text" name="note" maxlength="200" placeholder="Reference"></label>
      </div>
      <div class="callout hide" id="s-after" style="margin-top:14px"></div>
      <div class="form-foot"><button class="btn primary" type="submit" id="s-submit"><?= icon('check') ?> Save stock change</button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><div><h2>Needs restocking</h2><p>Tap one to fill the form with a suggested quantity.</p></div></div>
    <?php if (!$restock): ?><div class="empty-box"><?= icon('check') ?>Nothing to restock right now.</div><?php endif; ?>
    <div class="list">
    <?php foreach ($restock as $r): $sug = suggested_order($r['quantity'], $r['reorder_level']); ?>
      <div class="list-row"><div class="grow"><b><?= e($r['name']) ?></b><small><?= num($r['quantity']) ?> <?= e($r['unit']) ?> left · alert at <?= num($r['reorder_level']) ?></small></div>
        <?= status_pill($r['quantity'], $r['reorder_level']) ?>
        <button type="button" class="btn sm line fill" data-id="<?= (int)$r['id'] ?>" data-qty="<?= $sug ?>">+ <?= $sug ?></button></div>
    <?php endforeach; ?></div>
  </section>
</div>

<section class="card" style="margin-top:18px">
  <div class="card-head"><div><h2>Stock history</h2><p>Every change — including sales — with who did it. Latest 100 shown.</p></div>
    <form method="get" class="toolbar" style="margin:0">
      <div class="input-ico" style="min-width:200px"><?= icon('search') ?><input type="search" name="q" value="<?= e($fq) ?>" placeholder="Search product…"></div>
      <select name="t" onchange="this.form.submit()"><option value="">All types</option><?php foreach (['IN' => 'Stock in', 'OUT' => 'Stock out', 'SALE' => 'Sales', 'VOID' => 'Voided sales'] as $k => $v): ?><option value="<?= $k ?>" <?= $ft === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <button class="btn line sm">Filter</button>
    </form></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="num">Change</th><th class="num">Balance</th><th>Note</th><th>By</th></tr></thead><tbody>
    <?php if (!$moves): ?><tr><td colspan="7" class="empty">No stock movements found.</td></tr><?php endif; ?>
    <?php foreach ($moves as $m): $plus = in_array($m['movement_type'], ['IN', 'VOID'], true);
        $lab = ['IN' => ['ok', 'Stock in'], 'OUT' => ['low', 'Stock out'], 'SALE' => ['info', 'Sale'], 'VOID' => ['neutral', 'Void']][$m['movement_type']]; ?>
      <tr><td class="nowrap"><?= e(fmt_dt($m['created_at'])) ?></td><td><strong><?= e($m['name']) ?></strong><small><?= e($m['sku']) ?></small></td>
        <td><span class="pill <?= $lab[0] ?>"><?= $lab[1] ?></span></td>
        <td class="num"><strong style="color:var(--<?= $plus ? 'green' : 'red' ?>)"><?= $plus ? '+' : '−' ?><?= num($m['quantity']) ?></strong></td>
        <td class="num"><?= $m['balance_after'] === null ? '—' : num($m['balance_after']) ?></td>
        <td><?= e($m['note'] ?? '—') ?></td><td class="muted"><?= e($m['by_user'] ?? '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>

<script>
(function () {
  var D = window.JV_STOCK, $ = function (i) { return document.getElementById(i); };
  var byLabel = {}, byId = {}, bySku = {};
  D.products.forEach(function (p) { byLabel[(p.name + ' — ' + p.sku).toLowerCase()] = p; byId[p.id] = p; bySku[p.sku.toLowerCase()] = p; });
  var cur = null, search = $('s-search'), qty = $('s-qty');
  function mode() { return document.querySelector('input[name=mode]:checked').value; }
  function pick(p) {
    cur = p; $('s-id').value = p ? p.id : '';
    var info = $('s-info');
    if (!p) { info.classList.add('hide'); } else {
      var st = p.quantity <= 0 ? ['out', 'Out of stock'] : p.quantity <= p.reorder_level ? ['low', 'Low stock'] : ['ok', 'In stock'];
      info.innerHTML = '<div><b>' + JV.esc(p.name) + '</b><small>' + JV.esc(p.sku) + '</small></div><div style="text-align:right"><b style="font-size:20px">' + p.quantity + '</b> <span class="muted">' + JV.esc(p.unit) + '</span><br><span class="pill ' + st[0] + '">' + st[1] + '</span></div>';
      info.classList.remove('hide');
    }
    preview();
  }
  function preview() {
    var box = $('s-after'), n = parseInt(qty.value, 10), m = mode();
    if (!cur || isNaN(n)) { box.classList.add('hide'); return; }
    var after = m === 'IN' ? cur.quantity + n : m === 'OUT' ? cur.quantity - n : n;
    box.classList.remove('hide');
    if (after < 0) { box.innerHTML = 'Not enough stock — only ' + cur.quantity + ' ' + JV.esc(cur.unit) + ' available.'; $('s-submit').disabled = true; }
    else { box.innerHTML = 'New stock level will be <b>' + after + ' ' + JV.esc(cur.unit) + '</b> (now ' + cur.quantity + ').'; $('s-submit').disabled = false; }
  }
  search.addEventListener('input', function () {
    var v = search.value.trim().toLowerCase(); pick(byLabel[v] || bySku[v] || null);
  });
  qty.addEventListener('input', preview);
  document.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', function () {
    var m = mode();
    $('q-label').textContent = m === 'IN' ? 'Quantity received' : m === 'OUT' ? 'Quantity to remove' : 'Actual counted quantity';
    qty.min = m === 'COUNT' ? 0 : 1;
    $('reason-wrap').classList.toggle('hide', m !== 'OUT');
    $('s-quick').classList.toggle('hide', m === 'COUNT');
    preview();
  }); });
  $('s-quick').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; qty.value = (parseInt(qty.value, 10) || 0) + parseInt(b.dataset.n, 10); preview(); qty.focus(); });
  function fill(id, type, n) {
    var p = byId[id]; if (!p) return;
    search.value = p.name + ' — ' + p.sku; pick(p);
    var r = document.querySelector('input[name=mode][value=' + type + ']'); if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
    if (n > 0) qty.value = n; preview(); qty.focus(); qty.select();
    $('stock-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  document.querySelectorAll('.fill').forEach(function (b) { b.addEventListener('click', function () { fill(parseInt(b.dataset.id, 10), 'IN', parseInt(b.dataset.qty, 10)); }); });
  $('stock-form').addEventListener('submit', function (e) { if (!cur) { e.preventDefault(); JV.toast('Please pick a product from the list.', 'err'); search.focus(); } });
  if (D.pre.product) fill(D.pre.product, D.pre.type, D.pre.qty); else search.focus();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
