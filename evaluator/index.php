<?php
// evaluator/index.php – DMW Evaluator Dashboard
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

// ────────────────────────────────────────────────
// Get evaluator info
// ────────────────────────────────────────────────
$evaluator_name = 'Evaluator';
$evaluator_id   = null;

$stmt_ev = $database->prepare("
    SELECT eid, full_name 
    FROM evaluators 
    WHERE email = ? 
    LIMIT 1
");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();

if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// ────────────────────────────────────────────────
// Global stats: applications by status
// ────────────────────────────────────────────────
$stats = [
    'assign'           => 0,
    'under_evaluation' => 0,
    'for_compliance'   => 0,
    'approved'         => 0,
    'resubmitted'      => 0,
    'total'            => 0,
];

$res = $database->query("
    SELECT status, COUNT(*) as cnt 
    FROM applications 
    GROUP BY status
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $key = $row['status'];
        $cnt = (int) $row['cnt'];
        if (in_array($key, ['assigned', 'assign', 'pending'], true)) {
            $stats['assign'] += $cnt;
        } elseif (array_key_exists($key, $stats)) {
            $stats[$key] += $cnt;
        }
        $stats['total'] += $cnt;
    }
}

// Applications sitting unclaimed in the pool right now (anyone can open one)
$available_count = 0;
$res_avail = $database->query("
    SELECT COUNT(*) AS cnt FROM applications
    WHERE handling_party IS NULL AND status IN ('pending','assign','assigned','resubmitted')
");
if ($res_avail) {
    $available_count = (int) $res_avail->fetch_assoc()['cnt'];
}

// ────────────────────────────────────────────────
// My active assignments
// ────────────────────────────────────────────────
$my_applications = [];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id, 
            a.status, 
            a.updated_at, 
            r.rsf_number, 
            r.position_title, 
            r.worksite_country AS country,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name
        FROM applications a
        INNER JOIN rsf_job_orders    r  ON a.rsf_id           = r.id
        LEFT  JOIN clients           c  ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
        ORDER BY a.updated_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $my_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ────────────────────────────────────────────────
// Count active assignments
// ────────────────────────────────────────────────
$my_assigned_count = 0;

if ($evaluator_id) {
    $stmt_cnt = $database->prepare("
        SELECT COUNT(*) as cnt 
        FROM applications 
        WHERE current_evaluator_id = ? 
          AND handling_party = 'evaluator'
    ");
    $stmt_cnt->bind_param("i", $evaluator_id);
    $stmt_cnt->execute();
    $my_assigned_count = (int)$stmt_cnt->get_result()->fetch_assoc()['cnt'];
    $stmt_cnt->close();
}

// Greeting
$hour       = (int)date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($evaluator_name))[0] ?: $evaluator_name;

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluator Dashboard – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <!-- Fonts used by dashboard.css -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Your actual dashboard stylesheet -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Page-specific additions that extend dashboard.css ── */

        /* Quick-access shortcut row */
        .quick-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }

        .quick-btn {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 8px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: var(--text2);
            font-family: 'DM Sans', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
            transition: background .18s, transform .18s, box-shadow .18s;
        }

        .quick-btn:hover {
            background: #f8fafc;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px -4px rgba(11, 31, 75, .12);
        }

        .quick-btn:active {
            transform: scale(.97);
        }

        .quick-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        /* Application list rows inside a .card */
        .app-list {
            display: flex;
            flex-direction: column;
        }

        .app-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background .14s;
            border-radius: 10px;
            padding-left: 4px;
            padding-right: 4px;
        }

        .app-row:last-child {
            border-bottom: none;
        }

        .app-row:hover {
            background: var(--surface);
        }

        .app-avatar {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: #eef2ff;
            color: var(--indigo);
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .02em;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .app-info {
            flex: 1;
            min-width: 0;
        }

        .app-name {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-meta {
            font-size: 10.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-right {
            text-align: right;
            flex-shrink: 0;
        }

        .status-pill {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: 3px 9px;
            border-radius: 99px;
        }

        .sp-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .sp-under {
            background: #eef2ff;
            color: #3730a3;
        }

        .sp-compliance {
            background: #fff7ed;
            color: #92400e;
        }

        .sp-assigned {
            background: #eff6ff;
            color: #1e40af;
        }

        .sp-disapproved {
            background: #fee2e2;
            color: #991b1b;
        }

        .sp-default {
            background: #f1f5f9;
            color: #475569;
        }

        .app-date {
            font-size: 9.5px;
            color: var(--text3);
            margin-top: 3px;
            font-weight: 500;
        }

        /* Empty state */
        .empty-state {
            padding: 40px 16px;
            text-align: center;
        }

        .empty-state i {
            font-size: 32px;
            color: var(--border);
            display: block;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text3);
        }

        .empty-state span {
            font-size: 11px;
            color: var(--text3);
            opacity: .7;
        }

        /* Section label (mini uppercase eyebrow) */
        .sec-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* Stagger animations */
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

        .d6 {
            animation-delay: .3s
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

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
            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════
         SUBBAR
    ══════════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h2>
                <p>(<?= number_format($my_assigned_count) ?>) active assignment<?= $my_assigned_count !== 1 ? 's' : '' ?></p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Quick Access -->
        <p class="sec-label fade-in d1">Quick Access</p>
        <div class="quick-row fade-in d2">

            <a href="applications.php" class="quick-btn">
                <div class="quick-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-list"></i>
                </div>
                My Assignments
            </a>

            <a href="applications.php?status=for_compliance" class="quick-btn">
                <div class="quick-icon" style="background:#fffbeb;color:#d97706;">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                For Compliance
            </a>

            <a href="reports.php" class="quick-btn">
                <div class="quick-icon" style="background:#ecfdf5;color:#059669;">
                    <i class="fas fa-chart-bar"></i>
                </div>
                Reports
            </a>

        </div>

        <!-- KPI Stats — uses .z-stats + .kpi-card from dashboard.css -->
        <p class="sec-label fade-in d2">Overview</p>
        <div class="z-stats fade-in d3">

            <!-- Total -->
            <div class="kpi-card c-blue">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Total Applications</div>
                        <div class="kpi-value c-blue"><?= number_format($stats['total']) ?></div>
                    </div>
                    <div class="kpi-icon c-blue"><i class="fas fa-file-lines"></i></div>
                </div>
                <div class="kpi-sub">All statuses combined</div>
            </div>

            <!-- Assigned to me 
            <div class="kpi-card c-amber">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Assigned to Me</div>
                        <div class="kpi-value c-amber"><?= number_format($my_assigned_count) ?></div>
                    </div>
                    <div class="kpi-icon c-amber"><i class="fas fa-user-check"></i></div>
                </div>
                <div class="kpi-sub">Active assignments</div>
            </div>-->

            <!-- Available to claim -->
            <a href="applications.php?status=available" class="kpi-card c-green" style="text-decoration:none;">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Available</div>
                        <div class="kpi-value c-green"><?= number_format($available_count) ?></div>
                    </div>
                    <div class="kpi-icon c-green"><i class="fas fa-inbox"></i></div>
                </div>
                <div class="kpi-sub">Unclaimed — first to open it gets it</div>
            </a>

            <!-- Under evaluation -->
            <div class="kpi-card c-indigo">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Under Evaluation</div>
                        <div class="kpi-value c-indigo"><?= number_format($stats['under_evaluation']) ?></div>
                    </div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-sub">Currently being reviewed</div>
            </div>

            <!-- Approved -->
            <div class="kpi-card c-green">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Approved</div>
                        <div class="kpi-value c-green"><?= number_format($stats['approved']) ?></div>
                    </div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-sub">All approved applications</div>
            </div>

        </div>

        <!-- My Assigned Applications — uses .card + .card-head from dashboard.css -->
        <div class="z-mid fade-in d4">
            <div class="card">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt)"></span>
                        My Assigned Applications
                    </span>
                    <a href="applications.php" class="card-link">
                        View all <i class="fas fa-arrow-right" style="font-size:9px"></i>
                    </a>
                </div>

                <div class="app-list">
                    <?php if (empty($my_applications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No applications assigned yet</p>
                            <span>Applications assigned to you will appear here once processed.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_applications as $app): ?>
                            <?php
                            $status     = strtolower($app['status'] ?? 'unknown');
                            $statusText = str_replace('_', ' ', ucwords($status));

                            $pill = match ($status) {
                                'approved'         => 'sp-approved',
                                'under_evaluation' => 'sp-under',
                                'for_compliance'   => 'sp-compliance',
                                'disapproved'      => 'sp-disapproved',
                                'assign'           => 'sp-assigned',
                                'pending'          => 'sp-assigned',
                                'assigned'         => 'sp-assigned',
                                'resubmitted'      => 'sp-under',
                                default            => 'sp-default',
                            };

                            $np  = explode(' ', trim($app['applicant_name']));
                            $avi = strtoupper(count($np) >= 2
                                ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                : substr($app['applicant_name'], 0, 2));
                            ?>
                            <a href="view-application.php?id=<?= (int)$app['id'] ?>" class="app-row">
                                <div class="app-avatar"><?= htmlspecialchars($avi) ?></div>
                                <div class="app-info">
                                    <div class="app-name"><?= htmlspecialchars($app['applicant_name']) ?></div>
                                    <div class="app-meta">
                                        <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['position_title'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['country'] ?? '—') ?>
                                    </div>
                                </div>
                                <div class="app-right">
                                    <span class="status-pill <?= $pill ?>"><?= htmlspecialchars($statusText) ?></span>
                                    <div class="app-date">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div><!-- /card -->

            <!-- Status breakdown side-panel (fills the grid's right column on desktop) -->
            <div class="card fade-in d5">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:var(--indigo)"></span>
                        Status Breakdown
                    </span>
                </div>

                <?php
                $breakdown = [
                    ['label' => 'Assign',            'key' => 'assign',             'dot' => '#3b82f6'],
                    ['label' => 'Under Evaluation',  'key' => 'under_evaluation',   'dot' => '#4f46e5'],
                    ['label' => 'For Compliance',    'key' => 'for_compliance',   'dot' => '#d97706'],
                    ['label' => 'Resubmitted',       'key' => 'resubmitted',        'dot' => '#ca8a04'],
                    ['label' => 'Approved',          'key' => 'approved',           'dot' => '#059669'],
                ];
                $total = max($stats['total'], 1);
                foreach ($breakdown as $b):
                    $pct = round(($stats[$b['key']] / $total) * 100);
                ?>
                    <div class="s-row">
                        <span class="s-dot" style="background:<?= $b['dot'] ?>"></span>
                        <span class="s-name"><?= $b['label'] ?></span>
                        <span class="s-num" style="color:<?= $b['dot'] ?>"><?= number_format($stats[$b['key']]) ?></span>
                        <div class="s-bar-wrap">
                            <div class="s-bar-fill" style="width:<?= $pct ?>%;background:<?= $b['dot'] ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div><!-- /status card -->

        </div><!-- /z-mid -->

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK  (exact dashboard.php structure)
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk active">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
                <?php if ($my_assigned_count > 0): ?>
                    <span class="dk-badge"><?= $my_assigned_count > 99 ? '99+' : $my_assigned_count ?></span>
                <?php endif; ?>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.php" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <a href="settings.php" class="dk">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>

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
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>