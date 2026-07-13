<?php
// evaluator/applications.php – List applications for evaluation
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

// Get evaluator eid
$evaluator_name = 'Evaluator';
$evaluator_id   = null;
$stmt_ev = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// Filter by status ('available' = unclaimed pool anyone can pick up;
// 'assign' includes legacy assigned)
$valid_statuses = ['available', 'assign', 'assigned', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$status_filter  = $_GET['status'] ?? '';
$status_filter  = in_array($status_filter, $valid_statuses, true) ? $status_filter : '';

// Build query
$applications = [];
if ($status_filter === 'available') {
    // Unclaimed pool: anyone can open one of these — opening it claims it.
    $sql = "
        SELECT a.id, a.status, a.updated_at,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted')
        ORDER BY a.updated_at ASC
    ";
    $stmt = $database->prepare($sql);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($evaluator_id) {
    // My cases: anything I currently own, plus anything on record as mine
    // even while Admin has it for compliance (current_evaluator_id keeps
    // it visible to me, read-only, until Admin reopens or reassigns it).
    $sql = "
        SELECT a.id, a.status, a.updated_at, a.handling_party,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
    ";
    $params = [$evaluator_id];
    $types  = "i";

    if ($status_filter === 'assign' || $status_filter === 'assigned') {
        $sql .= " AND a.status IN ('assign','assigned')";
    } elseif ($status_filter) {
        $sql .= " AND a.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    $sql .= " ORDER BY a.updated_at DESC";

    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Stats for filter pills
$stats = ['available' => 0, 'assign' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'approved' => 0, 'disapproved' => 0, 'resubmitted' => 0];
$res   = $database->query("SELECT status, handling_party, COUNT(*) as cnt FROM applications GROUP BY status, handling_party");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $s = $row['status'];
        $cnt = (int) $row['cnt'];
        if ($row['handling_party'] === null && in_array($s, ['pending', 'assign', 'assigned', 'resubmitted'], true)) {
            $stats['available'] += $cnt;
        }
        if ($s === 'assigned' || $s === 'assign') {
            $stats['assign'] += $cnt;
        } elseif (isset($stats[$s])) {
            $stats[$s] += $cnt;
        }
    }
}

$page_title = $status_filter
    ? ucwords(str_replace('_', ' ', $status_filter))
    : 'My Cases';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – Evaluator Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Shared design system -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Page-specific styles ── */

        .main {
            padding: 14px 14px 0;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page header row */
        .page-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .page-hd-left h2 {
            font-family: 'DM Serif Display', serif;
            font-size: 20px;
            color: var(--navy);
            line-height: 1.2;
        }

        .page-hd-left p {
            font-size: 11.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 2px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text2);
            text-decoration: none;
            transition: background .15s, transform .15s;
            white-space: nowrap;
        }

        .btn-back:hover {
            background: var(--surface);
            transform: translateY(-1px);
        }

        /* Filter pills row */
        .filter-pills {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .fpill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 13px;
            border-radius: 99px;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            border: 1.5px solid var(--border);
            background: var(--card);
            color: var(--text2);
            transition: all .18s;
            cursor: pointer;
            white-space: nowrap;
        }

        .fpill:hover {
            border-color: var(--blue-lt);
            color: var(--blue);
            background: #eff6ff;
        }

        .fpill.active {
            background: var(--navy);
            border-color: var(--navy);
            color: #fff;
        }

        .fpill-count {
            background: rgba(255, 255, 255, .18);
            color: inherit;
            font-size: 9.5px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 99px;
        }

        .fpill:not(.active) .fpill-count {
            background: var(--surface);
            color: var(--text3);
        }

        /* Application cards list */
        .app-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 8px;
            transition: box-shadow .18s, transform .18s;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .app-card:hover {
            box-shadow: 0 8px 24px -4px rgba(11, 31, 75, .12);
            transform: translateY(-2px);
        }

        .app-card:active {
            transform: scale(.99);
        }

        .app-card-inner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
        }

        .app-card-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #eef2ff;
            color: var(--indigo);
            font-size: 12px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            letter-spacing: .02em;
        }

        .app-card-body {
            flex: 1;
            min-width: 0;
        }

        .app-card-name {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-card-sub {
            font-size: 11px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-card-meta {
            font-size: 10.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 3px;
        }

        .app-card-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            flex-shrink: 0;
        }

        .status-pill {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: 3px 10px;
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

        .app-card-date {
            font-size: 10px;
            color: var(--text3);
            font-weight: 500;
        }

        .app-card-arrow {
            font-size: 11px;
            color: var(--text3);
            margin-top: 2px;
        }

        /* Desktop: table-like layout */
        @media (min-width: 768px) {
            .app-card-inner {
                padding: 12px 20px;
                gap: 16px;
            }

            .app-card-avatar {
                width: 38px;
                height: 38px;
            }
        }

        /* Empty state */
        .empty-state {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 52px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 36px;
            color: var(--border);
            display: block;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 13px;
            font-weight: 700;
            color: var(--text2);
        }

        .empty-state span {
            font-size: 11.5px;
            color: var(--text3);
        }

        /* Section label */
        .sec-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* Fade-in animations */
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
            animation-delay: .04s
        }

        .d2 {
            animation-delay: .08s
        }

        .d3 {
            animation-delay: .12s
        }

        .d4 {
            animation-delay: .16s
        }

        .d5 {
            animation-delay: .20s
        }

        .d6 {
            animation-delay: .24s
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
                <h2><?= htmlspecialchars($page_title) ?></h2>
                <p>(<?= count($applications) ?>) application<?= count($applications) !== 1 ? 's' : '' ?> found</p>
            </div>
        </div>

        <!-- Scrollable filter pills -->
        <div class="filter-scroll">

            <a href="applications.php"
                class="filter-pill <?= !$status_filter ? 'active' : '' ?>">
                <i class="fas fa-list"></i> All
            </a>

            <a href="applications.php?status=available"
                class="filter-pill <?= $status_filter === 'available' ? 'active' : '' ?>">
                <i class="fas fa-inbox"></i> Available
                <?php if ($stats['available'] > 0): ?>
                    <span style="background:rgba(5,150,105,.15);color:#047857;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['available'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=assign"
                class="filter-pill <?= ($status_filter === 'assign' || $status_filter === 'assigned') ? 'active' : '' ?>">
                <i class="fas fa-user-check"></i> Assign
                <?php if ($stats['assign'] > 0): ?>
                    <span style="background:rgba(59,130,246,.15);color:#1d4ed8;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['assign'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=under_evaluation"
                class="filter-pill <?= $status_filter === 'under_evaluation' ? 'active' : '' ?>">
                <i class="fas fa-magnifying-glass"></i> Under Evaluation
                <?php if ($stats['under_evaluation'] > 0): ?>
                    <span style="background:rgba(79,70,229,.15);color:#4338ca;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['under_evaluation'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=for_compliance"
                class="filter-pill <?= $status_filter === 'for_compliance' ? 'active' : '' ?>">
                <i class="fas fa-triangle-exclamation"></i> For Compliance
                <?php if ($stats['for_compliance'] > 0): ?>
                    <span style="background:rgba(217,119,6,.15);color:#b45309;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['for_compliance'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=approved"
                class="filter-pill <?= $status_filter === 'approved' ? 'active' : '' ?>">
                <i class="fas fa-circle-check"></i> Approved
                <?php if ($stats['approved'] > 0): ?>
                    <span style="background:rgba(5,150,105,.15);color:#047857;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['approved'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=disapproved"
                class="filter-pill <?= $status_filter === 'disapproved' ? 'active' : '' ?>">
                <i class="fas fa-circle-xmark"></i> Disapproved
                <?php if ($stats['disapproved'] > 0): ?>
                    <span style="background:rgba(220,38,38,.15);color:#b91c1c;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['disapproved'] ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="applications.php?status=resubmitted"
                class="filter-pill <?= $status_filter === 'resubmitted' ? 'active' : '' ?>">
                <i class="fas fa-rotate-right"></i> Resubmitted
                <?php if ($stats['resubmitted'] > 0): ?>
                    <span style="background:rgba(234,179,8,.15);color:#a16207;font-size:9px;font-weight:800;padding:1px 6px;border-radius:99px;">
                        <?= $stats['resubmitted'] ?>
                    </span>
                <?php endif; ?>
            </a>

        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ -->
    <div class="main" style="padding-bottom: calc(var(--dock-h) + 28px + env(safe-area-inset-bottom));">

        <!-- Page header -->
        <div class="page-hd fade-in d1">
            <div class="page-hd-left">
                <h2><?= htmlspecialchars($page_title) ?></h2>
                <p><?= count($applications) ?> application<?= count($applications) !== 1 ? 's' : '' ?> found<?= $status_filter ? ' · filtered by "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?></p>
            </div>
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <!-- Application list -->
        <?php if (empty($applications)): ?>
            <div class="empty-state fade-in d2">
                <i class="fas fa-inbox"></i>
                <p>No applications found</p>
                <span>
                    <?php if ($status_filter === 'available'): ?>
                        Nothing waiting to be claimed right now — check back later.
                    <?php elseif ($status_filter): ?>
                        No cases of yours with status "<?= htmlspecialchars(str_replace('_', ' ', $status_filter)) ?>".
                    <?php else: ?>
                        You have no cases yet — check the Available tab to claim one.
                    <?php endif; ?>
                </span>
            </div>

        <?php else: ?>

            <?php foreach ($applications as $i => $app): ?>
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

                $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                $avi = strtoupper(count($np) >= 2
                    ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                    : substr($app['applicant_name'] ?? 'NA', 0, 2));

                $delay = 'd' . min($i + 2, 6);
                ?>
                <a href="view-application.php?id=<?= (int)$app['id'] ?>"
                    class="app-card fade-in <?= $delay ?>">
                    <div class="app-card-inner">

                        <div class="app-card-avatar"><?= htmlspecialchars($avi) ?></div>

                        <div class="app-card-body">
                            <div class="app-card-name"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                            <div class="app-card-sub"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                            <div class="app-card-meta">
                                <span style="color:var(--blue);font-weight:700;"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></span>
                                &nbsp;·&nbsp;<?= htmlspecialchars($app['position_title'] ?? '—') ?>
                                &nbsp;·&nbsp;<?= htmlspecialchars($app['employer_name'] ?? '—') ?>
                                &nbsp;·&nbsp;<?= htmlspecialchars($app['country'] ?? '—') ?>
                            </div>
                        </div>

                        <div class="app-card-right">
                            <span class="status-pill <?= $pill ?>"><?= htmlspecialchars($statusText) ?></span>
                            <div class="app-card-date">
                                <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                            </div>
                            <div class="app-card-arrow"><i class="fas fa-chevron-right"></i></div>
                        </div>

                    </div>
                </a>
            <?php endforeach; ?>

        <?php endif; ?>

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk active">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.html" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <a href="settings.html" class="dk">
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