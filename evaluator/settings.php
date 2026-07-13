<?php
// evaluator/profile.php – DMW Evaluator Profile & Settings
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');

// Require evaluator login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

/* ══════════════════════════════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Update profile (full name, phone) ──
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if ($full_name === '') {
            echo json_encode(['ok' => false, 'error' => 'Full name is required.']);
            exit;
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a valid phone number.']);
            exit;
        }

        $stmt = $database->prepare("UPDATE evaluators SET full_name = ?, phone = ? WHERE email = ?");
        $stmt->bind_param("sss", $full_name, $phone, $evaluator_email);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo json_encode(['ok' => false, 'error' => 'Could not save changes. Please try again.']);
            exit;
        }

        $parts    = explode(' ', trim($full_name));
        $initials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
            : strtoupper(substr($full_name, 0, 2));

        echo json_encode([
            'ok'        => true,
            'message'   => 'Profile updated.',
            'full_name' => htmlspecialchars($full_name),
            'phone'     => htmlspecialchars($phone),
            'initials'  => htmlspecialchars($initials),
        ]);
        exit;
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            echo json_encode(['ok' => false, 'error' => 'Please fill in all password fields.']);
            exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']);
            exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['ok' => false, 'error' => 'New password and confirmation do not match.']);
            exit;
        }

        $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? AND usertype = 'evaluator' LIMIT 1");
        $stmt->bind_param("s", $evaluator_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
            exit;
        }
        if (password_verify($new, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'New password must be different from your current password.']);
            exit;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $database->prepare("UPDATE webuser SET password = ? WHERE email = ? AND usertype = 'evaluator'");
        $stmt->bind_param("ss", $hash, $evaluator_email);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            echo json_encode(['ok' => false, 'error' => 'Could not update password. Please try again.']);
            exit;
        }

        echo json_encode(['ok' => true, 'message' => 'Password changed successfully.']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOAD EVALUATOR PROFILE
══════════════════════════════════════════════════════════════════════════════ */

$evaluator_name  = 'Evaluator';
$evaluator_id    = null;
$evaluator_phone = '';
$evaluator_since = null;

$stmt_ev = $database->prepare("
    SELECT eid, full_name, phone, created_at
    FROM evaluators
    WHERE email = ?
    LIMIT 1
");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();

if ($ev_result->num_rows === 1) {
    $row             = $ev_result->fetch_assoc();
    $evaluator_id    = (int)$row['eid'];
    $evaluator_name  = $row['full_name'] ?? 'Evaluator';
    $evaluator_phone = $row['phone'] ?? '';
    $evaluator_since = $row['created_at'] ?? null;
}
$stmt_ev->close();

/* ══════════════════════════════════════════════════════════════════════════════
   PERFORMANCE STATS
══════════════════════════════════════════════════════════════════════════════ */

$perf = [
    'evaluated_total' => 0,
    'approved'        => 0,
    'disapproved'     => 0,
    'for_compliance'  => 0,
    'active_now'      => 0,
];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT decision, COUNT(*) AS cnt
        FROM evaluations
        WHERE evaluator_id = ?
        GROUP BY decision
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $cnt = (int)$r['cnt'];
        $perf['evaluated_total'] += $cnt;
        if (array_key_exists($r['decision'], $perf)) {
            $perf[$r['decision']] += $cnt;
        }
    }
    $stmt->close();

    $stmt = $database->prepare("
        SELECT COUNT(*) AS cnt FROM applications
        WHERE current_evaluator_id = ? AND handling_party = 'evaluator'
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $perf['active_now'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

$member_since_label = $evaluator_since ? date('F Y', strtotime($evaluator_since)) : '—';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Profile &amp; Settings – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Section label (mini uppercase eyebrow) — mirrors index.php */
        .sec-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        .fade-in {
            opacity: 0;
            transform: translateY(10px);
            animation: fi2 .4s ease forwards;
        }

        @keyframes fi2 {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .d1 {
            animation-delay: .05s
        }

        .d2 {
            animation-delay: .1s
        }

        .d3 {
            animation-delay: .15s
        }

        .d4 {
            animation-delay: .2s
        }

        .d5 {
            animation-delay: .25s
        }

        /* ── Profile header card ── */
        .profile-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 14px;
        }

        .profile-avatar {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--blue-lt), var(--indigo));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .profile-info {
            flex: 1;
            min-width: 0;
        }

        .profile-name {
            font-size: 16.5px;
            font-weight: 800;
            color: var(--text1);
        }

        .profile-role {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text3);
            margin-top: 2px;
        }

        .profile-email {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── Card wrapper for forms (mirrors index.php's .card) ── */
        .card {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .ctdot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .card-action-btn {
            background: none;
            border: none;
            color: var(--blue-lt);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .card-action-btn:hover {
            text-decoration: underline;
        }

        /* ── Form elements ── */
        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text1);
            background: #fff;
            transition: border-color .18s;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--blue-lt);
        }

        .form-input:disabled {
            background: var(--surface);
            color: var(--text3);
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 10.5px;
            color: var(--text3);
            margin-top: 5px;
        }

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }

        .btn {
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid transparent;
            transition: opacity .18s, transform .12s;
        }

        .btn:active {
            transform: scale(.97);
        }

        .btn-primary {
            background: var(--blue-lt);
            color: #fff;
        }

        .btn-primary:hover {
            opacity: .92;
        }

        .btn-secondary {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text2);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .form-err {
            font-size: 11.5px;
            color: #b91c1c;
            margin-top: 8px;
            display: none;
        }

        /* ── Performance stats grid ── */
        .perf-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .perf-item {
            background: var(--surface);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .perf-value {
            font-size: 19px;
            font-weight: 800;
            color: var(--text1);
        }

        .perf-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text3);
            margin-top: 2px;
        }

        @media (min-width: 640px) {
            .settings-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                align-items: start;
            }
        }
    </style>
</head>

<body>

    <!-- ══════════════════════════════════════════
         TOPBAR
    ══════════════════════════════════════════ -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; EVALUATOR PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar" id="tb-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════
         SUBBAR
    ══════════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Profile &amp; Settings</h2>
                <p>Manage your account details and password</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Profile header -->
        <div class="profile-card fade-in d1">
            <div class="profile-avatar" id="profile-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="profile-info">
                <div class="profile-name" id="profile-name-display"><?= htmlspecialchars($evaluator_name) ?></div>
                <div class="profile-role">Evaluator &middot; Member since <?= htmlspecialchars($member_since_label) ?></div>
                <div class="profile-email">
                    <i class="fas fa-envelope" style="font-size:10px;"></i>
                    <?= htmlspecialchars($evaluator_email) ?>
                </div>
            </div>
        </div>

        <div class="settings-grid">
            <div>
                <!-- Performance snapshot -->
                <p class="sec-label fade-in d2">Snapshot</p>
                <div class="card fade-in d2">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            My Performance
                        </span>
                    </div>
                    <div class="perf-grid">
                        <div class="perf-item">
                            <div class="perf-value"><?= number_format($perf['active_now']) ?></div>
                            <div class="perf-label">Active assignments</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value"><?= number_format($perf['evaluated_total']) ?></div>
                            <div class="perf-label">Total evaluations made</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value" style="color:#059669;"><?= number_format($perf['approved']) ?></div>
                            <div class="perf-label">Approved</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value" style="color:#b91c1c;"><?= number_format($perf['disapproved']) ?></div>
                            <div class="perf-label">Disapproved</div>
                        </div>
                    </div>
                </div>

                <!-- Profile info form -->
                <p class="sec-label fade-in d3">Account Details</p>
                <div class="card fade-in d3">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--blue-lt)"></span>
                            Personal Information
                        </span>
                    </div>

                    <form id="profile-form">
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-input"
                                value="<?= htmlspecialchars($evaluator_name) ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="form-input"
                                    value="<?= htmlspecialchars($evaluator_phone) ?>" placeholder="e.g. 09171234567">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email_ro">Email Address</label>
                                <input type="text" id="email_ro" class="form-input" value="<?= htmlspecialchars($evaluator_email) ?>" disabled>
                            </div>
                        </div>
                        <div class="form-hint" style="margin-top:-8px; margin-bottom:14px;">
                            Email is tied to your login and can't be changed here. Contact an administrator if it needs to be updated.
                        </div>

                        <div id="profile-err" class="form-err"></div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="profile-save-btn">
                                <i class="fas fa-check"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div>
                <!-- Change password -->
                <p class="sec-label fade-in d4">Security</p>
                <div class="card fade-in d4">
                    <div class="card-head">
                        <span class="card-title">
                            <span class="ctdot" style="background:#d97706"></span>
                            Change Password
                        </span>
                    </div>

                    <form id="password-form">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-input" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-input" required autocomplete="new-password">
                            <div class="form-hint">At least 8 characters.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required autocomplete="new-password">
                        </div>

                        <div id="password-err" class="form-err"></div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="password-save-btn">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /main -->

    <!-- ══════════════════════════════════════════
         FLOATING DOCK  (exact index.php structure)
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk active">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.php" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <div class="dk" onclick="logout('Evaluator')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <script>
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        function showErr(id, msg) {
            const el = document.getElementById(id);
            if (!el) return;
            if (!msg) {
                el.textContent = '';
                el.style.display = 'none';
            } else {
                el.textContent = msg;
                el.style.display = 'block';
            }
        }

        /* ── Update profile ── */
        document.getElementById('profile-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('profile-err', '');
            const btn = document.getElementById('profile-save-btn');
            btn.disabled = true;

            const fd = new FormData(this);
            fd.append('action', 'update_profile');

            fetch('profile.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.ok) {
                        showErr('profile-err', data.error || 'Something went wrong.');
                        return;
                    }
                    document.getElementById('profile-name-display').textContent = data.full_name;
                    document.getElementById('profile-avatar').textContent = data.initials;
                    document.getElementById('tb-avatar').textContent = data.initials;
                    Swal.fire({
                        icon: 'success',
                        title: 'Profile updated',
                        text: data.message,
                        confirmButtonColor: '#3b82f6',
                        timer: 2200,
                        timerProgressBar: true
                    });
                })
                .catch(() => {
                    btn.disabled = false;
                    showErr('profile-err', 'Network error. Please try again.');
                });
        });

        /* ── Change password ── */
        document.getElementById('password-form').addEventListener('submit', function(ev) {
            ev.preventDefault();
            showErr('password-err', '');

            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            if (newPass !== confirmPass) {
                showErr('password-err', 'New password and confirmation do not match.');
                return;
            }

            const btn = document.getElementById('password-save-btn');
            btn.disabled = true;

            const fd = new FormData(this);
            fd.append('action', 'change_password');

            fetch('profile.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.ok) {
                        showErr('password-err', data.error || 'Something went wrong.');
                        return;
                    }
                    document.getElementById('password-form').reset();
                    Swal.fire({
                        icon: 'success',
                        title: 'Password updated',
                        text: data.message,
                        confirmButtonColor: '#3b82f6',
                        timer: 2200,
                        timerProgressBar: true
                    });
                })
                .catch(() => {
                    btn.disabled = false;
                    showErr('password-err', 'Network error. Please try again.');
                });
        });
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>