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

    if ($action === 'user') {
        $id = (int)($_POST['id'] ?? 0);
        $username = strtolower(trim($_POST['username'] ?? ''));
        $full = trim($_POST['full_name'] ?? '');
        $role = ($_POST['role'] ?? 'staff') === 'owner' ? 'owner' : 'staff';
        $prices = isset($_POST['can_edit_prices']) ? 1 : 0;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $pw = $_POST['password'] ?? '';
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username) || $full === '') { flash('Enter a full name and a username (3–30 letters or numbers, no spaces).', 'err'); redirect('settings.php'); }
        if ($id === 0 && strlen($pw) < 6) { flash('Give the new account a password of at least 6 characters.', 'err'); redirect('settings.php'); }
        if ($pw !== '' && strlen($pw) < 6) { flash('Passwords need at least 6 characters.', 'err'); redirect('settings.php'); }
        if ($id === (int)$me['id']) { $role = 'owner'; $active = 1; }   // you cannot lock yourself out
        try {
            if ($id > 0) {
                db_q('UPDATE users SET username=?,full_name=?,role=?,can_edit_prices=?,is_active=? WHERE id=?', [$username, $full, $role, $prices, $active, $id]);
                if ($pw !== '') db_q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
                flash('Account updated.');
            } else {
                db_q('INSERT INTO users(username,full_name,password_hash,role,can_edit_prices,is_active,created_at) VALUES(?,?,?,?,?,?,?)', [$username, $full, password_hash($pw, PASSWORD_DEFAULT), $role, $prices, $active, now()]);
                flash('Account created. Share the username and password with ' . $full . '.');
            }
        } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'That username is already taken.' : 'Could not save the account.', 'err'); }
        redirect('settings.php');
    }

    if ($action === 'demo_add') {
        $prods = db_q('SELECT id,sku,name,price FROM products WHERE is_active=1 AND price>0')->fetchAll();
        if (count($prods) < 3) { flash('Add a few products first.', 'err'); redirect('settings.php'); }
        $pdo->beginTransaction();
        try {
            $methods = ['Cash', 'Cash', 'Cash', 'GCash', 'Cash', 'Bank Transfer'];
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

$users = is_owner() ? db_q('SELECT * FROM users ORDER BY role, full_name')->fetchAll() : [];
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
  <?php endif; ?>
</div>

<?php if (is_owner()): ?>
<section class="card" style="margin-top:18px">
  <div class="card-head"><div><h2>Staff accounts</h2><p>Staff can record sales and add stock. Tick “Can edit products &amp; prices” for trusted staff.</p></div>
    <button class="btn primary" id="add-user"><?= icon('plus') ?> Add account</button></div>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Permissions</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): $j = e(json_encode(['id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'prices' => (int)$u['can_edit_prices'], 'active' => (int)$u['is_active'], 'self' => (int)$u['id'] === (int)$me['id']])); ?>
    <tr><td><strong><?= e($u['full_name']) ?></strong></td><td class="muted"><?= e($u['username']) ?></td>
      <td><span class="pill <?= $u['role'] === 'owner' ? 'info' : 'plain' ?>"><?= $u['role'] === 'owner' ? 'Owner' : 'Staff' ?></span></td>
      <td class="muted"><?= $u['role'] === 'owner' ? 'Full access' : ((int)$u['can_edit_prices'] ? 'Sales, stock in, edit products &amp; prices' : 'Sales and stock in') ?></td>
      <td><span class="pill <?= $u['is_active'] ? 'ok' : 'neutral' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
      <td class="right"><button class="btn sm line user-edit" data-u="<?= $j ?>"><?= icon('edit') ?> Edit</button></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>

<section class="card" style="margin-top:18px">
  <div class="card-head"><div><h2>Demo data</h2><p>For presentations: fill the dashboard and reports with 30 days of sample sales. Real stock is not touched, and you can remove the sample sales any time.</p></div></div>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?>
    <button class="btn line" name="action" value="demo_add" type="submit" <?= $demoCount ? 'disabled' : '' ?>>Add sample sales</button>
    <button class="btn danger" name="action" value="demo_remove" type="submit" <?= $demoCount ? '' : 'disabled' ?> data-confirm="Remove all sample (DEMO) sales? Your real sales are not affected.">Remove sample sales</button>
    <?php if ($demoCount): ?><span class="pill info"><?= $demoCount ?> sample sales currently in the system</span><?php endif; ?></form>
</section>

<dialog id="user-dialog">
  <div class="dlg-head"><h2 id="u-title">Add account</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <form method="post" class="dlg-body" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="user"><input type="hidden" name="id" id="u-id" value="0">
    <div class="form-grid">
      <label class="f">Full name<input type="text" name="full_name" id="u-full" maxlength="100" required></label>
      <label class="f">Username<input type="text" name="username" id="u-user" maxlength="30" required></label>
      <label class="f full"><span id="u-pw-label">Password</span><input type="password" name="password" id="u-pw" minlength="6" autocomplete="new-password"><small id="u-pw-hint"></small></label>
      <label class="f">Role<select name="role" id="u-role"><option value="staff">Staff</option><option value="owner">Owner</option></select></label>
      <div class="f" style="justify-content:end;gap:10px"><label class="check"><input type="checkbox" name="can_edit_prices" id="u-prices"> Can edit products &amp; prices</label>
        <label class="check"><input type="checkbox" name="is_active" id="u-active" checked> Account is active</label></div>
    </div>
    <div class="form-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary" type="submit">Save account</button></div>
  </form>
</dialog>
<script>
(function () {
  var $ = function (i) { return document.getElementById(i); }, d = $('user-dialog');
  function open(u) {
    $('u-id').value = u ? u.id : 0; $('u-title').textContent = u ? 'Edit account' : 'Add account';
    $('u-full').value = u ? u.full_name : ''; $('u-user').value = u ? u.username : ''; $('u-pw').value = ''; $('u-pw').required = !u;
    $('u-pw-label').textContent = u ? 'New password' : 'Password'; $('u-pw-hint').textContent = u ? 'Leave empty to keep the current password.' : 'At least 6 characters.';
    $('u-role').value = u ? u.role : 'staff'; $('u-prices').checked = u ? !!u.prices : false; $('u-active').checked = u ? !!u.active : true;
    $('u-role').disabled = $('u-active').disabled = !!(u && u.self);
    // disabled controls are not submitted; the server keeps owner/active for your own account
    d.showModal(); setTimeout(function () { $('u-full').focus(); }, 30);
  }
  $('add-user').addEventListener('click', function () { open(null); });
  document.querySelectorAll('.user-edit').forEach(function (b) { b.addEventListener('click', function () { open(JSON.parse(b.dataset.u)); }); });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
