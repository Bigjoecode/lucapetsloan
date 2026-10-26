<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    verify_csrf();
    $pid = (int)post('id');
    if (post('action') === 'toggle') {
        q('UPDATE loan_products SET is_active = 1 - is_active WHERE id = ?', [$pid]);
        flash('success', 'Product updated.');
    } else {
        $d = [
            mb_substr(post('name'), 0, 80), mb_substr(post('description'), 0, 255),
            (float)post('min_amount'), (float)post('max_amount'), (float)post('interest_rate'),
            (int)post('min_tenure'), (int)post('max_tenure'), (float)post('late_fee_rate'),
        ];
        if ($d[0] === '' || $d[2] <= 0 || $d[3] < $d[2] || $d[4] <= 0 || $d[5] < 1 || $d[6] < $d[5] || $d[6] > 60 || $d[7] < 0) {
            flash('error', 'Check the values: name is required, max must be at least min, and the rate must be above 0.');
        } elseif ($pid) {
            q('UPDATE loan_products SET name=?, description=?, min_amount=?, max_amount=?, interest_rate=?, min_tenure=?, max_tenure=?, late_fee_rate=? WHERE id = ?', [...$d, $pid]);
            flash('success', 'Product saved. New rates apply to new applications only.');
        } else {
            q('INSERT INTO loan_products (name, description, min_amount, max_amount, interest_rate, min_tenure, max_tenure, late_fee_rate) VALUES (?,?,?,?,?,?,?,?)', $d);
            flash('success', 'Product created.');
        }
    }
    redirect('admin/products.php');
}

$products = q('SELECT p.*, (SELECT COUNT(*) FROM loans l WHERE l.product_id = p.id) loans FROM loan_products p ORDER BY p.min_amount')->fetchAll();
$edit = isset($_GET['edit']) ? q_one('SELECT * FROM loan_products WHERE id = ?', [(int)$_GET['edit']]) : null;
$f = fn($k, $def = '') => e($edit[$k] ?? $def);

app_header('Loan products', 'products', 'admin');
?>
<h1 class="mobile-title">Loan products</h1>
<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h2>Products</h2>
    <ul class="list">
      <?php foreach ($products as $p): ?>
        <li>
          <div class="grow">
            <div class="title"><?= e($p['name']) ?> <?= $p['is_active'] ? '' : '<span class="badge badge-muted">hidden</span>' ?></div>
            <div class="sub"><?= (float)$p['interest_rate'] ?>%/month · <?= money0($p['min_amount']) ?>–<?= money0($p['max_amount']) ?> · <?= (int)$p['min_tenure'] ?>–<?= (int)$p['max_tenure'] ?> mo · late <?= (float)$p['late_fee_rate'] ?>%/day · <?= (int)$p['loans'] ?> loans</div>
          </div>
          <a class="btn btn-ghost btn-sm" href="?edit=<?= $p['id'] ?>">Edit</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-ghost btn-sm"><?= $p['is_active'] ? 'Hide' : 'Show' ?></button></form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <h2><?= $edit ? 'Edit ' . e($edit['name']) : 'New product' ?></h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $f('id', 0) ?>">
      <label>Name<input name="name" required value="<?= $f('name') ?>"></label>
      <label>Description<input name="description" value="<?= $f('description') ?>"></label>
      <div class="form-grid">
        <label>Min amount<input type="number" name="min_amount" step="100" required value="<?= $f('min_amount') ?>"></label>
        <label>Max amount<input type="number" name="max_amount" step="100" required value="<?= $f('max_amount') ?>"></label>
        <label>Interest % per month<input type="number" name="interest_rate" step="0.01" required value="<?= $f('interest_rate') ?>"></label>
        <label>Late fee % per day<input type="number" name="late_fee_rate" step="0.001" required value="<?= $f('late_fee_rate', '0.5') ?>"></label>
        <label>Min tenure (months)<input type="number" name="min_tenure" min="1" required value="<?= $f('min_tenure', 1) ?>"></label>
        <label>Max tenure (months)<input type="number" name="max_tenure" min="1" max="60" required value="<?= $f('max_tenure', 6) ?>"></label>
      </div>
      <div class="row">
        <button class="btn btn-primary"><?= $edit ? 'Save changes' : 'Create product' ?></button>
        <?php if ($edit): ?><a class="btn btn-ghost" href="products.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php app_footer();
