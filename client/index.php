<?php
session_start();
require_once '../db.php';
require_once '../auth.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}
date_default_timezone_set('Asia/Manila');

$client_email = $_SESSION['user_email'];
$client_name  = $_SESSION['full_name'] ?? 'Client';
$client_id    = null;
$app_count    = 0;
$my_apps      = [];

$stmt = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    $client_id = (int)$row['cid'];
    $cnt = $database->query("SELECT COUNT(*) as c FROM applications WHERE client_id = $client_id");
    if ($cnt) $app_count = (int)$cnt->fetch_assoc()['c'];
    $res = $database->query("
        SELECT a.id, a.status, a.updated_at, r.position_title, r.worksite_country, r.rsf_number
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.client_id = $client_id
        ORDER BY a.updated_at DESC
        LIMIT 10
    ");
    if ($res) $my_apps = $res->fetch_all(MYSQLI_ASSOC);
}

$latest_status = !empty($my_apps) ? $my_apps[0]['status'] : null;

// ── Profile completeness gate ────────────────────────────────────────────────
$profile_complete = false;
if ($client_id) {
    $pstmt = $database->prepare(
        "SELECT profile_completed_at FROM client_profiles WHERE client_id = ? LIMIT 1"
    );
    $pstmt->bind_param("i", $client_id);
    $pstmt->execute();
    $prow = $pstmt->get_result()->fetch_assoc();
    $pstmt->close();
    $profile_complete = ($prow && !empty($prow['profile_completed_at']));
}

// Show profile modal automatically on new registration OR if profile incomplete
$show_profile_modal = !$profile_complete;

// Degree programs, grouped by category, for the education step's degree picker
$degreeGroups = [];
if ($show_profile_modal) {
    $dgResult = $database->query("SELECT id, degree_name, degree_category FROM degree_programs ORDER BY degree_category ASC, degree_name ASC");
    $degreeRows = $dgResult ? $dgResult->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($degreeRows as $d) {
        $cat = $d['degree_category'] ?: 'Others';
        $degreeGroups[$cat][] = $d;
    }
    // Ensure "Others" renders as the last group
    if (isset($degreeGroups['Others'])) {
        $othersGroup = $degreeGroups['Others'];
        unset($degreeGroups['Others']);
        $degreeGroups['Others'] = $othersGroup;
    }
}

// Avatar initials
$client_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $client_name), 0, 2)) ?: 'U';

// Greeting
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first    = explode(' ', trim($client_name))[0] ?: $client_name;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Client Dashboard – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Layout ── */
        .main {
            max-width: 960px;
            margin: 0 auto;
            padding: 14px 14px calc(var(--dock-h) + 36px + env(safe-area-inset-bottom));
        }

        /* ── Section label ── */
        .sec-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* ── Quick action cards ── */
        .quick-grid {
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
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
            transition: background .18s, transform .18s, box-shadow .18s;
            border: none;
            font-family: 'DM Sans', sans-serif;
        }

        .quick-btn:hover {
            background: #f4f6fb;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px -4px rgba(11, 31, 75, .1);
        }

        .quick-btn:active {
            transform: scale(.97);
        }

        .quick-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        /* ── KPI cards (reuse z-stats + kpi-card from css) ── */

        /* ── Application list card ── */
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .card-title {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text2);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ctdot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        .card-link {
            font-size: 11px;
            font-weight: 600;
            color: var(--blue-lt);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            opacity: .75;
            transition: opacity .2s;
        }

        .card-link:hover {
            opacity: 1;
        }

        /* ── App rows ── */
        .app-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 16px;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background .14s;
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
            border-radius: 10px;
            background: #eff6ff;
            color: var(--blue);
            font-size: 11px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
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
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-right {
            text-align: right;
            flex-shrink: 0;
        }

        .app-date {
            font-size: 10px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 3px;
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

        .sp-pending {
            background: #f1f5f9;
            color: #475569;
        }

        .sp-default {
            background: #f1f5f9;
            color: #475569;
        }

        /* Status banner */
        .status-banner {
            margin: 0 16px 16px;
            border-radius: 12px;
            padding: 14px 16px;
            border: 1px solid;
        }

        .status-banner h4 {
            font-size: 12.5px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .status-banner p {
            font-size: 11.5px;
            font-weight: 500;
            line-height: 1.5;
        }

        .status-banner-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .sb-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 9px;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            transition: opacity .18s;
        }

        .sb-btn:hover {
            opacity: .85;
        }

        /* Empty state */
        .empty-state {
            padding: 36px 16px;
            text-align: center;
        }

        .empty-state i {
            font-size: 30px;
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
        }

        .empty-state a {
            color: var(--blue-lt);
            font-weight: 600;
            text-decoration: none;
        }

        /* ── Modals ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 75, .6);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 500;
            padding: 16px;
        }

        .modal-overlay.hidden {
            display: none;
        }

        .modal-box {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 60px rgba(11, 31, 75, .3);
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: #fff;
            border-radius: 18px 18px 0 0;
            z-index: 2;
        }

        .modal-head h3 {
            font-family: 'DM Serif Display', serif;
            font-size: 16px;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .modal-close {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text2);
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .15s;
            flex-shrink: 0;
        }

        .modal-close:hover {
            background: var(--border);
        }

        .modal-body {
            padding: 18px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid var(--border);
            background: var(--surface);
            border-radius: 0 0 18px 18px;
        }

        .modal-btn {
            padding: 9px 20px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: 'DM Sans', sans-serif;
            transition: opacity .18s;
        }

        .modal-btn:hover {
            opacity: .88;
        }

        .modal-btn-secondary {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text2);
        }

        .modal-btn-primary {
            background: var(--blue);
            color: #fff;
        }

        /* Warning modal content */
        .warn-list {
            padding-left: 18px;
            margin: 10px 0 0;
        }

        .warn-list li {
            font-size: 12.5px;
            color: var(--text2);
            line-height: 1.6;
            margin-bottom: 6px;
        }

        .warn-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 11px;
            padding: 12px 14px;
            margin-top: 12px;
        }

        .warn-box p {
            font-size: 12px;
            font-weight: 600;
            color: #92400e;
        }

        .warn-box span {
            font-size: 11.5px;
            color: #92400e;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .checkbox-row label {
            font-size: 12px;
            color: var(--text2);
            font-weight: 500;
            cursor: pointer;
        }

        /* Fade-in */
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
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar"><?= htmlspecialchars($client_initials) ?></div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════
         SUBBAR
    ══════════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first) ?></h2>
                <p><?= $app_count > 0
                        ? number_format($app_count) . ' active application' . ($app_count !== 1 ? 's' : '')
                        : 'No applications yet — browse jobs to get started' ?></p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ -->
    <div class="main">

        <!-- Quick Actions -->
        <p class="sec-label fade-in d1">Quick Actions</p>
        <div class="quick-grid fade-in d2">

            <button class="quick-btn" onclick="showBrowseWarningModal()">
                <div class="quick-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-briefcase"></i>
                </div>
                Browse Jobs
            </button>

            <a href="my-applications.php" class="quick-btn">
                <div class="quick-icon" style="background:#eef2ff;color:#6366f1;">
                    <i class="fas fa-file-lines"></i>
                </div>
                My Applications
            </a>

            <a href="notifications.php" class="quick-btn">
                <div class="quick-icon" style="background:#ecfdf5;color:#059669;">
                    <i class="fas fa-bell"></i>
                </div>
                Notifications
            </a>

        </div>

        <!-- KPI Stats -->
        <p class="sec-label fade-in d2">Overview</p>
        <div class="z-stats fade-in d3">

            <div class="kpi-card c-blue">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">My Applications</div>
                        <div class="kpi-value c-blue"><?= $app_count ?></div>
                    </div>
                    <div class="kpi-icon c-blue"><i class="fas fa-file-lines"></i></div>
                </div>
                <div class="kpi-sub">Total submitted</div>
            </div>

            <?php
            $statusCounts = ['approved' => 0, 'under_evaluation' => 0, 'for_compliance' => 0];
            foreach ($my_apps as $a) {
                $s = $a['status'];
                if ($s === 'approved') {
                    $statusCounts['approved']++;
                } elseif (in_array($s, ['under_evaluation', 'assign', 'assigned'], true)) {
                    $statusCounts['under_evaluation']++;
                } elseif ($s === 'for_compliance') {
                    $statusCounts['for_compliance']++;
                }
            }
            ?>

            <div class="kpi-card c-green">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Approved</div>
                        <div class="kpi-value c-green"><?= $statusCounts['approved'] ?></div>
                    </div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-sub">Applications approved</div>
            </div>

            <div class="kpi-card c-indigo">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">Under Review</div>
                        <div class="kpi-value c-indigo"><?= $statusCounts['under_evaluation'] ?></div>
                    </div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-sub">Being evaluated now</div>
            </div>

            <div class="kpi-card c-amber">
                <div class="kpi-top">
                    <div>
                        <div class="kpi-label">For Compliance</div>
                        <div class="kpi-value c-amber"><?= $statusCounts['for_compliance'] ?></div>
                    </div>
                    <div class="kpi-icon c-amber"><i class="fas fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-sub">Needs your attention</div>
            </div>

        </div>

        <!-- My Recent Applications -->
        <p class="sec-label fade-in d4" style="margin-top:1.3rem">My Recent Applications</p>
        <div class="card fade-in d4">
            <div class="card-head">
                <span class="card-title">
                    <span class="ctdot" style="background:var(--blue-lt)"></span>
                    Applications
                </span>
                <a href="my-applications.php" class="card-link">
                    View all <i class="fas fa-arrow-right" style="font-size:9px"></i>
                </a>
            </div>

            <?php if (empty($my_apps)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No applications yet</p>
                    <span><a href="jobs.php">Browse job opportunities</a> to submit your first application.</span>
                </div>
            <?php else: ?>
                <?php foreach ($my_apps as $app): ?>
                    <?php
                    $s    = strtolower($app['status'] ?? '');
                    $stxt = str_replace('_', ' ', ucwords($s));
                    $pill = match ($s) {
                        'approved'         => 'sp-approved',
                        'under_evaluation' => 'sp-under',
                        'for_compliance'   => 'sp-compliance',
                        'assign'           => 'sp-assigned',
                        'assigned'         => 'sp-assigned',
                        'disapproved'      => 'sp-disapproved',
                        'resubmitted'      => 'sp-under',
                        'unassign'         => 'sp-pending',
                        'unassigned'       => 'sp-pending',
                        'pending'          => 'sp-pending',
                        default            => 'sp-default',
                    };
                    $posInitials = strtoupper(substr($app['position_title'] ?? 'J', 0, 2));
                    ?>
                    <a href="edit-application.php?id=<?= (int) ($app['id'] ?? 0) ?>" class="app-row">
                        <div class="app-avatar"><?= htmlspecialchars($posInitials) ?></div>
                        <div class="app-info">
                            <div class="app-name"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                            <div class="app-meta">
                                <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                                &nbsp;·&nbsp;<?= htmlspecialchars($app['worksite_country'] ?? '—') ?>
                            </div>
                        </div>
                        <div class="app-right">
                            <span class="status-pill <?= $pill ?>"><?= htmlspecialchars($stxt) ?></span>
                            <div class="app-date">
                                <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card fade-in d5">

            <!-- Status banner -->
            <?php if ($latest_status): ?>
                <?php
                $bannerColor = match (true) {
                    in_array($latest_status, ['pending', 'unassign', 'unassigned'], true) => ['bg' => '#fffbeb', 'border' => '#fde68a', 'h' => '#92400e', 'p' => '#78350f'],
                    in_array($latest_status, ['assign', 'assigned', 'under_evaluation'], true) => ['bg' => '#eef2ff', 'border' => '#c7d2fe', 'h' => '#3730a3', 'p' => '#4338ca'],
                    $latest_status === 'resubmitted'                         => ['bg' => '#e0f2fe', 'border' => '#7dd3fc', 'h' => '#0369a1', 'p' => '#0c4a6e'],
                    $latest_status === 'for_compliance'                        => ['bg' => '#fff7ed', 'border' => '#fed7aa', 'h' => '#9a3412', 'p' => '#7c2d12'],
                    $latest_status === 'approved'                              => ['bg' => '#d1fae5', 'border' => '#a7f3d0', 'h' => '#065f46', 'p' => '#047857'],
                    $latest_status === 'disapproved'                           => ['bg' => '#fee2e2', 'border' => '#fecaca', 'h' => '#991b1b', 'p' => '#b91c1c'],
                    default                                                    => ['bg' => '#f1f5f9', 'border' => '#e2e8f0', 'h' => '#1e293b', 'p' => '#475569'],
                };
                $bannerMsg = match (true) {
                    in_array($latest_status, ['pending', 'unassign', 'unassigned'], true) => 'Your application has been submitted and is awaiting evaluator assignment.',
                    in_array($latest_status, ['assign', 'assigned', 'under_evaluation'], true) => 'Your application is being evaluated. You will be notified if additional information is needed.',
                    $latest_status === 'resubmitted'                         => 'You resubmitted your application. An evaluator will review your updates.',
                    $latest_status === 'for_compliance'                        => 'Please review the evaluator\'s notes and update your application in My Applications.',
                    $latest_status === 'approved'                              => 'Congratulations! Your application has been approved.',
                    $latest_status === 'disapproved'                           => 'Your application was not approved. You may apply for other positions.',
                    default                                                    => 'Track your application progress in My Applications.',
                };
                ?>
                <div class="status-banner"
                    style="background:<?= $bannerColor['bg'] ?>;border-color:<?= $bannerColor['border'] ?>;">
                    <h4 style="color:<?= $bannerColor['h'] ?>">
                        Latest Status: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $latest_status))) ?>
                    </h4>
                    <p style="color:<?= $bannerColor['p'] ?>"><?= $bannerMsg ?></p>
                    <div class="status-banner-btns">
                        <a href="my-applications.php" class="sb-btn" style="background:var(--navy);color:#fff;">
                            <i class="fas fa-file-lines"></i> View My Applications
                        </a>
                        <a href="jobs.php" class="sb-btn" style="background:var(--card);border:1px solid var(--border);color:var(--text2);">
                            <i class="fas fa-briefcase"></i> Browse More Jobs
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-banner" style="background:#eff6ff;border-color:#bfdbfe;">
                    <h4 style="color:var(--navy)">Get Started</h4>
                    <p style="color:var(--blue)">Browse job opportunities and apply for positions that match your qualifications.</p>
                    <div class="status-banner-btns">
                        <button onclick="showBrowseWarningModal()" class="sb-btn" style="background:var(--blue);color:#fff;">
                            <i class="fas fa-briefcase"></i> Browse Jobs
                        </button>
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- /process-card -->

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk active">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk ">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk ">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?>
                    <span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span>
                <?php endif; ?>
            </a>

            <a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a>

            <div class="dk-div"></div>

            <a href="setting.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <!-- ══════════════════════════════════════════
         BROWSE WARNING MODAL
    ══════════════════════════════════════════ -->
    <div id="browseWarningModal" class="modal-overlay hidden">
        <div class="modal-box">
            <div class="modal-head">
                <h3>
                    <span style="width:32px;height:32px;border-radius:9px;background:#fffbeb;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:14px;flex-shrink:0;">
                        <i class="fas fa-shield-halved"></i>
                    </span>
                    Before Browsing Jobs
                </h3>
                <button class="modal-close" onclick="closeBrowseModal()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:12.5px;font-weight:700;color:#92400e;margin-bottom:6px;">Only use official and verified job orders!</p>
                <ul class="warn-list">
                    <li>All positions come from <strong>approved RSF job orders</strong> issued by the <strong>Department of Migrant Workers (DMW)</strong>.</li>
                    <li><strong>NEVER PAY ANYTHING</strong> before deployment — legitimate employers do not require upfront fees.</li>
                    <li>Beware of <strong>illegal recruiters</strong>, social media offers, and "fixers" asking for money outside official channels.</li>
                    <li>Always read the full RSF PDF before applying — it contains complete job details, salary, and contract terms.</li>
                    <li>If something seems suspicious, contact DMW Regional Office IV-A immediately.</li>
                </ul>
                <div class="warn-box">
                    <p>Hotline / Report Illegal Recruitment:</p>
                    <span>DMW Hotline: <strong>1348</strong> (nationwide) or <strong>(02) 8722-1144</strong></span><br>
                    <span style="font-size:11px;color:#78350f;">Official website: <strong>dmw.gov.ph</strong></span>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="dontShowAgain" style="width:16px;height:16px;accent-color:var(--blue);cursor:pointer;">
                    <label for="dontShowAgain">Do not show this message again today</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeBrowseModal()">Cancel</button>
                <button class="modal-btn modal-btn-primary" id="proceedBtn">
                    Proceed to Jobs &nbsp;<i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        /* Live date */
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        /* Browse warning modal */
        function showBrowseWarningModal() {
            const lastShown = localStorage.getItem('browseWarningLastShown');
            const today = new Date().toDateString();
            if (lastShown === today) {
                window.location.href = 'jobs.php';
            } else {
                document.getElementById('browseWarningModal').classList.remove('hidden');
            }
        }

        function closeBrowseModal() {
            document.getElementById('browseWarningModal').classList.add('hidden');
        }

        document.getElementById('proceedBtn').addEventListener('click', function() {
            if (document.getElementById('dontShowAgain').checked) {
                localStorage.setItem('browseWarningLastShown', new Date().toDateString());
            }
            closeBrowseModal();
            window.location.href = 'jobs.php';
        });

        /* Close modal on backdrop click */
        document.getElementById('browseWarningModal').addEventListener('click', function(e) {
            if (e.target === this) this.classList.add('hidden');
        });
    </script>
    <!-- ══════════════════════════════════════════
         PROFILE COMPLETION MODAL
    ══════════════════════════════════════════ -->
    <?php if ($show_profile_modal): ?>
        <style>
            /* Step indicator */
            .profile-steps {
                display: flex;
                align-items: center;
                gap: 0;
                margin-bottom: 22px;
            }

            .step-node {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                font-weight: 800;
                flex-shrink: 0;
                transition: background .2s, color .2s;
            }

            .step-node.done {
                background: var(--blue);
                color: #fff;
            }

            .step-node.active {
                background: var(--navy);
                color: #fff;
            }

            .step-node.idle {
                background: var(--border);
                color: var(--text3);
            }

            .step-line {
                flex: 1;
                height: 2px;
                background: var(--border);
                transition: background .2s;
            }

            .step-line.done {
                background: var(--blue);
            }

            /* Step panels */
            .step-panel {
                display: none;
            }

            .step-panel.active {
                display: block;
            }

            /* Form grid */
            .pf-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .pf-full {
                grid-column: 1 / -1;
            }

            @media(max-width:500px) {
                .pf-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Field */
            .pf-field label {
                display: block;
                font-size: 10.5px;
                font-weight: 700;
                color: var(--text2);
                letter-spacing: .04em;
                text-transform: uppercase;
                margin-bottom: 5px;
            }

            .pf-field input,
            .pf-field select {
                width: 100%;
                padding: 9px 11px;
                border: 1.5px solid var(--border);
                border-radius: 9px;
                font-family: 'DM Sans', sans-serif;
                font-size: 13px;
                color: var(--text1);
                background: #fff;
                transition: border-color .15s;
                box-sizing: border-box;
            }

            .pf-field input:focus,
            .pf-field select:focus {
                outline: none;
                border-color: var(--blue-lt);
            }

            .pf-field .req {
                color: #ef4444;
            }

            /* Section heading */
            .pf-section-heading {
                font-family: 'DM Serif Display', serif;
                font-size: 15px;
                color: var(--navy);
                margin-bottom: 14px;
            }

            .pf-subtitle {
                font-size: 11.5px;
                color: var(--text3);
                margin-bottom: 18px;
                line-height: 1.55;
            }

            /* Lock banner shown when profile incomplete */
            .profile-lock-banner {
                background: #fff7ed;
                border: 1.5px solid #fed7aa;
                border-radius: 12px;
                padding: 14px 16px;
                margin-bottom: 12px;
                display: flex;
                align-items: flex-start;
                gap: 12px;
            }

            .profile-lock-banner i {
                color: #f97316;
                font-size: 18px;
                margin-top: 2px;
            }

            .profile-lock-banner h4 {
                font-size: 12.5px;
                font-weight: 800;
                color: #9a3412;
            }

            .profile-lock-banner p {
                font-size: 11.5px;
                color: #c2410c;
                margin-top: 3px;
                line-height: 1.5;
            }

            .profile-lock-banner button {
                margin-top: 10px;
                background: var(--navy);
                color: #fff;
                border: none;
                padding: 8px 16px;
                border-radius: 9px;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
                font-family: 'DM Sans', sans-serif;
            }
        </style>

        <div id="profileModal" class="modal-overlay" style="z-index:600;">
            <div class="modal-box" style="max-width:600px;">
                <div class="modal-head">
                    <h3>
                        <span style="width:32px;height:32px;border-radius:9px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:14px;flex-shrink:0;">
                            <i class="fas fa-id-card"></i>
                        </span>
                        Complete Your Applicant Profile
                    </h3>
                    <!-- No close button — must complete -->
                </div>
                <div class="modal-body">

                    <!-- Step indicator -->
                    <div class="profile-steps" id="profileStepIndicator">
                        <div class="step-node active" id="sn1">1</div>
                        <div class="step-line" id="sl1"></div>
                        <div class="step-node idle" id="sn2">2</div>
                        <div class="step-line" id="sl2"></div>
                        <div class="step-node idle" id="sn3">3</div>
                    </div>

                    <form id="profileForm">

                        <!-- ── STEP 1: Personal Info ── -->
                        <div class="step-panel active" id="stepPanel1">
                            <p class="pf-section-heading">Personal Information</p>
                            <p class="pf-subtitle">Please provide accurate details. These will be used to match you with job opportunities.</p>
                            <div class="pf-grid">
                                <div class="pf-field">
                                    <label>Citizenship <span class="req">*</span></label>
                                    <input type="text" name="citizenship" value="Filipino" placeholder="e.g. Filipino" required>
                                </div>
                                <div class="pf-field">
                                    <label>Civil Status <span class="req">*</span></label>
                                    <select name="civil_status" required>
                                        <option value="">Select…</option>
                                        <option>Single</option>
                                        <option>Married</option>
                                        <option>Widowed</option>
                                        <option>Separated</option>
                                        <option>Annulled</option>
                                    </select>
                                </div>
                                <div class="pf-field">
                                    <label>Religion</label>
                                    <input type="text" name="religion" placeholder="e.g. Catholic">
                                </div>
                                <div class="pf-field" style="grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div>
                                        <label>Height (cm) <span class="req">*</span></label>
                                        <input type="number" name="height_cm" placeholder="e.g. 165" min="100" max="250" step="0.5" required>
                                    </div>
                                    <div>
                                        <label>Weight (kg) <span class="req">*</span></label>
                                        <input type="number" name="weight_kg" placeholder="e.g. 60" min="30" max="300" step="0.5" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── STEP 2: Education ── -->
                        <div class="step-panel" id="stepPanel2">
                            <p class="pf-section-heading">Educational Background</p>
                            <p class="pf-subtitle">This information is required for evaluating your eligibility for specific job orders.</p>
                            <div class="pf-grid">
                                <div class="pf-field pf-full">
                                    <label>Highest Educational Attainment <span class="req">*</span></label>
                                    <select name="educational_attainment" id="eduSelect" required>
                                        <option value="">Select…</option>
                                        <option>Elementary Graduate</option>
                                        <option>High School Graduate</option>
                                        <option>Senior High School Graduate</option>
                                        <option>Vocational/Technical</option>
                                        <option>College Undergraduate</option>
                                        <option>College Graduate</option>
                                        <option>Post Graduate</option>
                                    </select>
                                </div>
                                <div class="pf-field pf-full" id="courseDegreeField" style="display:none;">
                                    <label>Course / Degree</label>
                                    <select name="degree_id" id="degreeSelect">
                                        <option value="">Select…</option>
                                        <?php foreach ($degreeGroups as $category => $degrees): ?>
                                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                                <?php foreach ($degrees as $d): ?>
                                                    <option value="<?= (int) $d['id'] ?>"
                                                        data-name="<?= htmlspecialchars($d['degree_name'], ENT_QUOTES) ?>"
                                                        <?= strcasecmp($d['degree_name'], 'Others') === 0 ? 'data-others="1"' : '' ?>>
                                                        <?= htmlspecialchars($d['degree_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="course_degree" id="courseDegreeInput"
                                        placeholder="Please specify your course/degree" style="display:none;margin-top:8px;">
                                </div>
                                <div class="pf-field pf-full">
                                    <label>School / Institution Name</label>
                                    <input type="text" name="school_name" placeholder="e.g. City College of Calamba">
                                </div>
                                <div class="pf-field pf-full">
                                    <label>School Address</label>
                                    <input type="text" name="school_address" placeholder="e.g. Calamba City, Laguna">
                                </div>
                                <div class="pf-field">
                                    <label>Year Graduated</label>
                                    <input type="number" name="year_graduated" placeholder="e.g. 2022" min="1970" max="<?= date('Y') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ── STEP 3: Work Experience ── -->
                        <div class="step-panel" id="stepPanel3">
                            <p class="pf-section-heading">Work Experience</p>
                            <p class="pf-subtitle">If you have no prior work experience, you may leave these fields blank.</p>
                            <div class="pf-grid">
                                <div class="pf-field pf-full">
                                    <label>Years of Work Experience</label>
                                    <input type="number" name="work_experience_years" placeholder="e.g. 3" min="0" max="50">
                                </div>
                                <div class="pf-field pf-full">
                                    <label>Last / Current Employer</label>
                                    <input type="text" name="last_employer" placeholder="e.g. ABC Manufacturing Corp.">
                                </div>
                                <div class="pf-field pf-full">
                                    <label>Last / Current Position</label>
                                    <input type="text" name="last_position" placeholder="e.g. Production Operator">
                                </div>
                            </div>
                        </div>

                    </form><!-- /profileForm -->

                    <div id="profileError" style="display:none;background:#fee2e2;border:1px solid #fecaca;border-radius:9px;padding:10px 13px;margin-top:12px;font-size:12px;font-weight:600;color:#991b1b;"></div>

                </div><!-- /modal-body -->
                <div class="modal-footer" id="profileModalFooter">
                    <button class="modal-btn modal-btn-secondary" id="profileBackBtn" style="display:none;" onclick="profileStep(-1)">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button class="modal-btn modal-btn-primary" id="profileNextBtn" onclick="profileStep(1)">
                        Next &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const TOTAL_STEPS = 3;
                let currentStep = 1;

                // Edu field toggle
                document.getElementById('eduSelect').addEventListener('change', function() {
                    const showCourse = ['College Undergraduate', 'College Graduate', 'Post Graduate',
                        'Vocational/Technical', 'Senior High School Graduate'
                    ].includes(this.value);
                    document.getElementById('courseDegreeField').style.display = showCourse ? 'block' : 'none';
                });

                // Degree picker: auto-fill course_degree from the selected degree name,
                // or reveal a free-text field when "Others" is chosen.
                document.getElementById('degreeSelect').addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    const input = document.getElementById('courseDegreeInput');
                    if (!this.value) {
                        input.style.display = 'none';
                        input.value = '';
                        return;
                    }
                    if (opt.dataset.others === '1') {
                        input.style.display = 'block';
                        input.value = '';
                        input.placeholder = 'Please specify your course/degree';
                        input.focus();
                    } else {
                        input.style.display = 'none';
                        input.value = opt.dataset.name || '';
                    }
                });

                function updateStepUI() {
                    for (let i = 1; i <= TOTAL_STEPS; i++) {
                        document.getElementById('stepPanel' + i).classList.toggle('active', i === currentStep);
                        const node = document.getElementById('sn' + i);
                        if (i < currentStep) node.className = 'step-node done';
                        else if (i === currentStep) node.className = 'step-node active';
                        else node.className = 'step-node idle';
                        if (i < TOTAL_STEPS) {
                            document.getElementById('sl' + i).className = 'step-line' + (i < currentStep ? ' done' : '');
                        }
                    }
                    document.getElementById('profileBackBtn').style.display = currentStep > 1 ? '' : 'none';
                    const nextBtn = document.getElementById('profileNextBtn');
                    if (currentStep === TOTAL_STEPS) {
                        nextBtn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                    } else {
                        nextBtn.innerHTML = 'Next &nbsp;<i class="fas fa-arrow-right"></i>';
                    }
                }

                function validateStep(step) {
                    const panel = document.getElementById('stepPanel' + step);
                    const required = panel.querySelectorAll('[required]');
                    for (const el of required) {
                        if (!el.value.trim()) {
                            el.focus();
                            showError('Please fill in all required fields (marked with *).');
                            return false;
                        }
                    }
                    hideError();
                    return true;
                }

                function showError(msg) {
                    const el = document.getElementById('profileError');
                    el.textContent = msg;
                    el.style.display = 'block';
                }

                function hideError() {
                    document.getElementById('profileError').style.display = 'none';
                }

                window.profileStep = function(dir) {
                    if (dir === 1) {
                        if (!validateStep(currentStep)) return;
                        if (currentStep === TOTAL_STEPS) {
                            submitProfile();
                            return;
                        }
                        currentStep++;
                    } else {
                        currentStep = Math.max(1, currentStep - 1);
                    }
                    updateStepUI();
                };

                function submitProfile() {
                    const btn = document.getElementById('profileNextBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

                    const formData = new FormData(document.getElementById('profileForm'));

                    fetch('complete-profile.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('profileModal').style.display = 'none';
                                // Reload to refresh dashboard state
                                window.location.replace('index.php');
                            } else {
                                showError(data.message || 'Something went wrong. Please try again.');
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                            }
                        })
                        .catch(() => {
                            showError('Network error. Please check your connection and try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Submit Profile';
                        });
                }

                updateStepUI();

                // Prevent closing modal on backdrop click
                document.getElementById('profileModal').addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            })();
        </script>
    <?php endif; ?>

    <!-- Lock overlay for incomplete profile on interactive elements -->
    <?php if ($show_profile_modal): ?>
        <script>
            // Override Browse Jobs button & jobs.php / my-applications.php links
            // to redirect back to modal instead of navigating away
            document.addEventListener('DOMContentLoaded', function() {
                // Replace Browse Jobs warning modal trigger
                window.showBrowseWarningModal = function() {
                    document.getElementById('profileModal').style.display = 'flex';
                };

                // Intercept all navigation links to gated pages
                const gated = ['jobs.php', 'my-applications.php'];
                document.querySelectorAll('a').forEach(function(a) {
                    const href = (a.getAttribute('href') || '');
                    if (gated.some(g => href.includes(g))) {
                        a.addEventListener('click', function(e) {
                            e.preventDefault();
                            document.getElementById('profileModal').style.display = 'flex';
                        });
                    }
                });
            });
        </script>
    <?php endif; ?>

    <!-- Lock banner injected into page when profile incomplete -->
    <?php if ($show_profile_modal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const main = document.querySelector('.main');
                if (!main) return;
                const banner = document.createElement('div');
                banner.className = 'profile-lock-banner fade-in';
                banner.innerHTML = `
            <i class="fas fa-lock"></i>
            <div>
                <h4>Complete Your Profile to Unlock the Dashboard</h4>
                <p>You must fill in your applicant profile before you can browse jobs or submit applications. This ensures accurate matching with available job orders.</p>
                <button onclick="document.getElementById('profileModal').style.display='flex'">
                    <i class="fas fa-id-card"></i> &nbsp; Complete My Profile
                </button>
            </div>
        `;
                main.insertBefore(banner, main.firstChild);
            });
        </script>
    <?php endif; ?>

    <script type="module" src="../js/main.js"></script>
</body>

</html>