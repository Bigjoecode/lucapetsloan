<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_staff();
if (!can('kyc') && !can('visits')) {
    http_response_code(403);
    exit("You don't have access to this page.");
}

$id = (int)($_GET['id'] ?? 0);

if (is_post()) {
    verify_csrf();
    $uid = (int)post('user_id');
    $cust = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
    $k = q_one('SELECT * FROM kyc WHERE user_id = ?', [$uid]);
    $action = post('action');
    if (!$cust || !$k) {
        flash('error', 'Verification record not found.');
        redirect('admin/kyc.php');
    }

    if ($action === 'credit_limit') {
        if (!can('loans')) {
            flash('error', 'Only staff who handle loans can change limits.');
        } else {
            $raw = trim((string)post('credit_limit'));
            $limit = $raw === '' ? null : max(0, round((float)$raw, 2));
            q('UPDATE users SET credit_limit_override = ? WHERE id = ?', [$limit, $uid]);
            audit('customer.credit_limit', 'User #' . $uid, $limit === null ? 'back to automatic' : money0($limit) . ' · ' . mb_substr(post('reason'), 0, 200));
            flash('success', $limit === null ? 'Limit is automatic again.' : 'Limit set to ' . money0($limit) . '.');
        }
        redirect('admin/kyc.php?id=' . $uid);
    }

    if ($action === 'checkin' || $action === 'visit_photo') {
        if (!can('visits')) {
            flash('error', 'Only field officers can check in.');
            redirect('admin/kyc.php?id=' . $uid);
        }
        if ($action === 'checkin') {
            $lat = (float)post('lat');
            $lng = (float)post('lng');
            if (!$lat && !$lng) {
                flash('error', 'Your location could not be read. Allow location access and try again.');
            } else {
                $dist = ($k['latitude'] !== null && $k['longitude'] !== null) ? distance_m($lat, $lng, (float)$k['latitude'], (float)$k['longitude']) : null;
                q('UPDATE kyc SET checkin_at = NOW(), checkin_lat = ?, checkin_lng = ?, checkin_distance_m = ? WHERE user_id = ?', [$lat, $lng, $dist, $uid]);
                audit('kyc.checkin', 'User #' . $uid, $dist === null ? 'no saved location to compare' : $dist . ' m from the address');
                $far = $dist !== null && $dist > setting('visit_radius_m');
                flash($far ? 'error' : 'success', $dist === null ? 'Checked in. The customer did not share a map location, so distance could not be checked.'
                    : ($far ? 'Checked in, but you are ' . number_format($dist) . ' m from the address the customer gave. Confirm you are at the right place.' : 'Checked in at the address (' . $dist . ' m away).'));
            }
        } else {
            try {
                $ref = store_upload('photo', $uid);
                if (!$ref) throw new UserError('Choose a photo.');
                if ($k['visit_photo']) delete_upload($k['visit_photo']);
                q('UPDATE kyc SET visit_photo = ? WHERE user_id = ?', [$ref, $uid]);
                audit('kyc.visit_photo', 'User #' . $uid);
                flash('success', 'Visit photo saved.');
            } catch (UserError $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/kyc.php?id=' . $uid);
    }

    if ($action === 'schedule' && !can('kyc')) {
        flash('error', 'Only verification staff can schedule visits.');
        redirect('admin/kyc.php?id=' . $uid);
    }

    if ($action === 'schedule') {
        $date = post('visit_date');
        $time = post('visit_time');
        $officerId = (int)post('officer_id');
        $officerUser = $officerId ? q_one('SELECT full_name, phone FROM users WHERE id = ? AND role IN ("admin","super_admin")', [$officerId]) : null;
        $officer = $officerUser['full_name'] ?? post('officer_name');
        if ($officerUser && post('officer_phone') === '') {
            $_POST['officer_phone'] = $officerUser['phone'];
        }
        if (!$date || !$time || !$officer) {
            flash('error', 'Visit date, time and officer are required.');
        } else {
            q('UPDATE kyc SET visit_date = ?, visit_time = ?, officer_name = ?, officer_phone = ?, officer_id = ?, checkin_at = NULL, rejection_reason = NULL WHERE user_id = ?',
                [$date, mb_substr($time, 0, 20), mb_substr($officer, 0, 120), mb_substr(post('officer_phone'), 0, 30), $officerUser ? $officerId : null, $uid]);
            if ($officerUser) {
                notify($officerId, 'Home visit assigned', $cust['full_name'] . ' on ' . fdate($date, 'D, M j') . ' (' . $time . '), ' . $k['address'] . ', ' . $k['city'] . '.', 'admin/kyc.php?id=' . $uid);
            }
            q('UPDATE users SET kyc_status = "visit_scheduled" WHERE id = ?', [$uid]);
            audit('kyc.schedule_visit', 'User #' . $uid, $date . ' ' . $time . ' · ' . $officer);
            notify($uid, 'Home visit scheduled', 'Officer ' . $officer . ' will visit on ' . fdate($date, 'D, M j') . ' (' . $time . ').', 'verification.php', true);
            flash('success', 'Visit scheduled and the customer has been notified.');
        }
    } elseif ($action === 'outcome') {
        $result = post('result');
        $notes = mb_substr(post('visit_notes'), 0, 2000);
        $checks = array_intersect((array)($_POST['checks'] ?? []), ['Address confirmed', 'Original ID sighted', 'NIN slip sighted', 'Customer met in person', 'Business/workplace seen', 'Guarantor contacted', 'Photo matches customer', 'Collateral seen and matches description']);
        $fullNotes = trim(($checks ? '✔ ' . implode(' · ✔ ', $checks) . "\n" : '') . $notes);
        if ($result === 'verified') {
            q('UPDATE kyc SET visit_notes = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = NULL WHERE user_id = ?', [$fullNotes, $admin['id'], $uid]);
            q('UPDATE users SET kyc_status = "verified" WHERE id = ?', [$uid]);
            audit('kyc.verify', 'User #' . $uid, $cust['full_name']);
            notify($uid, 'You are verified ✅', 'Your home visit is complete. You can now apply for a loan.', 'loans.php#apply', true);
            flash('success', $cust['full_name'] . ' is now verified.');
        } elseif ($result === 'rejected') {
            $reason = mb_substr(post('rejection_reason'), 0, 255);
            if ($reason === '') {
                flash('error', 'Give the customer a reason for the rejection.');
                redirect('admin/kyc.php?id=' . $uid);
            }
            q('UPDATE kyc SET visit_notes = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ?', [$fullNotes, $reason, $admin['id'], $uid]);
            q('UPDATE users SET kyc_status = "rejected" WHERE id = ?', [$uid]);
            audit('kyc.reject', 'User #' . $uid, $reason);
            notify($uid, 'Verification not approved', $reason, 'verification.php', true);
            flash('success', 'Verification rejected and the customer has been notified.');
        }
    }
    redirect('admin/kyc.php?id=' . $uid);
}

/* ---------- Detail view ---------- */
if ($id) {
    $k = q_one('SELECT k.*, u.full_name, u.email, u.phone, u.kyc_status, u.created_at joined, r.full_name reviewer
                FROM kyc k JOIN users u ON u.id = k.user_id LEFT JOIN users r ON r.id = k.reviewed_by WHERE k.user_id = ?', [$id]);
    if (!$k) {
        flash('error', 'This customer has not submitted verification yet.');
        redirect('admin/kyc.php');
    }
    $savings = savings_total($id);
    $officers = array_filter(q('SELECT * FROM users WHERE role IN ("admin","super_admin") AND status = "active" ORDER BY full_name')->fetchAll(), fn($s) => can('visits', $s));
    $credit = credit_profile(q_one('SELECT * FROM users WHERE id = ?', [$id]));
    $override = q_val('SELECT credit_limit_override FROM users WHERE id = ?', [$id]);
    $daysSaved = (int)q_val('SELECT COUNT(DISTINCT DATE(created_at)) FROM transactions WHERE user_id = ? AND type = "deposit" AND status = "approved"', [$id]);
    $mapQ = $k['latitude'] ? $k['latitude'] . ',' . $k['longitude'] : $k['address'] . ', ' . $k['city'] . ', ' . $k['state'] . ', Nigeria';
    $docUrl = fn($d) => url('file.php?u=' . $id . '&doc=' . $d);

    app_header('Verification · ' . $k['full_name'], 'kyc', 'admin');
    ?>
    <div class="row between">
      <div><a class="small" href="kyc.php">← Verifications</a><h2 style="margin:.2rem 0 0"><?= e($k['full_name']) ?></h2><span class="muted small"><?= e($k['phone']) ?> · <?= e($k['email']) ?> · joined <?= fdate($k['joined']) ?></span></div>
      <?= badge($k['kyc_status']) ?>
    </div>

    <div class="grid grid-2" style="align-items:start">
      <div class="stack">
        <div class="card">
          <h2><?= icon('map', 18) ?> Visit address</h2>
          <dl class="kv">
            <dt>Address</dt><dd><?= e($k['address']) ?></dd>
            <dt>Landmark</dt><dd><?= e($k['landmark']) ?></dd>
            <dt>Area</dt><dd><?= e($k['city']) ?>, <?= e($k['lga']) ?> LGA, <?= e($k['state']) ?></dd>
            <dt>Residence</dt><dd><?= e($k['residence_type']) ?> · <?= (int)$k['years_at_address'] ?> yrs</dd>
            <dt>GPS</dt><dd><?= $k['latitude'] ? e($k['latitude'] . ', ' . $k['longitude']) : '<span class="muted">Not shared</span>' ?></dd>
            <dt>Directions</dt><dd><?= e($k['visit_instructions'] ?: '—') ?></dd>
            <dt>Preferred</dt><dd><?= fdate($k['preferred_visit_date'], 'D, M j') ?> · <?= e($k['preferred_visit_time']) ?></dd>
          </dl>
          <a class="btn btn-ghost btn-sm" style="margin-top:.8rem" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($mapQ) ?>"><?= icon('map', 16) ?> Open in Google Maps</a>
        </div>
        <div class="card">
          <h2>Identity &amp; personal</h2>
          <dl class="kv">
            <dt>Date of birth</dt><dd><?= fdate($k['dob']) ?> (<?= (int)date_diff(date_create($k['dob']), date_create())->y ?> yrs)</dd>
            <dt>Gender / status</dt><dd><?= e($k['gender']) ?> · <?= e($k['marital_status']) ?></dd>
            <dt>ID</dt><dd><?= e($k['id_type']) ?> · <?= e($k['id_number']) ?></dd>
            <dt>BVN</dt><dd><?= e($k['bvn']) ?><?php if ($k['bvn_check']): ?> <span class="badge badge-<?= ['match' => 'ok', 'partial' => 'warn', 'no_match' => 'bad', 'not_found' => 'bad', 'error' => 'muted'][$k['bvn_check']] ?? 'muted' ?>"><?= e(['match' => 'matches name', 'partial' => 'partly matches', 'no_match' => 'name differs', 'not_found' => 'not found', 'error' => 'not checked'][$k['bvn_check']] ?? $k['bvn_check']) ?></span><div class="muted small"><?= e($k['bvn_check_details']) ?></div><?php endif; ?></dd>
            <dt>NIN</dt><dd><?= $k['nin'] ? e($k['nin']) : '<span class="muted">Not given (older submission)</span>' ?><?php if ($k['nin_check']): ?> <span class="badge badge-<?= ['match' => 'ok', 'partial' => 'warn', 'no_match' => 'bad', 'not_found' => 'bad', 'error' => 'muted'][$k['nin_check']] ?? 'muted' ?>"><?= e(['match' => 'matches name', 'partial' => 'partly matches', 'no_match' => 'name differs', 'not_found' => 'not found', 'error' => 'not checked'][$k['nin_check']] ?? $k['nin_check']) ?></span><div class="muted small"><?= e($k['nin_check_details']) ?></div><?php endif; ?></dd>
          </dl>
        </div>
        <div class="card">
          <h2>Work, income &amp; bank</h2>
          <dl class="kv">
            <dt>Occupation</dt><dd><?= e($k['occupation']) ?> at <?= e($k['employer_name']) ?></dd>
            <dt>Work address</dt><dd><?= e($k['work_address']) ?></dd>
            <dt>Monthly income</dt><dd><?= money($k['monthly_income']) ?></dd>
            <dt>Bank</dt><dd><?= e($k['bank_name']) ?> · <?= e($k['account_number']) ?> · <?= e($k['account_name']) ?></dd>
            <dt>Savings</dt><dd><?= money($savings) ?> · saved on <?= $daysSaved ?> days</dd>
          </dl>
        </div>
        <div class="card">
          <h2>Next of kin &amp; guarantor</h2>
          <dl class="kv">
            <dt>Next of kin</dt><dd><?= e($k['nok_name']) ?> (<?= e($k['nok_relationship']) ?>) · <a href="tel:<?= e($k['nok_phone']) ?>"><?= e($k['nok_phone']) ?></a></dd>
            <dt>Guarantor</dt><dd><?= e($k['guarantor_name']) ?><?= $k['guarantor_relationship'] ? ' (' . e($k['guarantor_relationship']) . ')' : '' ?> · <a href="tel:<?= e($k['guarantor_phone']) ?>"><?= e($k['guarantor_phone']) ?></a></dd>
            <dt>Guarantor address</dt><dd><?= e($k['guarantor_address']) ?></dd>
          </dl>
        </div>
        <div class="card">
          <h2><?= icon('shield', 18) ?> Collateral</h2>
          <?php if ($k['collateral_type']): ?>
            <dl class="kv">
              <dt>Type</dt><dd><?= e($k['collateral_type']) ?></dd>
              <dt>Description</dt><dd><?= e($k['collateral_description']) ?></dd>
              <dt>Estimated value</dt><dd><strong><?= money0($k['collateral_value']) ?></strong> · <?= $credit['limit'] > 0 ? round((float)$k['collateral_value'] / $credit['limit'] * 100) . '% of their borrowing limit' : '' ?></dd>
            </dl>
          <?php else: ?><p class="muted small">Submitted before collateral was required. Ask the customer to update their verification, or note collateral at the visit.</p><?php endif; ?>
        </div>
      </div>

      <div class="stack">
        <div class="card">
          <h2><?= icon('doc', 18) ?> Documents</h2>
          <div class="grid grid-3">
            <?php foreach (['photo' => 'Photo', 'id_doc' => 'Government ID', 'utility_bill' => 'Utility bill', 'collateral_doc' => 'Collateral'] as $d => $t): if (empty($k[$d])) continue; ?>
              <a href="<?= $docUrl($d) ?>" target="_blank" class="card" style="padding:.6rem;text-align:center;box-shadow:none">
                <?php if (!is_pdf_ref($k[$d])): ?><img src="<?= $docUrl($d) ?>" alt="<?= e($t) ?>" style="width:100%;height:110px;object-fit:cover;border-radius:8px"><?php else: ?><div style="height:110px;display:grid;place-items:center"><?= icon('doc', 40) ?></div><?php endif; ?>
                <div class="small" style="margin-top:.4rem;font-weight:600"><?= e($t) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (in_array($k['kyc_status'], ['submitted', 'visit_scheduled', 'rejected'], true)): ?>
        <div class="card">
          <h2><?= icon('calendar', 18) ?> <?= $k['kyc_status'] === 'visit_scheduled' ? 'Reschedule visit' : 'Schedule physical visit' ?></h2>
          <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="schedule"><input type="hidden" name="user_id" value="<?= $id ?>">
            <div class="form-grid">
              <label>Date<input type="date" name="visit_date" required min="<?= date('Y-m-d') ?>" value="<?= e($k['visit_date'] ?: $k['preferred_visit_date']) ?>"></label>
              <label>Time<input name="visit_time" required value="<?= e($k['visit_time'] ?: $k['preferred_visit_time']) ?>"></label>
              <label>Field officer
                <select name="officer_id"><option value="0">Someone else (type below)</option><?php foreach ($officers as $o): ?><option value="<?= $o['id'] ?>" <?= (int)$k['officer_id'] === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['full_name']) ?></option><?php endforeach; ?></select>
              </label>
              <label>Officer name <span class="hint">If not on the list</span><input name="officer_name" value="<?= e($k['officer_id'] ? '' : $k['officer_name']) ?>"></label>
              <label>Officer phone <span class="hint">Shown to the customer</span><input type="tel" name="officer_phone" value="<?= e($k['officer_phone']) ?>"></label>
            </div>
            <button class="btn btn-primary">Save &amp; notify customer</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($k['kyc_status'] === 'visit_scheduled' || $k['checkin_at'] || $k['visit_photo']): ?>
        <div class="card">
          <h2><?= icon('map', 18) ?> At the visit</h2>
          <dl class="kv">
            <dt>Check-in</dt><dd><?php if ($k['checkin_at']): ?><?= fdatetime($k['checkin_at']) ?><?php if ($k['checkin_distance_m'] !== null): ?> · <span style="color:var(--<?= $k['checkin_distance_m'] > setting('visit_radius_m') ? 'bad' : 'ok' ?>)"><?= number_format((int)$k['checkin_distance_m']) ?> m from the address</span><?php endif; ?> · <a href="https://www.google.com/maps?q=<?= e($k['checkin_lat'] . ',' . $k['checkin_lng']) ?>" target="_blank" rel="noopener">map</a><?php else: ?>Not yet<?php endif; ?></dd>
            <dt>Visit photo</dt><dd><?= $k['visit_photo'] ? '<a href="' . e($docUrl('visit_photo')) . '" target="_blank"><img src="' . e($docUrl('visit_photo')) . '" alt="Visit photo" style="max-width:160px;border-radius:8px;display:block"></a>' : 'None yet' ?></dd>
          </dl>
          <?php if (can('visits') && $k['kyc_status'] === 'visit_scheduled'): ?>
            <div class="row" style="margin-top:.6rem">
              <form method="post" data-checkin><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="user_id" value="<?= $id ?>"><input type="hidden" name="lat"><input type="hidden" name="lng">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('map', 16) ?> Check in here</button></form>
              <form method="post" enctype="multipart/form-data" class="row" data-max-bytes="<?= MAX_UPLOAD_BYTES ?>"><?= csrf_field() ?><input type="hidden" name="action" value="visit_photo"><input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="file" name="photo" accept="image/*" capture="environment" required style="max-width:200px"><button class="btn btn-ghost btn-sm">Save photo</button></form>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($k['kyc_status'], ['submitted', 'visit_scheduled'], true)): ?>
        <div class="card">
          <h2><?= icon('check', 18) ?> Record visit outcome</h2>
          <?php if ($k['kyc_status'] === 'submitted'): ?><p class="muted small">Normally you schedule and complete a visit first. You can still reject here if the documents are clearly invalid.</p><?php endif; ?>
          <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="outcome"><input type="hidden" name="user_id" value="<?= $id ?>">
            <div class="grid" style="gap:.4rem">
              <?php foreach (['Address confirmed', 'Original ID sighted', 'NIN slip sighted', 'Customer met in person', 'Photo matches customer', 'Business/workplace seen', 'Guarantor contacted', 'Collateral seen and matches description'] as $c): ?>
                <label class="check"><input type="checkbox" name="checks[]" value="<?= e($c) ?>"><span><?= e($c) ?></span></label>
              <?php endforeach; ?>
            </div>
            <label>Officer notes<textarea name="visit_notes" placeholder="What did the officer observe at the address?"></textarea></label>
            <label>Reason (if rejecting) <span class="hint">Shown to the customer</span><input name="rejection_reason" maxlength="255"></label>
            <div class="row">
              <button class="btn btn-primary" name="result" value="verified" <?= $k['kyc_status'] !== 'visit_scheduled' ? 'disabled title="Schedule and complete the visit first"' : '' ?>><?= icon('check', 16) ?> Visit done, verify</button>
              <button class="btn btn-danger" name="result" value="rejected" data-confirm="Reject this verification?"><?= icon('x', 16) ?> Reject</button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <div class="card">
          <h2>Credit</h2>
          <dl class="kv">
            <dt>Level</dt><dd><?= e($credit['name']) ?> · <?= $credit['on_time'] ?> on time, <?= $credit['late'] ?> late, <?= $credit['defaults'] ?> defaulted</dd>
            <dt>Limit now</dt><dd><strong><?= money0($credit['limit']) ?></strong><?= $credit['override'] ? ' (set by staff)' : ' (' . money0($credit['base']) . ' level + ' . money0($credit['savings_boost']) . ' savings)' ?></dd>
            <dt>Instant approval</dt><dd><?= $credit['trusted'] && setting('auto_approve_repeat') ? 'Yes' : 'No, staff review' ?></dd>
          </dl>
          <?php if (can('loans')): ?>
            <form method="post" class="form" style="margin-top:.8rem">
              <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $id ?>"><input type="hidden" name="action" value="credit_limit">
              <div class="form-grid">
                <label>Custom limit <span class="hint">Empty = automatic</span><input type="number" name="credit_limit" min="0" step="1000" value="<?= $override !== null ? (float)$override : '' ?>"></label>
                <label>Reason<input name="reason" maxlength="200" placeholder="e.g. Long-time trader, strong income"></label>
              </div>
              <button class="btn btn-ghost btn-sm">Save limit</button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($k['visit_notes'] || $k['reviewed_at']): ?>
        <div class="card">
          <h2>Review record</h2>
          <dl class="kv">
            <dt>Visit</dt><dd><?= $k['visit_date'] ? fdate($k['visit_date']) . ' · ' . e($k['visit_time']) . ' · ' . e($k['officer_name']) : '—' ?></dd>
            <dt>Reviewed</dt><dd><?= $k['reviewed_at'] ? fdatetime($k['reviewed_at']) . ' by ' . e($k['reviewer']) : '—' ?></dd>
            <?php if ($k['rejection_reason']): ?><dt>Rejection reason</dt><dd><?= e($k['rejection_reason']) ?></dd><?php endif; ?>
          </dl>
          <?php if ($k['visit_notes']): ?><p style="white-space:pre-line;margin-top:.8rem" class="small"><?= e($k['visit_notes']) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    app_footer();
    exit;
}

/* ---------- List view ---------- */
$tabs = ['submitted' => 'To review', 'visit_scheduled' => 'Visit scheduled', 'verified' => 'Verified', 'rejected' => 'Rejected'];
$status = array_key_exists($_GET['status'] ?? '', $tabs) ? $_GET['status'] : 'submitted';
$counts = q('SELECT kyc_status, COUNT(*) c FROM users GROUP BY kyc_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$rows = q('SELECT k.user_id, k.city, k.state, k.preferred_visit_date, k.visit_date, k.visit_time, k.officer_name, k.updated_at, u.full_name, u.phone
           FROM kyc k JOIN users u ON u.id = k.user_id WHERE u.kyc_status = ? ORDER BY ' . ($status === 'visit_scheduled' ? 'k.visit_date' : 'k.updated_at DESC') . ' LIMIT 200', [$status])->fetchAll();

app_header('Verifications', 'kyc', 'admin');
?>
<h1 class="mobile-title">Verifications</h1>
<div class="row">
  <?php foreach ($tabs as $key => $t): ?>
    <a class="btn btn-sm <?= $key === $status ? 'btn-primary' : 'btn-ghost' ?>" href="?status=<?= $key ?>"><?= e($t) ?> (<?= (int)($counts[$key] ?? 0) ?>)</a>
  <?php endforeach; ?>
</div>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('shield', 32) ?><p>Nothing in this list.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Location</th><th><?= $status === 'visit_scheduled' ? 'Visit' : 'Preferred visit' ?></th><th>Updated</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['full_name']) ?></strong><div class="muted small"><?= e($r['phone']) ?></div></td>
          <td><?= e($r['city']) ?>, <?= e($r['state']) ?></td>
          <td><?= $status === 'visit_scheduled' ? fdate($r['visit_date'], 'D, M j') . ' · ' . e($r['visit_time']) . '<div class="muted small">' . e($r['officer_name']) . '</div>' : fdate($r['preferred_visit_date'], 'D, M j') ?></td>
          <td class="muted small"><?= fdatetime($r['updated_at']) ?></td>
          <td class="right"><a class="btn btn-ghost btn-sm" href="?id=<?= $r['user_id'] ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php app_footer();
