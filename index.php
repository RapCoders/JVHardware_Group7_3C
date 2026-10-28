<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$today = date('Y-m-d');
$sumSql = "SELECT COUNT(*) AS c, COALESCE(SUM(subtotal),0) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<?";
$todayRow = db_q($sumSql, [$today . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetch();
$yday = date('Y-m-d', strtotime('-1 day'));
$ydayRow = db_q($sumSql, [$yday . ' 00:00:00', $today . ' 00:00:00'])->fetch();
$monthRow = db_q($sumSql, [date('Y-m-01') . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetch();

$inv = db_q("SELECT COUNT(*) AS n, COALESCE(SUM(quantity),0) AS units, COALESCE(SUM(quantity*price),0) AS val,
  COALESCE(SUM(CASE WHEN quantity<=0 THEN 1 ELSE 0 END),0) AS outc,
  COALESCE(SUM(CASE WHEN quantity>0 AND quantity<=reorder_level THEN 1 ELSE 0 END),0) AS lowc
  FROM products WHERE is_active=1")->fetch();
$inStockCount = max(0, (int)$inv['n'] - (int)$inv['lowc'] - (int)$inv['outc']);
$stockTotal = (int)$inv['n'];
$stockInPct = $stockTotal ? $inStockCount / $stockTotal * 100 : 0;
$stockLowPct = $stockTotal ? (int)$inv['lowc'] / $stockTotal * 100 : 0;
$stockOutPct = $stockTotal ? (int)$inv['outc'] / $stockTotal * 100 : 0;

// last 7 days chart
$from7 = date('Y-m-d', strtotime('-6 days'));
$byDay = [];
foreach (db_q("SELECT DATE(sold_at) AS d, SUM(subtotal) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<? GROUP BY DATE(sold_at)", [$from7 . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetchAll() as $r) $byDay[$r['d']] = (float)$r['t'];
$chart = []; $weekTotal = 0;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $v = $byDay[$d] ?? 0; $weekTotal += $v;
    $chart[] = ['label' => $i === 0 ? 'Today' : date('D', strtotime($d)), 'value' => $v, 'tip' => date('M j', strtotime($d)) . ' — ' . money($v)];
}

$restock = db_q('SELECT * FROM products WHERE is_active=1 AND quantity<=reorder_level ORDER BY quantity ASC, name LIMIT 7')->fetchAll();
$top = db_q("SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.line_total) AS rev FROM sale_items si JOIN sales s ON s.id=si.sale_id
  WHERE s.status='completed' AND s.sold_at>=? AND s.sold_at<? GROUP BY si.product_id, si.product_name ORDER BY rev DESC LIMIT 5",
  [date('Y-m-01') . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetchAll();
$recent = db_q('SELECT id,receipt_no,customer_name,payment_method,subtotal,status,sold_at FROM sales ORDER BY id DESC LIMIT 6')->fetchAll();

$diff = null;
if ((float)$ydayRow['t'] > 0) $diff = round(((float)$todayRow['t'] - (float)$ydayRow['t']) / (float)$ydayRow['t'] * 100);

$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', current_user()['full_name'])[0];

// Gentle reminder to change the default owner password
$defaultPw = false;
if (is_owner() && password_verify('admin123', current_user()['password_hash'])) $defaultPw = true;

$pageTitle = 'Dashboard'; $active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head dashboard-heading">
  <div><h2>Store Summary</h2><p><?= $greet ?>, <?= e($firstName) ?>. Here is how the store is doing today.</p></div>
  <div class="actions no-print">
    <a class="btn line" href="export.php?type=sales&amp;from=<?= e($from7) ?>&amp;to=<?= e($today) ?>"><?= icon('download') ?> Export report</a>
    <a class="btn primary" href="sales.php"><?= icon('cart') ?> New sale</a>
    <a class="btn line" href="stock.php"><?= icon('swap') ?> Add stock</a>
    <?php if (can('prices')): ?><a class="btn line" href="products.php?new=1"><?= icon('plus') ?> New product</a><?php endif; ?>
  </div>
</div>

<?php if ($defaultPw): ?>
<div class="callout" style="margin-bottom:18px"><?= icon('alert') ?><div><b>Security tip:</b> you are still using the default password. <a class="link" href="settings.php">Change it in Settings →</a></div></div>
<?php endif; ?>

<div class="grid g4 dashboard-stats" style="margin-bottom:18px">
  <article class="stat hero"><div class="lab">Today's sales <span class="ico"><?= icon('trend') ?></span></div>
    <div class="val"><?= money($todayRow['t']) ?></div>
    <div class="sub"><?= num($todayRow['c']) ?> transaction<?= (int)$todayRow['c'] === 1 ? '' : 's' ?>
      <?php if ($diff !== null): ?> · <span class="<?= $diff >= 0 ? 'up' : 'down' ?>"><?= $diff >= 0 ? '▲' : '▼' ?> <?= abs($diff) ?>% vs yesterday</span><?php endif; ?></div></article>
  <article class="stat"><div class="lab">Total transactions <span class="ico green"><?= icon('receipt') ?></span></div>
    <div class="val"><?= num($todayRow['c']) ?></div><div class="sub">today · <?= money($monthRow['t']) ?> sales this month</div></article>
  <article class="stat"><div class="lab">Products in stock <span class="ico blue"><?= icon('box') ?></span></div>
    <div class="val"><?= num(max(0, (int)$inv['n'] - (int)$inv['outc'])) ?></div><div class="sub"><?= num($inv['units']) ?> units · inventory value <?= money($inv['val']) ?></div></article>
  <article class="stat <?= ($inv['lowc'] + $inv['outc']) > 0 ? 'warn' : '' ?>"><div class="lab">Low stock items <span class="ico orange"><?= icon('alert') ?></span></div>
    <div class="val"><?= num($inv['lowc'] + $inv['outc']) ?></div><div class="sub"><?= num($inv['lowc']) ?> low · <?= num($inv['outc']) ?> out of stock</div></article>
</div>

<div class="split dashboard-grid">
  <div class="grid dashboard-primary">
    <section class="card dashboard-chart">
      <div class="card-head"><div><h2>Sales — last 7 days</h2><p>Total <?= money($weekTotal) ?></p></div><a class="link" href="reports.php">Full report →</a></div>
      <?= bar_chart($chart) ?>
    </section>
    <section class="card dashboard-recent">
      <div class="card-head"><h2>Recent sales</h2><a class="link" href="reports.php">View all →</a></div>
      <?php if (!$recent): ?><div class="empty-box"><?= icon('receipt') ?>No sales yet. <a class="link" href="sales.php">Record the first sale</a></div><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Customer</th><th>Payment</th><th class="num">Total</th></tr></thead><tbody>
        <?php foreach ($recent as $s): ?>
        <tr class="<?= $s['status'] === 'voided' ? 'voided' : '' ?>"><td><a class="link" href="receipt.php?id=<?= (int)$s['id'] ?>"><strong><?= e($s['receipt_no']) ?></strong></a><small><?= e(fmt_dt($s['sold_at'])) ?></small></td>
          <td><?= e($s['customer_name'] ?: 'Walk-in customer') ?></td><td><span class="pill plain"><?= e($s['payment_method']) ?></span></td><td class="num"><strong><?= money($s['subtotal']) ?></strong></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  </div>
  <div class="grid dashboard-secondary">
    <section class="card dashboard-stock-status">
      <div class="card-head"><div><h2>Stock status</h2><p><?= num($stockTotal) ?> active products</p></div><a class="link" href="products.php">View products →</a></div>
      <div class="stock-overview">
        <div class="stock-donut" role="img" aria-label="<?= num($inStockCount) ?> products in stock, <?= num($inv['lowc']) ?> low stock, <?= num($inv['outc']) ?> out of stock" style="--stock-in:<?= round($stockInPct, 2) ?>%;--stock-low:<?= round($stockLowPct, 2) ?>%;--stock-out:<?= round($stockOutPct, 2) ?>%">
          <div><strong><?= num($stockTotal) ?></strong><span>Products</span></div>
        </div>
        <div class="stock-legend">
          <div><i class="in"></i><span>In stock</span><b><?= num($inStockCount) ?></b><small><?= number_format($stockInPct, 1) ?>%</small></div>
          <div><i class="low"></i><span>Low stock</span><b><?= num($inv['lowc']) ?></b><small><?= number_format($stockLowPct, 1) ?>%</small></div>
          <div><i class="out"></i><span>Out of stock</span><b><?= num($inv['outc']) ?></b><small><?= number_format($stockOutPct, 1) ?>%</small></div>
        </div>
      </div>
    </section>
    <section class="card dashboard-restock">
      <div class="card-head"><div><h2>Restock alerts</h2><p>At or below the reorder level</p></div><a class="link" href="stock.php">Open stock →</a></div>
      <?php if (!$restock): ?><div class="empty-box"><?= icon('check') ?>All products are well stocked.</div><?php endif; ?>
      <div class="list">
      <?php foreach ($restock as $p): ?>
        <div class="list-row"><div class="grow"><b><?= e($p['name']) ?></b><small><?= num($p['quantity']) ?> <?= e($p['unit']) ?> left · reorder at <?= num($p['reorder_level']) ?></small></div>
          <?= status_pill($p['quantity'], $p['reorder_level']) ?>
          <a class="btn sm line" href="stock.php?product=<?= (int)$p['id'] ?>&type=IN&qty=<?= suggested_order($p['quantity'], $p['reorder_level']) ?>">Restock</a></div>
      <?php endforeach; ?></div>
    </section>
    <section class="card dashboard-bestsellers">
      <div class="card-head"><div><h2>Best sellers</h2><p>This month, by sales amount</p></div></div>
      <?php if (!$top): ?><div class="empty-box">Best sellers appear after the first sales.</div><?php endif; ?>
      <div class="list"><?php foreach ($top as $i => $t): ?>
        <div class="list-row"><span class="rank"><?= $i + 1 ?></span><div class="grow"><b><?= e($t['product_name']) ?></b><small><?= num($t['qty']) ?> sold</small></div><b style="flex:none"><?= money($t['rev']) ?></b></div>
      <?php endforeach; ?></div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
