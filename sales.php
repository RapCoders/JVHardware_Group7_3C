<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cart = json_decode($_POST['cart'] ?? '[]', true);
    $customer = mb_substr(trim($_POST['customer_name'] ?? ''), 0, 150);
    $payment = $_POST['payment_method'] ?? 'Cash';
    $paid = filter_var($_POST['amount_paid'] ?? 0, FILTER_VALIDATE_FLOAT);
    if (!is_array($cart) || !count($cart)) {
        flash('Add at least one item to the sale.', 'err'); redirect('sales.php');
    }
    if (!in_array($payment, ['Cash', 'GCash'], true)) {
        flash('Choose Cash or GCash as the payment method.', 'err'); redirect('sales.php');
    }
    global $pdo;
    $saleId = 0;
    try {
        $pdo->beginTransaction();
        $lines = []; $subtotal = 0.0;
        ksort($cart);                       // lock rows in a fixed order (avoids deadlocks)
        foreach ($cart as $id => $rawQty) {
            $qty = filter_var($rawQty, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 0) throw new RuntimeException('Invalid quantity.');
            if ($qty === 0) continue;
            $p = db_q('SELECT id,sku,name,quantity,price,is_active FROM products WHERE id=?' . lock_clause(), [(int)$id])->fetch();
            if (!$p || !(int)$p['is_active']) throw new RuntimeException('A selected product is no longer available.');
            if ((int)$p['quantity'] < $qty) throw new RuntimeException('Not enough stock for ' . $p['name'] . '. Available: ' . $p['quantity'] . '.');
            $line = round($qty * (float)$p['price'], 2);   // price always comes from the database
            $subtotal += $line;
            $lines[] = ['p' => $p, 'qty' => $qty, 'line' => $line];
        }
        if (!$lines) throw new RuntimeException('Add at least one item to the sale.');
        $subtotal = round($subtotal, 2);
        if ($payment === 'Cash') {
            if ($paid === false || $paid + 0.001 < $subtotal) throw new RuntimeException('Cash received is less than the total.');
        } else { $paid = $subtotal; }
        $change = round(max(0, $paid - $subtotal), 2);

        // Sequential daily receipt number: JV-20261016-0007
        $today = date('Y-m-d');
        $n = (int)db_q('SELECT COUNT(*) FROM sales WHERE sold_at>=? AND sold_at<?', [$today . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetchColumn() + 1;
        do {
            $receipt = 'JV-' . date('Ymd') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (db_q('SELECT COUNT(*) FROM sales WHERE receipt_no=?', [$receipt])->fetchColumn() > 0);

        db_q("INSERT INTO sales(receipt_no,customer_name,payment_method,subtotal,amount_paid,change_amount,status,user_id,sold_at) VALUES(?,?,?,?,?,?,'completed',?,?)",
            [$receipt, $customer ?: null, $payment, $subtotal, $paid, $change, current_user()['id'], now()]);
        $saleId = (int)$pdo->lastInsertId();
        foreach ($lines as $l) {
            $p = $l['p'];
            db_q('INSERT INTO sale_items(sale_id,product_id,product_name,sku,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?)',
                [$saleId, $p['id'], $p['name'], $p['sku'], $l['qty'], $p['price'], $l['line']]);
            apply_stock((int)$p['id'], -$l['qty'], 'SALE', 'Sale ' . $receipt);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e instanceof RuntimeException ? $e->getMessage() : 'The sale could not be saved. Please try again.', 'err');
        redirect('sales.php');
    }
    redirect('receipt.php?id=' . $saleId . '&new=1');
}

$products = db_q('SELECT id,sku,name,category,unit,quantity,price,reorder_level FROM products WHERE is_active=1 ORDER BY name')->fetchAll();
foreach ($products as &$p) { $p['id'] = (int)$p['id']; $p['quantity'] = (int)$p['quantity']; $p['price'] = (float)$p['price']; $p['reorder_level'] = (int)$p['reorder_level']; }
unset($p);
$cats = array_values(array_unique(array_column($products, 'category')));
sort($cats);
$gcashName = setting('gcash_name');
$gcashNumber = setting('gcash_number');
$gcashQr = setting('gcash_qr');
$today = date('Y-m-d');
$td = db_q("SELECT COUNT(*) AS c, COALESCE(SUM(subtotal),0) AS t FROM sales WHERE status='completed' AND sold_at>=? AND sold_at<?", [$today . ' 00:00:00', next_day($today) . ' 00:00:00'])->fetch();

$pageTitle = 'Record Sale'; $active = 'sales';
$pageScripts = ['assets/pos.js'];
require __DIR__ . '/includes/header.php';
?>
<script>window.JV_PRODUCTS = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>

<div class="page-head">
  <div><p>Tap a product to add it, or type / scan a code and press Enter. The total, change and stock update by themselves.</p></div>
  <div class="actions"><span class="pill plain">Today: <?= money($td['t']) ?> · <?= num($td['c']) ?> sale<?= (int)$td['c'] === 1 ? '' : 's' ?></span></div>
</div>

<div class="pos">
  <section class="pos-find">
    <div class="input-ico"><?= icon('search') ?><input type="search" id="q" placeholder="Search product or scan code…   (press / to jump here)" autocomplete="off" autofocus></div>
    <div class="chips" id="cats" style="margin-top:12px">
      <button type="button" class="chip active" data-cat="">All</button>
      <?php foreach ($cats as $c): ?><button type="button" class="chip" data-cat="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?>
    </div>
    <div class="pgrid" id="pgrid"></div>
    <p class="empty-box hide" id="no-results">No product found. Check the spelling or code.</p>
  </section>

  <form method="post" class="card pos-cart" id="cart-card">
    <?= csrf_field() ?>
    <input type="hidden" name="cart" id="f-cart"><input type="hidden" name="amount_paid" id="f-paid">
    <div class="cart-head"><h2>Current sale</h2><button type="button" class="link" id="clear">Clear all</button></div>
    <div class="cart-lines" id="lines"></div>
    <div class="cart-foot">
      <label class="f">Customer<input type="text" name="customer_name" maxlength="150" placeholder="Walk-in customer (optional)"></label>
      <div class="seg" id="pay">
        <?php foreach (['Cash', 'GCash'] as $i => $m): ?>
          <label><input type="radio" name="payment_method" value="<?= $m ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= $m ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="gcash-payment hide" id="gcash-box" aria-live="polite">
        <?php if ($gcashQr): ?><img class="gcash-payment-qr" src="<?= e($gcashQr) ?>" alt="GCash QR code for <?= e($gcashName ?: 'the store') ?>"><?php endif; ?>
        <div class="gcash-payment-details">
          <?php if ($gcashName): ?><strong><?= e($gcashName) ?></strong><?php endif; ?>
          <?php if ($gcashNumber): ?><span>GCash number: <b><?= e($gcashNumber) ?></b></span><button type="button" class="btn sm line" id="copy-gcash-number" data-number="<?= e($gcashNumber) ?>">Copy number</button><?php endif; ?>
          <?php if (!$gcashQr && !$gcashName && !$gcashNumber): ?><span>GCash details are not set up yet. An owner can add the QR code and account details in Settings.</span><?php endif; ?>
          <small>Confirm the payment has arrived before completing the sale.</small>
        </div>
      </div>
      <div id="cash-box">
        <label class="f">Cash received (₱)<input type="number" id="paid" min="0" step="0.01" inputmode="decimal" placeholder="0.00"></label>
        <div class="quickpay" style="margin-top:8px"><button type="button" data-exact>Exact</button><button type="button" data-amt="100">₱100</button><button type="button" data-amt="200">₱200</button><button type="button" data-amt="500">₱500</button><button type="button" data-amt="1000">₱1,000</button></div>
      </div>
      <div class="totals">
        <div class="trow"><span>Items</span><span id="t-items">0</span></div>
        <div class="trow big"><span>Total</span><b id="t-total">₱0.00</b></div>
        <div class="trow chg" id="chg-row"><span id="chg-label">Change</span><b id="t-change">₱0.00</b></div>
      </div>
      <button class="btn primary lg" type="submit" id="complete" disabled><?= icon('check') ?> Complete sale</button>
      <p class="hint" style="text-align:center">Shortcut: Ctrl + Enter</p>
    </div>
  </form>
</div>
<a class="mobile-bar" href="#cart-card"><span><span style="opacity:.7;font-size:12px" id="mb-items">0 items</span><br><b id="mb-total">₱0.00</b></span><span class="btn primary">View cart</span></a>
<?php require __DIR__ . '/includes/footer.php'; ?>
