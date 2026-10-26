<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
$kyc = q_one('SELECT * FROM kyc WHERE user_id = ?', [$uid]);
$editable = in_array($u['kyc_status'], ['none', 'submitted', 'rejected'], true);

$fields = [
    'dob', 'gender', 'marital_status', 'id_type', 'id_number', 'bvn',
    'address', 'landmark', 'city', 'lga', 'state', 'latitude', 'longitude', 'residence_type', 'years_at_address',
    'occupation', 'employer_name', 'work_address', 'monthly_income', 'bank_name', 'account_number', 'account_name',
    'nok_name', 'nok_phone', 'nok_relationship', 'guarantor_name', 'guarantor_phone', 'guarantor_address',
    'preferred_visit_date', 'preferred_visit_time', 'visit_instructions',
];
$optional = ['latitude', 'longitude', 'visit_instructions'];
const VISIT_TIMES = ['Morning (8am–12pm)', 'Afternoon (12pm–4pm)', 'Evening (4pm–6pm)'];
$errors = [];

if (is_post() && $editable) {
    verify_csrf();
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = mb_substr((string)post($f), 0, 255);
        if ($data[$f] === '' && !in_array($f, $optional, true)) {
            $errors[$f] = label($f) . ' is required.';
        }
    }
    $dob = strtotime($data['dob'] ?: 'now');
    if ($dob > strtotime('-18 years')) $errors['dob'] = 'You must be at least 18 years old.';
    if (!preg_match('/^\d{11}$/', $data['bvn'])) $errors['bvn'] = 'BVN must be 11 digits.';
    if (!preg_match('/^\d{10}$/', $data['account_number'])) $errors['account_number'] = 'Account number must be 10 digits.';
    if ((float)$data['monthly_income'] <= 0) $errors['monthly_income'] = 'Enter your monthly income.';
    if (strtotime($data['preferred_visit_date'] ?: 'yesterday') < strtotime('tomorrow')) $errors['preferred_visit_date'] = 'Pick a visit date from tomorrow onward.';
    if (!in_array($data['state'], nigerian_states(), true)) $errors['state'] = 'Choose your state.';
    if (!in_array($data['preferred_visit_time'], VISIT_TIMES, true)) $errors['preferred_visit_date'] = 'Choose a preferred visit time.';
    $data['latitude'] = is_numeric($data['latitude']) ? $data['latitude'] : null;
    $data['longitude'] = is_numeric($data['longitude']) ? $data['longitude'] : null;
    $data['years_at_address'] = min(99, max(0, (int)$data['years_at_address']));
    if (!post('consent')) $errors['consent'] = 'Please agree to the physical verification visit.';

    $docs = ['id_doc' => 'ID document', 'photo' => 'Passport photo', 'utility_bill' => 'Utility bill'];
    $uploaded = [];
    if (!$errors) {
        $new = [];
        try {
            foreach ($docs as $field => $title) {
                $stored = store_upload($field, $uid);
                if ($stored === null && empty($kyc[$field])) {
                    throw new UserError($title . ' is required.');
                }
                if ($stored !== null) {
                    $new[] = $stored;
                }
                $uploaded[$field] = $stored ?? $kyc[$field];
            }
        } catch (UserError $ex) {
            $errors['docs'] = $ex->getMessage();
            array_map('delete_upload', $new);   // don't keep half a submission
        }
    }

    if (!$errors) {
        $data += $uploaded;
        $cols = array_keys($data);
        if ($kyc) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            q("UPDATE kyc SET $set, rejection_reason = NULL, visit_date = NULL, visit_time = NULL, officer_name = NULL, officer_phone = NULL WHERE user_id = ?", [...array_values($data), $uid]);
            // Remove replaced documents
            foreach ($docs as $field => $_) {
                if ($kyc[$field] && $kyc[$field] !== $uploaded[$field]) {
                    delete_upload($kyc[$field]);
                }
            }
        } else {
            $ph = implode(',', array_fill(0, count($cols) + 1, '?'));
            q('INSERT INTO kyc (user_id, ' . implode(',', $cols) . ") VALUES ($ph)", [$uid, ...array_values($data)]);
        }
        q('UPDATE users SET kyc_status = "submitted" WHERE id = ?', [$uid]);
        foreach (q('SELECT id FROM users WHERE role = "admin"')->fetchAll() as $a) {
            notify((int)$a['id'], 'New verification to review', $u['full_name'] . ' submitted verification details.', 'admin/kyc.php?id=' . $uid);
        }
        flash('success', 'Verification submitted. We will contact you to confirm the date of your home visit.');
        redirect('verification.php');
    }
    $kyc = array_merge($kyc ?? [], $data);
}

$v = fn($f) => e($kyc[$f] ?? '');
$err = fn($f) => isset($errors[$f]) ? '<span class="hint" style="color:var(--bad)">' . e($errors[$f]) . '</span>' : '';
$sel = fn($f, $val) => (($kyc[$f] ?? '') === $val) ? 'selected' : '';

$status = $u['kyc_status'];
$stepState = function (int $n) use ($status): string {
    $pos = ['none' => 1, 'submitted' => 2, 'visit_scheduled' => 3, 'verified' => 5, 'rejected' => 2][$status];
    if ($status === 'rejected' && $n === 2) return 'fail';
    return $n < $pos ? 'done' : ($n === $pos ? 'now' : '');
};

app_header('Verification', 'verification');
?>
<h1 class="mobile-title">Verification</h1>

<div class="card stack">
  <div class="row between"><h2 style="margin:0">Your verification</h2><?= badge($status) ?></div>
  <div class="steps">
    <div class="st <?= $stepState(1) ?>">Fill details</div>
    <div class="st <?= $stepState(2) ?>">Review</div>
    <div class="st <?= $stepState(3) ?>">Home visit</div>
    <div class="st <?= $stepState(4) ?>">Verified</div>
  </div>
  <?php if ($status === 'none'): ?>
    <p class="muted small">Before we can lend to you, we need your identity and contact details. One of our field officers will then <strong>visit your home or business in person</strong> to confirm them. Once the visit is done, you can apply for a loan.</p>
  <?php elseif ($status === 'submitted'): ?>
    <div class="alert alert-info" style="margin:0">We're reviewing your details. You'll get a notification when your home visit is scheduled. You can still make changes below.</div>
  <?php elseif ($status === 'visit_scheduled'): ?>
    <div class="alert alert-warn" style="margin:0">
      <strong>Home visit:</strong> <?= fdate($kyc['visit_date'], 'l, F j, Y') ?> · <?= e($kyc['visit_time']) ?><br>
      Field officer: <strong><?= e($kyc['officer_name']) ?></strong><?= $kyc['officer_phone'] ? ' · ' . e($kyc['officer_phone']) : '' ?><br>
      Address: <?= e($kyc['address']) ?>, <?= e($kyc['city']) ?> (near <?= e($kyc['landmark']) ?>)<br>
      <span class="small">Please be available with your original ID, the utility bill you uploaded, and if possible your guarantor. Our officers never ask for cash or your PIN.</span>
    </div>
  <?php elseif ($status === 'verified'): ?>
    <div class="alert alert-success row between" style="margin:0"><span>You're fully verified<?= $kyc && $kyc['reviewed_at'] ? ' since ' . fdate($kyc['reviewed_at']) : '' ?>. Loans are now unlocked.</span><a class="btn btn-primary btn-sm" href="<?= url('loans.php#apply') ?>">Apply for a loan</a></div>
  <?php elseif ($status === 'rejected'): ?>
    <div class="alert alert-error" style="margin:0"><strong>Not approved:</strong> <?= e($kyc['rejection_reason'] ?? 'Please review and resubmit your details.') ?></div>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error">Please fix the highlighted fields.<?= isset($errors['docs']) ? ' ' . e($errors['docs']) : '' ?><?= isset($errors['consent']) ? ' ' . e($errors['consent']) : '' ?></div>
<?php endif; ?>

<?php if (!$editable && $kyc): ?>
  <div class="grid grid-2">
    <div class="card"><h2>Personal &amp; identity</h2><dl class="kv">
      <dt>Date of birth</dt><dd><?= fdate($kyc['dob']) ?></dd><dt>ID</dt><dd><?= e($kyc['id_type']) ?> · <?= e($kyc['id_number']) ?></dd>
      <dt>BVN</dt><dd>•••••••<?= e(substr($kyc['bvn'], -4)) ?></dd><dt>Occupation</dt><dd><?= e($kyc['occupation']) ?></dd>
      <dt>Bank</dt><dd><?= e($kyc['bank_name']) ?> · <?= e($kyc['account_number']) ?></dd></dl></div>
    <div class="card"><h2>Visit address</h2><dl class="kv">
      <dt>Address</dt><dd><?= e($kyc['address']) ?></dd><dt>Landmark</dt><dd><?= e($kyc['landmark']) ?></dd>
      <dt>Area</dt><dd><?= e($kyc['city']) ?>, <?= e($kyc['lga']) ?>, <?= e($kyc['state']) ?></dd>
      <dt>Guarantor</dt><dd><?= e($kyc['guarantor_name']) ?> · <?= e($kyc['guarantor_phone']) ?></dd></dl>
      <p class="muted small" style="margin-top:.8rem">Need to change something? Call <?= e(SUPPORT_PHONE) ?>.</p></div>
  </div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="stack" data-max-bytes="<?= MAX_UPLOAD_BYTES ?>">
  <?= csrf_field() ?>

  <fieldset>
    <legend><?= icon('user', 18) ?> Personal details</legend>
    <div class="form-grid">
      <label>Full name<input value="<?= e($u['full_name']) ?>" disabled></label>
      <label>Date of birth <?= $err('dob') ?><input type="date" name="dob" required value="<?= $v('dob') ?>" max="<?= date('Y-m-d', strtotime('-18 years')) ?>"></label>
      <label>Gender<select name="gender" required><option value="">Select</option><?php foreach (['Male', 'Female'] as $o): ?><option <?= $sel('gender', $o) ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label>Marital status<select name="marital_status" required><option value="">Select</option><?php foreach (['Single', 'Married', 'Divorced', 'Widowed'] as $o): ?><option <?= $sel('marital_status', $o) ?>><?= $o ?></option><?php endforeach; ?></select></label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= icon('shield', 18) ?> Identity</legend>
    <div class="form-grid">
      <label>ID type<select name="id_type" required><option value="">Select</option><?php foreach (['NIN Slip / National ID', 'International Passport', "Driver's Licence", "Voter's Card"] as $o): ?><option <?= $sel('id_type', $o) ?>><?= e($o) ?></option><?php endforeach; ?></select></label>
      <label>ID number <?= $err('id_number') ?><input name="id_number" required value="<?= $v('id_number') ?>"></label>
      <label>BVN <span class="hint">11 digits</span> <?= $err('bvn') ?><input name="bvn" inputmode="numeric" pattern="\d{11}" maxlength="11" required value="<?= $v('bvn') ?>"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= icon('map', 18) ?> Home address (for the physical visit)</legend>
    <div class="form-grid">
      <label style="grid-column:1/-1">Street address <span class="hint">House number, street, estate</span><input name="address" required value="<?= $v('address') ?>"></label>
      <label>Nearest landmark <span class="hint">e.g. opposite First Bank, behind the mosque</span><input name="landmark" required value="<?= $v('landmark') ?>"></label>
      <label>City / town<input name="city" required value="<?= $v('city') ?>"></label>
      <label>LGA<input name="lga" required value="<?= $v('lga') ?>"></label>
      <label>State <?= $err('state') ?><select name="state" required><option value="">Select</option><?php foreach (nigerian_states() as $s): ?><option <?= $sel('state', $s) ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
      <label>Type of residence<select name="residence_type" required><option value="">Select</option><?php foreach (['Owned', 'Rented', 'Family house', 'Employer provided'] as $o): ?><option <?= $sel('residence_type', $o) ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label>Years at this address<input type="number" name="years_at_address" min="0" max="99" required value="<?= $v('years_at_address') ?>"></label>
    </div>
    <div class="row" style="margin-top:.9rem">
      <button type="button" class="btn btn-ghost btn-sm" id="geo-btn"><?= icon('map', 16) ?> Use my current location</button>
      <span class="muted small" id="geo-status"><?= !empty($kyc['latitude']) ? 'Location saved.' : 'Optional: tap this while you are at home.' ?></span>
    </div>
    <input type="hidden" name="latitude" value="<?= $v('latitude') ?>"><input type="hidden" name="longitude" value="<?= $v('longitude') ?>">
  </fieldset>

  <fieldset>
    <legend><?= icon('cash', 18) ?> Work, income &amp; bank</legend>
    <div class="form-grid">
      <label>Occupation<input name="occupation" required value="<?= $v('occupation') ?>" placeholder="e.g. Trader, Teacher"></label>
      <label>Employer / business name<input name="employer_name" required value="<?= $v('employer_name') ?>"></label>
      <label style="grid-column:1/-1">Work or shop address<input name="work_address" required value="<?= $v('work_address') ?>"></label>
      <label>Monthly income <?= $err('monthly_income') ?><input type="number" name="monthly_income" min="0" step="1000" required value="<?= $v('monthly_income') ?>"></label>
      <label>Bank name<input name="bank_name" required value="<?= $v('bank_name') ?>"></label>
      <label>Account number <?= $err('account_number') ?><input name="account_number" inputmode="numeric" pattern="\d{10}" maxlength="10" required value="<?= $v('account_number') ?>"></label>
      <label>Account name<input name="account_name" required value="<?= $v('account_name') ?>"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= icon('users', 18) ?> Next of kin &amp; guarantor</legend>
    <div class="form-grid">
      <label>Next of kin name<input name="nok_name" required value="<?= $v('nok_name') ?>"></label>
      <label>Next of kin phone<input type="tel" name="nok_phone" required value="<?= $v('nok_phone') ?>"></label>
      <label>Relationship<input name="nok_relationship" required value="<?= $v('nok_relationship') ?>" placeholder="e.g. Spouse, Brother"></label>
      <label>Guarantor name<input name="guarantor_name" required value="<?= $v('guarantor_name') ?>"></label>
      <label>Guarantor phone<input type="tel" name="guarantor_phone" required value="<?= $v('guarantor_phone') ?>"></label>
      <label>Guarantor address<input name="guarantor_address" required value="<?= $v('guarantor_address') ?>"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= icon('doc', 18) ?> Documents</legend>
    <p class="muted small">JPG, PNG, WEBP or PDF, up to <?= MAX_UPLOAD_BYTES / 1048576 ?> MB each.<?= $kyc && !empty($kyc['id_doc']) ? ' Leave a field empty to keep the file you already uploaded.' : '' ?></p>
    <div class="form-grid">
      <label>Government ID<?= !empty($kyc['id_doc']) ? ' <span class="badge badge-ok">uploaded</span>' : '' ?><input type="file" name="id_doc" accept="image/*,application/pdf" <?= empty($kyc['id_doc']) ? 'required' : '' ?>></label>
      <label>Passport photo / selfie<?= !empty($kyc['photo']) ? ' <span class="badge badge-ok">uploaded</span>' : '' ?><input type="file" name="photo" accept="image/*" capture="user" <?= empty($kyc['photo']) ? 'required' : '' ?>></label>
      <label>Utility bill <span class="hint">Shows your address, last 3 months</span><?= !empty($kyc['utility_bill']) ? ' <span class="badge badge-ok">uploaded</span>' : '' ?><input type="file" name="utility_bill" accept="image/*,application/pdf" <?= empty($kyc['utility_bill']) ? 'required' : '' ?>></label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= icon('calendar', 18) ?> Home visit</legend>
    <div class="form-grid">
      <label>Preferred date <?= $err('preferred_visit_date') ?><input type="date" name="preferred_visit_date" required min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" value="<?= $v('preferred_visit_date') ?>"></label>
      <label>Preferred time<select name="preferred_visit_time" required><option value="">Select</option><?php foreach (VISIT_TIMES as $o): ?><option <?= $sel('preferred_visit_time', $o) ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label style="grid-column:1/-1">Directions for our officer <span class="hint">optional</span><input name="visit_instructions" value="<?= $v('visit_instructions') ?>" placeholder="e.g. Blue gate, call when you get to the junction"></label>
    </div>
    <label class="check" style="margin-top:.9rem"><input type="checkbox" name="consent" value="1" <?= post('consent') ? 'checked' : '' ?>><span class="small">I confirm these details are true, and I agree to a physical verification visit at the address above. I understand my loan can only be granted after this visit.</span></label>
  </fieldset>

  <button class="btn btn-primary"><?= icon('check', 18) ?> <?= $kyc ? 'Update & resubmit' : 'Submit for verification' ?></button>
</form>
<?php endif; ?>
<?php app_footer();
