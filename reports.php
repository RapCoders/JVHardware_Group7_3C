<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$tab = in_array($_GET['tab'] ?? '', ['inventory', 'prices'], true) ? $_GET['tab'] : 'sales';
$today = date('Y-m-d');

/* ---- date range (quick ranges or custom) ---- */
$range = $_GET['range'] ?? '';
switch ($range) {
    case 'today':     $from = $to = $today; break;
    case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); break;
    case 'week':      $from = date('Y-m-d', strtotime('monday this week')); $to = $today; break;
    case 'lastmonth': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); break;
    case 'month':     $from = date('Y-m-01'); $to = $today; break;
    default:
        $from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $today;
        if (isset($_GET['from']) || isset($_GET['to'])) $range = 'custom'; else $range = 'month';
}
if ($from > $to) { $t = $from; $from = $to; $to = $t; }
$start = $from . ' 00:00:00'; $end = next_day($to) . ' 00:00:00';
$rangeLabel = $from === $to ? fmt_d($from) : fmt_d($from) . ' – ' . fmt_d($to);
$qs = function ($extra = []) use ($tab, $from, $to) { return http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $extra)); };

if ($tab === 'sales') {
    $sum = db_q("SELECT COUNT(*) AS c, COALESCE(SUM(subtotal),0) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<?", [$start, $end])->fetch();
    $itemsSold = (int)db_q("SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' AND s.sold_at>=? AND s.sold_at<?", [$start, $end])->fetchColumn();
    $voidCount = (int)db_q("SELECT COUNT(*) FROM sales WHERE status='voided' AND sold_at>=? AND sold_at<?", [$start, $end])->fetchColumn();
    $avg = $sum['c'] > 0 ? $sum['t'] / $sum['c'] : 0;
    $pay = db_q("SELECT payment_method, COUNT(*) AS c, SUM(subtotal) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<? GROUP BY payment_method ORDER BY t DESC", [$start, $end])->fetchAll();
    $top = db_q("SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.line_total) AS rev FROM sale_items si JOIN sales s ON s.id=si.sale_id
        WHERE s.status='completed' AND s.sold_at>=? AND s.sold_at<? GROUP BY si.product_id, si.product_name ORDER BY rev DESC LIMIT 8", [$start, $end])->fetchAll();
    $sales = db_q("SELECT s.*, (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id=s.id) AS items FROM sales s WHERE s.sold_at>=? AND s.sold_at<? ORDER BY s.sold_at DESC LIMIT 500", [$start, $end])->fetchAll();
    // per-day chart for ranges up to 31 days
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1; $chart = [];
    if ($days <= 31) {
        $by = [];
        foreach (db_q("SELECT DATE(sold_at) AS d, SUM(subtotal) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<? GROUP BY DATE(sold_at)", [$start, $end])->fetchAll() as $r) $by[$r['d']] = (float)$r['t'];
        for ($i = 0; $i < $days; $i++) { $d = date('Y-m-d', strtotime("$from +$i days")); $chart[] = ['label' => $days > 10 ? date('j', strtotime($d)) : date('D j', strtotime($d)), 'value' => $by[$d] ?? 0, 'tip' => date('M j', strtotime($d)) . ' — ' . money($by[$d] ?? 0)]; }
    }
} elseif ($tab === 'inventory') {
    $inv = db_q('SELECT * FROM products WHERE is_active=1 ORDER BY category, name')->fetchAll();
    $cat = []; $totVal = 0; $totUnits = 0; $low = [];
    foreach ($inv as $p) {
        $c = $p['category']; $v = $p['quantity'] * $p['price'];
        if (!isset($cat[$c])) $cat[$c] = ['n' => 0, 'u' => 0, 'v' => 0];
        $cat[$c]['n']++; $cat[$c]['u'] += $p['quantity']; $cat[$c]['v'] += $v; $totVal += $v; $totUnits += $p['quantity'];
        if ($p['quantity'] <= $p['reorder_level']) $low[] = $p;
    }
    usort($low, function ($a, $b) { return $a['quantity'] <=> $b['quantity']; });
} else {
    $changes = db_q('SELECT ph.*, p.name, p.sku, u.full_name AS by_user FROM price_history ph JOIN products p ON p.id=ph.product_id LEFT JOIN users u ON u.id=ph.user_id ORDER BY ph.id DESC LIMIT 100')->fetchAll();
}

$pageTitle = 'Reports'; $active = 'reports';
require __DIR__ . '/includes/header.php';
?>
<div class="print-only"><h2><?= e(setting('store_name', 'JV Hardware')) ?> — <?= ['sales' => 'Sales report', 'inventory' => 'Inventory report', 'prices' => 'Price changes'][$tab] ?></h2>
  <p><?= $tab === 'sales' ? e($rangeLabel) : 'As of ' . e(fmt_dt(now())) ?></p></div>

<div class="tabs report-tabs">
  <a href="reports.php?tab=sales" class="<?= $tab === 'sales' ? 'active' : '' ?>">Sales</a>
  <a href="reports.php?tab=inventory" class="<?= $tab === 'inventory' ? 'active' : '' ?>">Inventory &amp; reorder list</a>
  <a href="reports.php?tab=prices" class="<?= $tab === 'prices' ? 'active' : '' ?>">Price changes</a>
</div>

<?php if ($tab === 'sales'): ?>
<div class="toolbar no-print report-toolbar">
  <div class="chips">
    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month'] as $k => $v): ?>
      <a class="chip <?= $range === $k ? 'active' : '' ?>" href="reports.php?tab=sales&range=<?= $k ?>"><?= $v ?></a><?php endforeach; ?>
  </div>
  <form method="get" class="toolbar" style="margin:0 0 0 auto"><input type="hidden" name="tab" value="sales">
    <input type="date" name="from" value="<?= e($from) ?>" max="<?= $today ?>" required style="width:auto"><span class="muted">to</span>
    <input type="date" name="to" value="<?= e($to) ?>" max="<?= $today ?>" required style="width:auto"><button class="btn dark sm">Apply</button></form>
  <a class="btn line sm" href="export.php?<?= $qs(['type' => 'sales']) ?>"><?= icon('download') ?> CSV</a>
  <button class="btn line sm" onclick="window.print()"><?= icon('print') ?> Print</button>
</div>
<p class="muted report-range">Showing <b style="color:var(--ink)"><?= e($rangeLabel) ?></b></p>

<div class="grid g4 report-stats" style="margin-bottom:18px">
  <article class="stat hero"><div class="lab">Total sales <span class="ico"><?= icon('trend') ?></span></div><div class="val"><?= money($sum['t']) ?></div><div class="sub"><?= num($sum['c']) ?> transactions</div></article>
  <article class="stat"><div class="lab">Average sale <span class="ico green"><?= icon('receipt') ?></span></div><div class="val"><?= money($avg) ?></div><div class="sub">per transaction</div></article>
  <article class="stat"><div class="lab">Items sold <span class="ico blue"><?= icon('box') ?></span></div><div class="val"><?= num($itemsSold) ?></div><div class="sub">units across all products</div></article>
  <article class="stat"><div class="lab">Voided sales <span class="ico red"><?= icon('undo') ?></span></div><div class="val"><?= num($voidCount) ?></div><div class="sub">not counted in totals</div></article>
</div>

<div class="split report-dashboard" style="margin-bottom:18px">
  <div class="report-primary">
    <section class="card report-chart">
      <div class="card-head"><h2>Sales per day</h2></div>
      <?php if ($chart): echo bar_chart($chart); else: ?><div class="empty-box">Pick a range of 31 days or less to see the daily chart.</div><?php endif; ?>
    </section>
    <section class="card report-payment"><div class="card-head"><h2>By payment method</h2></div>
      <?php if (!$pay): ?><div class="empty-box">No sales in this period.</div><?php endif; ?>
      <div class="list"><?php foreach ($pay as $p): ?><div class="list-row"><div class="grow"><b><?= e($p['payment_method']) ?></b><small><?= num($p['c']) ?> sale<?= (int)$p['c'] === 1 ? '' : 's' ?></small></div><b><?= money($p['t']) ?></b></div><?php endforeach; ?></div></section>
  </div>
  <section class="card report-products">
    <div class="card-head"><h2>Top products</h2></div>
      <?php if (!$top): ?><div class="empty-box">No sales in this period.</div><?php endif; ?>
    <div class="list"><?php foreach ($top as $i => $t): ?><div class="list-row"><span class="rank"><?= $i + 1 ?></span><div class="grow"><b><?= e($t['product_name']) ?></b><small><?= num($t['qty']) ?> sold</small></div><b style="flex:none"><?= money($t['rev']) ?></b></div><?php endforeach; ?></div>
  </section>
</div>

<section class="card report-transactions">
  <div class="card-head"><div><h2>Sales transactions</h2><p><?= count($sales) >= 500 ? 'Showing the latest 500.' : 'Click a receipt to view or reprint it.' ?></p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Date &amp; time</th><th>Customer</th><th>Payment</th><th class="num">Items</th><th class="num">Total</th><th></th></tr></thead><tbody>
  <?php if (!$sales): ?><tr><td colspan="7" class="empty">No sales in this period.</td></tr><?php endif; ?>
  <?php foreach ($sales as $s): ?>
    <tr class="<?= $s['status'] === 'voided' ? 'voided' : '' ?>"><td><a class="link" href="receipt.php?id=<?= (int)$s['id'] ?>"><strong><?= e($s['receipt_no']) ?></strong></a></td><td class="nowrap"><?= e(fmt_dt($s['sold_at'])) ?></td>
      <td><?= e($s['customer_name'] ?: 'Walk-in customer') ?></td><td><span class="pill plain"><?= e($s['payment_method']) ?></span></td>
      <td class="num"><?= num($s['items']) ?></td><td class="num"><strong><?= money($s['subtotal']) ?></strong></td>
      <td class="right"><?= $s['status'] === 'voided' ? '<span class="pill out">Voided</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>

<?php elseif ($tab === 'inventory'): ?>
<div class="page-head no-print"><div><p>Current stock value and what to order from suppliers.</p></div>
  <div class="actions"><a class="btn line" href="export.php?type=inventory"><?= icon('download') ?> CSV</a><button class="btn line" onclick="window.print()"><?= icon('print') ?> Print</button></div></div>
<div class="grid g4" style="margin-bottom:18px">
  <article class="stat hero"><div class="lab">Inventory value <span class="ico"><?= icon('box') ?></span></div><div class="val"><?= money($totVal) ?></div><div class="sub">at selling prices</div></article>
  <article class="stat"><div class="lab">Products <span class="ico blue"><?= icon('grid') ?></span></div><div class="val"><?= num(count($inv)) ?></div><div class="sub"><?= num(count($cat)) ?> categories</div></article>
  <article class="stat"><div class="lab">Units in stock <span class="ico green"><?= icon('swap') ?></span></div><div class="val"><?= num($totUnits) ?></div><div class="sub">all products</div></article>
  <article class="stat <?= $low ? 'warn' : '' ?>"><div class="lab">To reorder <span class="ico orange"><?= icon('alert') ?></span></div><div class="val"><?= num(count($low)) ?></div><div class="sub">at or below alert level</div></article>
</div>
<section class="card"><div class="card-head"><div><h2>Reorder list</h2><p>Suggested quantity brings stock back to twice the alert level.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Product</th><th>Category</th><th class="num">In stock</th><th class="num">Alert level</th><th class="num">Suggested order</th><th>Status</th></tr></thead><tbody>
  <?php if (!$low): ?><tr><td colspan="6" class="empty">Nothing to reorder. All products are above their alert level.</td></tr><?php endif; ?>
  <?php foreach ($low as $p): ?><tr><td><strong><?= e($p['name']) ?></strong><small><?= e($p['sku']) ?></small></td><td><?= e($p['category']) ?></td><td class="num"><?= num($p['quantity']) ?> <?= e($p['unit']) ?></td><td class="num"><?= num($p['reorder_level']) ?></td><td class="num"><strong><?= num(suggested_order($p['quantity'], $p['reorder_level'])) ?> <?= e($p['unit']) ?></strong></td><td><?= status_pill($p['quantity'], $p['reorder_level']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<section class="card"><div class="card-head"><h2>Stock value by category</h2></div>
  <div class="table-wrap"><table><thead><tr><th>Category</th><th class="num">Products</th><th class="num">Units</th><th class="num">Value</th></tr></thead><tbody>
  <?php foreach ($cat as $name => $c): ?><tr><td><strong><?= e($name) ?></strong></td><td class="num"><?= num($c['n']) ?></td><td class="num"><?= num($c['u']) ?></td><td class="num"><strong><?= money($c['v']) ?></strong></td></tr><?php endforeach; ?>
  </tbody></table></div></section>

<?php else: ?>
<div class="page-head no-print"><div><p>Every selling-price change, who made it and when.</p></div><div class="actions"><a class="btn line" href="export.php?type=prices"><?= icon('download') ?> CSV</a><button class="btn line" onclick="window.print()"><?= icon('print') ?> Print</button></div></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Product</th><th class="num">Old price</th><th class="num">New price</th><th class="num">Change</th><th>Changed by</th></tr></thead><tbody>
  <?php if (!$changes): ?><tr><td colspan="6" class="empty">No price changes yet. Edit a product's price on the Products page and it will be logged here.</td></tr><?php endif; ?>
  <?php foreach ($changes as $c): $d = $c['new_price'] - $c['old_price']; ?>
    <tr><td class="nowrap"><?= e(fmt_dt($c['changed_at'])) ?></td><td><strong><?= e($c['name']) ?></strong><small><?= e($c['sku']) ?></small></td><td class="num"><?= money($c['old_price']) ?></td><td class="num"><strong><?= money($c['new_price']) ?></strong></td>
      <td class="num"><span class="pill <?= $d > 0 ? 'low' : 'ok' ?>"><?= $d > 0 ? '▲' : '▼' ?> <?= money(abs($d)) ?></span></td><td class="muted"><?= e($c['by_user'] ?? '—') ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
