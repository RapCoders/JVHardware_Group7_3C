<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$sale = db_q('SELECT s.*, u.full_name AS cashier FROM sales s LEFT JOIN users u ON u.id=s.user_id WHERE s.id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$sale && !empty($_GET['no'])) $sale = db_q('SELECT s.*, u.full_name AS cashier FROM sales s LEFT JOIN users u ON u.id=s.user_id WHERE s.receipt_no=?', [$_GET['no']])->fetch();
if (!$sale) { flash('Receipt not found.', 'err'); redirect('reports.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    csrf_check(); require_owner();
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') { flash('Please type a reason for voiding the sale.', 'err'); redirect('receipt.php?id=' . $sale['id']); }
    global $pdo;
    try {
        $pdo->beginTransaction();
        $s = db_q('SELECT status FROM sales WHERE id=?' . lock_clause(), [$sale['id']])->fetch();
        if ($s['status'] !== 'completed') throw new RuntimeException('This sale was already voided.');
        foreach (db_q('SELECT product_id,quantity FROM sale_items WHERE sale_id=?', [$sale['id']])->fetchAll() as $it) {
            apply_stock((int)$it['product_id'], (int)$it['quantity'], 'VOID', 'Voided ' . $sale['receipt_no'] . ': ' . mb_substr($reason, 0, 120));
        }
        db_q("UPDATE sales SET status='voided', void_reason=?, voided_at=? WHERE id=?", [mb_substr($reason, 0, 255), now(), $sale['id']]);
        $pdo->commit();
        flash('Sale voided. The items were returned to stock.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e instanceof RuntimeException ? $e->getMessage() : 'Could not void the sale.', 'err');
    }
    redirect('receipt.php?id=' . $sale['id']);
}

$items = db_q('SELECT * FROM sale_items WHERE sale_id=?', [$sale['id']])->fetchAll();
$voided = $sale['status'] === 'voided';
$isNew = isset($_GET['new']);

$pageTitle = 'Receipt'; $active = 'sales';
require __DIR__ . '/includes/header.php';
?>
<div class="receipt-wrap">
  <article class="receipt">
    <?php if ($voided): ?><div class="void-mark"><span>VOID</span></div><?php endif; ?>
    <div class="center"><h2><?= e(setting('store_name', 'JV Hardware')) ?></h2>
      <div><?= e(setting('store_address', '')) ?></div><div><?= e(setting('store_hours', '')) ?></div></div>
    <hr>
    <div class="r"><span>Receipt</span><b><?= e($sale['receipt_no']) ?></b></div>
    <div class="r"><span>Date</span><span><?= e(date('M j, Y g:i A', strtotime($sale['sold_at']))) ?></span></div>
    <div class="r"><span>Customer</span><span><?= e($sale['customer_name'] ?: 'Walk-in') ?></span></div>
    <?php if ($sale['cashier']): ?><div class="r"><span>Served by</span><span><?= e($sale['cashier']) ?></span></div><?php endif; ?>
    <hr>
    <?php foreach ($items as $it): ?>
      <div class="it"><div><?= e($it['product_name']) ?></div>
        <div class="r"><span><?= (int)$it['quantity'] ?> × <?= number_format((float)$it['unit_price'], 2) ?></span><span><?= number_format((float)$it['line_total'], 2) ?></span></div></div>
    <?php endforeach; ?>
    <hr>
    <div class="r tot"><span>TOTAL</span><span><?= money($sale['subtotal']) ?></span></div>
    <div class="r"><span>Paid (<?= e($sale['payment_method']) ?>)</span><span><?= money($sale['amount_paid']) ?></span></div>
    <div class="r"><span>Change</span><span><?= money($sale['change_amount']) ?></span></div>
    <hr>
    <div class="center"><?= e(setting('receipt_footer', 'Thank you!')) ?></div>
    <?php if ($voided): ?><hr><div class="center"><b>VOIDED</b> <?= e(fmt_dt($sale['voided_at'])) ?><br><?= e($sale['void_reason']) ?></div><?php endif; ?>
  </article>

  <div class="no-print">
    <?php if ($isNew && !$voided): ?>
      <div class="success-ico"><?= icon('check') ?></div>
      <h2 style="font-size:24px;font-weight:800;letter-spacing:-.5px">Sale recorded</h2>
      <p class="muted" style="margin:4px 0 20px">Stock has been updated automatically.<?php if ((float)$sale['change_amount'] > 0): ?> Give the customer <b style="color:var(--ink)"><?= money($sale['change_amount']) ?></b> change.<?php endif; ?></p>
    <?php else: ?>
      <h2 style="font-size:22px;font-weight:800;letter-spacing:-.4px">Receipt details</h2>
      <p class="muted" style="margin:4px 0 20px"><?= $voided ? 'This sale was voided and its items returned to stock.' : 'Completed sale.' ?></p>
    <?php endif; ?>
    <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn primary" onclick="window.print()"><?= icon('print') ?> Print receipt</button>
      <a class="btn dark" href="sales.php"><?= icon('cart') ?> New sale</a>
      <a class="btn line" href="reports.php">All sales</a>
      <?php if (is_owner() && !$voided): ?><button class="btn danger" data-open="void-dialog"><?= icon('undo') ?> Void sale</button><?php endif; ?>
    </div>
  </div>
</div>

<?php if (is_owner() && !$voided): ?>
<dialog id="void-dialog">
  <div class="dlg-head"><h2>Void this sale?</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <form method="post" class="dlg-body">
    <?= csrf_field() ?><input type="hidden" name="action" value="void">
    <p style="margin-bottom:12px">All items on <b><?= e($sale['receipt_no']) ?></b> go back to stock and the sale no longer counts in reports. This cannot be undone.</p>
    <label class="f">Reason<input type="text" name="reason" maxlength="255" required placeholder="e.g. Customer returned the items"></label>
    <div class="form-foot"><button type="button" class="btn ghost" data-close>Keep sale</button><button class="btn danger" type="submit">Void sale</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
