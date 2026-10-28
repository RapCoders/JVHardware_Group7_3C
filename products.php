<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

/* ---- CSV template download ---- */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jv-products-template.csv"');
    echo "\xEF\xBB\xBF", "name,sku,category,unit,quantity,price,reorder_level\r\n",
         "PVC Pipe 3/4 inch,,Plumbing,piece,40,110.00,10\r\n",
         "Concrete Nail 3 inch,,Hardware,kilo,15,98.00,5\r\n";
    exit;
}

/* ---- actions ---- */
function parse_product_post() {
    $d = [
        'name' => trim($_POST['name'] ?? ''), 'sku' => trim($_POST['sku'] ?? ''),
        'category' => trim($_POST['category'] ?? '') ?: 'General', 'unit' => trim($_POST['unit'] ?? '') ?: 'piece',
        'price' => filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT),
        'reorder' => filter_var($_POST['reorder_level'] ?? 5, FILTER_VALIDATE_INT),
        'qty' => filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_INT),
    ];
    return $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        if (!can('prices')) { flash('You are not allowed to change products. Ask the owner for access.', 'err'); redirect('products.php'); }
        $id = (int)($_POST['id'] ?? 0);
        $d = parse_product_post();
        if ($d['name'] === '' || mb_strlen($d['name']) > 150 || mb_strlen($d['sku']) > 50 || mb_strlen($d['category']) > 100 || mb_strlen($d['unit']) > 30
            || $d['price'] === false || !is_finite((float)$d['price']) || $d['price'] < 0 || $d['price'] > 99999999.99
            || $d['reorder'] === false || $d['reorder'] < 0 || $d['reorder'] > 2147483647
            || $d['qty'] === false || $d['qty'] < 0 || $d['qty'] > 2147483647) {
            flash('Please check the details: product fields must fit their limits, and prices and quantities must be valid non-negative numbers.', 'err'); redirect('products.php');
        }
        global $pdo;
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $old = db_q('SELECT price FROM products WHERE id=?' . lock_clause(), [$id])->fetch();
                if (!$old) throw new RuntimeException('Product not found.');
                $sku = $d['sku'] !== '' ? $d['sku'] : next_sku();
                db_q('UPDATE products SET sku=?,name=?,category=?,unit=?,price=?,reorder_level=?,updated_at=? WHERE id=?',
                    [$sku, $d['name'], $d['category'], $d['unit'], $d['price'], $d['reorder'], now(), $id]);
                if (round((float)$old['price'], 2) !== round($d['price'], 2)) {
                    db_q('INSERT INTO price_history(product_id,old_price,new_price,user_id,changed_at) VALUES(?,?,?,?,?)',
                        [$id, $old['price'], $d['price'], current_user()['id'], now()]);
                }
                flash('Saved changes to ' . $d['name'] . '.');
            } else {
                $sku = $d['sku'] !== '' ? $d['sku'] : next_sku();
                db_q('INSERT INTO products(sku,name,category,unit,quantity,price,reorder_level,is_active,created_at) VALUES(?,?,?,?,?,?,?,1,?)',
                    [$sku, $d['name'], $d['category'], $d['unit'], $d['qty'], $d['price'], $d['reorder'], now()]);
                $newId = (int)$pdo->lastInsertId();
                if ($d['qty'] > 0) {
                    db_q("INSERT INTO stock_movements(product_id,movement_type,quantity,note,user_id,balance_after,created_at) VALUES(?,'IN',?,?,?,?,?)",
                        [$newId, $d['qty'], 'Opening stock', current_user()['id'], $d['qty'], now()]);
                }
                flash($d['name'] . ' added (' . $sku . ').');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($e instanceof PDOException && $e->getCode() === '23000' ? 'That product code (SKU) is already used. Leave it blank to auto-generate one.' : ($e instanceof RuntimeException ? $e->getMessage() : 'Could not save the product.'), 'err');
        }
        redirect('products.php');
    }

    if ($action === 'archive' || $action === 'restore') {
        require_owner();
        $id = (int)($_POST['id'] ?? 0);
        db_q('UPDATE products SET is_active=?,updated_at=? WHERE id=?', [$action === 'restore' ? 1 : 0, now(), $id]);
        flash($action === 'restore' ? 'Product restored.' : 'Product archived. It is hidden from sales but its history is kept.');
        redirect('products.php' . ($action === 'restore' ? '' : ''));
    }

    if ($action === 'import') {
        require_owner();
        global $pdo;
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) { flash('Please choose a CSV file first.', 'err'); redirect('products.php'); }
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $head = fgetcsv($fh);
        if (!$head) { flash('The file is empty.', 'err'); redirect('products.php'); }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
        $alias = ['name' => 'name', 'product' => 'name', 'product name' => 'name', 'sku' => 'sku', 'code' => 'sku', 'category' => 'category', 'unit' => 'unit',
                  'quantity' => 'qty', 'qty' => 'qty', 'stock' => 'qty', 'price' => 'price', 'selling price' => 'price',
                  'reorder_level' => 'reorder', 'reorder' => 'reorder', 'reorder level' => 'reorder', 'low stock' => 'reorder'];
        $map = [];
        foreach ($head as $i => $h) { $k = strtolower(trim($h)); if (isset($alias[$k])) $map[$alias[$k]] = $i; }
        if (!isset($map['name']) || !isset($map['price'])) { flash('The CSV needs at least "name" and "price" columns. Download the template to see the format.', 'err'); redirect('products.php'); }
        $added = 0; $skipped = 0; $bad = 0; $n = 0; $truncated = false;
        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== false && $n < 2000) {
                $n++;
                $g = function ($k, $def = '') use ($map, $row) { return isset($map[$k]) && isset($row[$map[$k]]) ? trim($row[$map[$k]]) : $def; };
                $name = $g('name'); $price = filter_var(str_replace([',', '₱'], '', $g('price')), FILTER_VALIDATE_FLOAT);
                $qty = filter_var($g('qty', '0') === '' ? 0 : $g('qty', '0'), FILTER_VALIDATE_INT);
                $reo = filter_var($g('reorder', '5') === '' ? 5 : $g('reorder', '5'), FILTER_VALIDATE_INT);
                if ($name === '' && $g('price') === '') continue;
                if ($name === '' || $price === false || $price < 0 || $qty === false || $qty < 0 || $reo === false || $reo < 0) { $bad++; continue; }
                $sku = $g('sku') !== '' ? $g('sku') : next_sku();
                if (db_q('SELECT COUNT(*) FROM products WHERE sku=?', [$sku])->fetchColumn() > 0) { $skipped++; continue; }
                db_q('INSERT INTO products(sku,name,category,unit,quantity,price,reorder_level,is_active,created_at) VALUES(?,?,?,?,?,?,?,1,?)',
                    [$sku, $name, $g('category') ?: 'General', $g('unit') ?: 'piece', $qty, $price, $reo, now()]);
                if ($qty > 0) db_q("INSERT INTO stock_movements(product_id,movement_type,quantity,note,user_id,balance_after,created_at) VALUES(?,'IN',?,?,?,?,?)",
                    [(int)$pdo->lastInsertId(), $qty, 'Opening stock (CSV import)', current_user()['id'], $qty, now()]);
                $added++;
            }
            $truncated = $n >= 2000 && fgetcsv($fh) !== false;
            $pdo->commit();
            flash("Import finished: $added added" . ($skipped ? ", $skipped skipped (code already exists)" : '') . ($bad ? ", $bad rows had errors" : '') . ($truncated ? '; only the first 2,000 rows were processed' : '') . '.', $added ? 'ok' : 'err');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Import failed. Nothing was saved. Please check the file.', 'err');
        }
        fclose($fh);
        redirect('products.php');
    }
    redirect('products.php');
}

/* ---- data ---- */
$showArchived = isset($_GET['archived']);
$products = db_q('SELECT * FROM products WHERE is_active=? ORDER BY name', [$showArchived ? 0 : 1])->fetchAll();
$categories = db_q('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$archivedCount = (int)db_q('SELECT COUNT(*) FROM products WHERE is_active=0')->fetchColumn();
$counts = ['all' => count($products), 'low' => 0, 'out' => 0];
foreach ($products as $p) { list($c) = status_of($p['quantity'], $p['reorder_level']); if ($c === 'low') $counts['low']++; if ($c === 'out') $counts['out']++; }
$canEdit = can('prices');

$pageTitle = 'Products'; $active = 'products';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p><?= $showArchived ? 'Archived products are hidden from sales. Restore them any time.' : 'Everything you sell — prices, stock levels and alert settings in one list.' ?></p></div>
  <div class="actions">
    <?php if (is_owner() && !$showArchived): ?><button class="btn line" data-open="import-dialog"><?= icon('upload') ?> Import CSV</button><a class="btn line" href="export.php?type=inventory"><?= icon('download') ?> Export</a><?php endif; ?>
    <?php if ($canEdit && !$showArchived): ?><button class="btn primary" id="add-btn"><?= icon('plus') ?> Add product</button><?php endif; ?>
  </div>
</div>

<div class="toolbar">
  <div class="input-ico"><?= icon('search') ?><input type="search" id="filter-q" placeholder="Search by name, code or category…" autocomplete="off"></div>
  <select id="filter-cat" aria-label="Category"><option value="">All categories</option><?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
  <div class="chips" id="filter-status">
    <button type="button" class="chip active" data-s="">All <em><?= $counts['all'] ?></em></button>
    <button type="button" class="chip" data-s="low">Low <em><?= $counts['low'] ?></em></button>
    <button type="button" class="chip" data-s="out">Out <em><?= $counts['out'] ?></em></button>
  </div>
  <?php if ($showArchived): ?><a class="chip" href="products.php">← Back to active</a>
  <?php elseif ($archivedCount): ?><a class="chip" href="products.php?archived=1">Archived <em><?= $archivedCount ?></em></a><?php endif; ?>
</div>

<div class="table-wrap"><table id="ptable">
  <thead><tr><th>Product</th><th>Category</th><th class="num">Price</th><th>In stock</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p):
      list($sc) = status_of($p['quantity'], $p['reorder_level']);
      $pct = min(100, (int)round($p['quantity'] / max(1, $p['reorder_level'] * 3) * 100));
      $json = e(json_encode(['id' => (int)$p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'category' => $p['category'], 'unit' => $p['unit'], 'price' => (float)$p['price'], 'reorder' => (int)$p['reorder_level']])); ?>
    <tr data-s="<?= $sc ?>" data-cat="<?= e($p['category']) ?>" data-q="<?= e(strtolower($p['name'] . ' ' . $p['sku'] . ' ' . $p['category'])) ?>">
      <td><strong><?= e($p['name']) ?></strong><small><?= e($p['sku']) ?></small></td>
      <td><span class="pill plain"><?= e($p['category']) ?></span></td>
      <td class="num"><strong><?= money($p['price']) ?></strong><small>per <?= e($p['unit']) ?></small></td>
      <td><strong><?= num($p['quantity']) ?></strong> <span class="muted"><?= e($p['unit']) ?></span><div class="stockbar <?= $sc ?>"><span style="width:<?= max(3, $pct) ?>%"></span></div></td>
      <td><?= $showArchived ? '<span class="pill neutral">Archived</span>' : status_pill($p['quantity'], $p['reorder_level']) ?></td>
      <td><div class="row-actions">
        <?php if (!$showArchived): ?><a class="btn sm line" href="stock.php?product=<?= (int)$p['id'] ?>&type=IN" title="Add stock"><?= icon('plus') ?> Stock</a><?php endif; ?>
        <?php if ($canEdit && !$showArchived): ?><button class="btn sm line edit-btn" data-p="<?= $json ?>"><?= icon('edit') ?> Edit</button><?php endif; ?>
        <?php if (is_owner()): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $showArchived ? 'restore' : 'archive' ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn sm <?= $showArchived ? 'line' : 'ghost' ?>" type="submit" <?= $showArchived ? '' : 'data-confirm="Archive this product? It will be hidden from sales, but past sales and history are kept."' ?> title="<?= $showArchived ? 'Restore' : 'Archive' ?>"><?= icon($showArchived ? 'undo' : 'archive') ?><?= $showArchived ? ' Restore' : '' ?></button></form>
        <?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  <tr id="no-match" class="hide"><td colspan="6" class="empty">No products match your search.</td></tr>
  <?php if (!$products): ?><tr><td colspan="6" class="empty"><?= $showArchived ? 'No archived products.' : 'No products yet. Click “Add product” to start' . (is_owner() ? ' or import a CSV.' : '.') ?></td></tr><?php endif; ?>
  </tbody></table></div>

<?php if ($canEdit): ?>
<dialog id="product-dialog">
  <div class="dlg-head"><h2 id="pd-title">Add product</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <form method="post" class="dlg-body" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="pd-id" value="0">
    <div class="form-grid">
      <label class="f full">Product name<input type="text" name="name" id="pd-name" maxlength="150" required placeholder="e.g. PVC Pipe 1/2 inch"></label>
      <label class="f">Category<input type="text" name="category" id="pd-category" maxlength="100" list="cat-list" placeholder="e.g. Plumbing"></label>
      <label class="f">Unit<input type="text" name="unit" id="pd-unit" maxlength="30" list="unit-list" value="piece"></label>
      <label class="f">Selling price (₱)<input type="number" name="price" id="pd-price" min="0" step="0.01" required placeholder="0.00"></label>
      <label class="f">Low-stock alert at<input type="number" name="reorder_level" id="pd-reorder" min="0" step="1" value="5"><small>You'll be alerted when stock drops to this number.</small></label>
      <label class="f" id="pd-qty-wrap">Starting quantity<input type="number" name="quantity" id="pd-qty" min="0" step="1" value="0"></label>
      <label class="f">Product code (SKU)<input type="text" name="sku" id="pd-sku" maxlength="50" placeholder="Auto-generated if empty"></label>
    </div>
    <p class="hint" id="pd-note" style="margin-top:12px"></p>
    <div class="form-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary" type="submit">Save product</button></div>
  </form>
  <datalist id="cat-list"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="unit-list"><?php foreach (['piece', 'bag', 'kilo', 'meter', 'roll', 'box', 'set', 'pack', 'liter', 'gallon', 'sheet', 'pair'] as $u): ?><option value="<?= $u ?>"><?php endforeach; ?></datalist>
</dialog>
<?php endif; ?>

<?php if (is_owner()): ?>
<dialog id="import-dialog">
  <div class="dlg-head"><h2>Import products from CSV</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <form method="post" enctype="multipart/form-data" class="dlg-body">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <p style="margin-bottom:12px">Save your price list from Excel as <b>CSV</b>, then upload it here to add many products at once. Codes (SKU) are generated automatically if left blank, and existing codes are skipped.</p>
    <p class="hint" style="margin-bottom:14px">Columns: <b>name, sku, category, unit, quantity, price, reorder_level</b> (only name and price are required). <a class="link" href="products.php?template=1">Download the template</a></p>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <div class="form-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary" type="submit"><?= icon('upload') ?> Import</button></div>
  </form>
</dialog>
<?php endif; ?>

<script>
(function () {
  var q = document.getElementById('filter-q'), cat = document.getElementById('filter-cat'), chips = document.querySelectorAll('#filter-status .chip');
  var rows = document.querySelectorAll('#ptable tbody tr[data-q]'), none = document.getElementById('no-match'), st = '';
  function apply() {
    var t = q.value.trim().toLowerCase(), c = cat.value, shown = 0;
    rows.forEach(function (r) {
      var ok = (!t || r.dataset.q.indexOf(t) > -1) && (!c || r.dataset.cat === c) && (!st || r.dataset.s === st);
      r.classList.toggle('hide', !ok); if (ok) shown++;
    });
    none.classList.toggle('hide', shown > 0 || rows.length === 0);
  }
  q.addEventListener('input', apply); cat.addEventListener('change', apply);
  chips.forEach(function (b) { b.addEventListener('click', function () { chips.forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active'); st = b.dataset.s; apply(); }); });

  var dlg = document.getElementById('product-dialog'); if (!dlg) return;
  function $(id) { return document.getElementById(id); }
  function open(p) {
    $('pd-id').value = p ? p.id : 0; $('pd-title').textContent = p ? 'Edit product' : 'Add product';
    $('pd-name').value = p ? p.name : ''; $('pd-sku').value = p ? p.sku : ''; $('pd-category').value = p ? p.category : '';
    $('pd-unit').value = p ? p.unit : 'piece'; $('pd-price').value = p ? p.price.toFixed(2) : ''; $('pd-reorder').value = p ? p.reorder : 5; $('pd-qty').value = 0;
    $('pd-qty-wrap').classList.toggle('hide', !!p);
    $('pd-note').textContent = p ? 'To change the quantity, use Stock In / Out so the history stays accurate. Price changes are recorded automatically.' : 'Tip: leave the code empty and the system will create one for you.';
    dlg.showModal(); setTimeout(function () { $('pd-name').focus(); }, 30);
  }
  var add = $('add-btn'); if (add) add.addEventListener('click', function () { open(null); });
  document.querySelectorAll('.edit-btn').forEach(function (b) { b.addEventListener('click', function () { open(JSON.parse(b.dataset.p)); }); });
  if (location.search.indexOf('new=1') > -1) open(null);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
