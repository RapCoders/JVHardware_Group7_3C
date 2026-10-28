<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$me = current_user();

function save_setting($k, $v) {
    db_q('DELETE FROM settings WHERE k=?', [$k]);
    db_q('INSERT INTO settings(k,v) VALUES(?,?)', [$k, $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    global $pdo;

    if ($action === 'password') {
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($_POST['current_password'] ?? '', $me['password_hash'])) flash('Your current password is not correct.', 'err');
        elseif (strlen($new) < 6) flash('The new password must be at least 6 characters.', 'err');
        elseif ($new !== ($_POST['confirm_password'] ?? '')) flash('The two new passwords do not match.', 'err');
        else { db_q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]); flash('Password changed.'); }
        redirect('settings.php');
    }

    require_owner();

    if ($action === 'store') {
        foreach (['store_name' => 80, 'store_address' => 150, 'store_hours' => 100, 'receipt_footer' => 150] as $k => $max) save_setting($k, mb_substr(trim($_POST[$k] ?? ''), 0, $max));
        flash('Store details saved. They appear on receipts.');
        redirect('settings.php');
    }

    if ($action === 'gcash') {
        $accountName = trim($_POST['gcash_name'] ?? '');
        $accountNumber = trim($_POST['gcash_number'] ?? '');
        if (mb_strlen($accountName) > 100 || mb_strlen($accountNumber) > 30 || ($accountNumber !== '' && !preg_match('/^[0-9+() -]{7,30}$/', $accountNumber))) {
            flash('Enter a valid GCash account name and number.', 'err'); redirect('settings.php');
        }
        $qrPath = setting('gcash_qr');
        $upload = $_FILES['gcash_qr'] ?? null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK || (int)$upload['size'] > 5 * 1024 * 1024) {
                flash('The QR image must be no larger than 5 MB.', 'err'); redirect('settings.php');
            }
            $image = @getimagesize($upload['tmp_name']);
            $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
            if (!$image || !isset($types[$image[2]])) {
                flash('Upload a valid PNG, JPG, or WebP GCash QR image.', 'err'); redirect('settings.php');
            }
            $fileName = 'gcash-qr-' . bin2hex(random_bytes(8)) . '.' . $types[$image[2]];
            $target = __DIR__ . '/assets/' . $fileName;
            if (!move_uploaded_file($upload['tmp_name'], $target)) {
                flash('The GCash QR image could not be saved.', 'err'); redirect('settings.php');
            }
            $qrPath = 'assets/' . $fileName;
        }
        save_setting('gcash_name', $accountName);
        save_setting('gcash_number', $accountNumber);
        save_setting('gcash_qr', $qrPath);
        flash('GCash payment details saved.');
        redirect('settings.php');
    }

    if ($action === 'demo_add') {
        $prods = db_q('SELECT id,sku,name,price FROM products WHERE is_active=1 AND price>0')->fetchAll();
        if (count($prods) < 3) { flash('Add a few products first.', 'err'); redirect('settings.php'); }
        $pdo->beginTransaction();
        try {
            $methods = ['Cash', 'Cash', 'Cash', 'GCash', 'Cash', 'GCash'];
            $custs = ['', '', '', 'Mang Rodel', 'Aling Nena', 'Kuya Jun', '', 'Engr. Dela Cruz'];
            mt_srand(7);
            for ($d = 29; $d >= 0; $d--) {
                $day = date('Y-m-d', strtotime("-$d days"));
                $n = mt_rand(3, 9) + (date('N', strtotime($day)) >= 6 ? 3 : 0);
                for ($i = 0; $i < $n; $i++) {
                    $time = sprintf('%s %02d:%02d:%02d', $day, mt_rand(8, 16), mt_rand(0, 59), mt_rand(0, 59));
                    if ($d === 0 && $time > now()) continue;
                    $items = []; $sub = 0;
                    foreach ((array)array_rand($prods, mt_rand(1, 3)) as $k) { $p = $prods[$k]; $q = mt_rand(1, 4); $items[] = [$p, $q, round($q * $p['price'], 2)]; $sub += $q * $p['price']; }
                    $sub = round($sub, 2); $pm = $methods[array_rand($methods)];
                    $paid = $pm === 'Cash' ? ceil($sub / 50) * 50 : $sub;
                    $rec = 'DEMO-' . date('Ymd', strtotime($day)) . '-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT);
                    db_q("INSERT INTO sales(receipt_no,customer_name,payment_method,subtotal,amount_paid,change_amount,status,user_id,sold_at) VALUES(?,?,?,?,?,?,'completed',?,?)",
                        [$rec, $custs[array_rand($custs)] ?: null, $pm, $sub, $paid, round($paid - $sub, 2), $me['id'], $time]);
                    $sid = (int)$pdo->lastInsertId();
                    foreach ($items as $it) db_q('INSERT INTO sale_items(sale_id,product_id,product_name,sku,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?)', [$sid, $it[0]['id'], $it[0]['name'], $it[0]['sku'], $it[1], $it[0]['price'], $it[2]]);
                }
            }
            $pdo->commit();
            flash('Demo sales for the last 30 days were added. Stock levels were not changed.');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('Could not add demo data.', 'err'); }
        redirect('settings.php');
    }

    if ($action === 'demo_remove') {
        $pdo->beginTransaction();
        try {
            db_q("DELETE FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE receipt_no LIKE 'DEMO-%')");
            db_q("DELETE FROM sales WHERE receipt_no LIKE 'DEMO-%'");
            $pdo->commit();
            flash('Demo sales removed.');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('Could not remove demo data.', 'err'); }
        redirect('settings.php');
    }
    redirect('settings.php');
}

$demoCount = is_owner() ? (int)db_q("SELECT COUNT(*) FROM sales WHERE receipt_no LIKE 'DEMO-%'")->fetchColumn() : 0;

$pageTitle = 'Settings'; $active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="grid g2" style="align-items:start">
  <section class="card">
    <div class="card-head"><div><h2>Change my password</h2><p>Signed in as <b><?= e($me['username']) ?></b></p></div></div>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label class="f full">Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
      <label class="f">New password<input type="password" name="new_password" minlength="6" required autocomplete="new-password"></label>
      <label class="f">Confirm new password<input type="password" name="confirm_password" minlength="6" required autocomplete="new-password"></label>
      <div class="full form-foot" style="margin-top:0"><button class="btn primary" type="submit">Update password</button></div>
    </form>
  </section>

  <?php if (is_owner()): ?>
  <section class="card">
    <div class="card-head"><div><h2>Store details</h2><p>Shown on printed receipts and the page header.</p></div></div>
    <form method="post" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="store">
      <label class="f full">Store name<input type="text" name="store_name" maxlength="80" value="<?= e(setting('store_name', 'JV Hardware')) ?>"></label>
      <label class="f full">Address<input type="text" name="store_address" maxlength="150" value="<?= e(setting('store_address', 'Brgy. Lomboy, Inabanga, Bohol')) ?>"></label>
      <label class="f">Opening hours<input type="text" name="store_hours" maxlength="100" value="<?= e(setting('store_hours', 'Open daily 8:00 AM - 5:00 PM')) ?>"></label>
      <label class="f">Receipt message<input type="text" name="receipt_footer" maxlength="150" value="<?= e(setting('receipt_footer', 'Thank you for shopping at JV Hardware!')) ?>"></label>
      <div class="full form-foot" style="margin-top:0"><button class="btn primary" type="submit">Save details</button></div>
    </form>
  </section>
  <section class="card">
    <div class="card-head"><div><h2>GCash payment details</h2><p>Shown to the cashier when GCash is selected for a sale.</p></div></div>
    <form method="post" class="form-grid" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="gcash">
      <label class="f full">Account name<input type="text" name="gcash_name" maxlength="100" value="<?= e(setting('gcash_name')) ?>" placeholder="Name registered with GCash"></label>
      <label class="f full">GCash number<input type="tel" name="gcash_number" maxlength="30" value="<?= e(setting('gcash_number')) ?>" placeholder="09XX XXX XXXX"></label>
      <label class="f full">GCash QR code<input type="file" name="gcash_qr" accept="image/png,image/jpeg,image/webp"><small>PNG, JPG, or WebP, up to 5 MB. Leave empty to keep the current QR.</small></label>
      <?php if (setting('gcash_qr')): ?><div class="full"><img class="gcash-settings-qr" src="<?= e(setting('gcash_qr')) ?>" alt="Current GCash QR code"></div><?php endif; ?>
      <div class="full form-foot" style="margin-top:0"><button class="btn primary" type="submit">Save GCash details</button></div>
    </form>
  </section>
  <?php endif; ?>
</div>

<?php if (is_owner()): ?>
<section class="card" style="margin-top:18px">
  <div class="card-head"><div><h2>Demo data</h2><p>For presentations: fill the dashboard and reports with 30 days of sample sales. Real stock is not touched, and you can remove the sample sales any time.</p></div></div>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?>
    <button class="btn line" name="action" value="demo_add" type="submit" <?= $demoCount ? 'disabled' : '' ?>>Add sample sales</button>
    <button class="btn danger" name="action" value="demo_remove" type="submit" <?= $demoCount ? '' : 'disabled' ?> data-confirm="Remove all sample (DEMO) sales? Your real sales are not affected.">Remove sample sales</button>
    <?php if ($demoCount): ?><span class="pill info"><?= $demoCount ?> sample sales currently in the system</span><?php endif; ?></form>
</section>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
