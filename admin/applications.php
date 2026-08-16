<?php
//Admin: Applications - list, filter, assign evaluators
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$admin_email = $_SESSION['user_email'];
$admin_id = null;
$admin_name = 'Admin';
$stmt_ad = $database->prepare("SELECT aid, full_name FROM admins WHERE email = ? LIMIT 1");
$stmt_ad->bind_param("s", $admin_email);
$stmt_ad->execute();
$ad_row = $stmt_ad->get_result()->fetch_assoc();
$stmt_ad->close();
if ($ad_row) {
    $admin_id = (int) $ad_row['aid'];
    $admin_name = $ad_row['full_name'] ?? 'Admin';
}
$status_filter = $_GET['status'] ?? '';
$error = '';
$success = '';

// Assign evaluator (POST) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    $app_id = (int) ($_POST['application_id'] ?? 0);
    $evaluator_id = (int) ($_POST['evaluator_id'] ?? 0);
    if ($app_id && $evaluator_id) {
        $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $st = $row ? (string) ($row['status'] ?? '') : '';
        $assignable = in_array($st, ['unassign', 'unassigned', 'resubmitted'], true);
        if ($row && $assignable) {
            $stmt_asn = $database->prepare("SELECT id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
            $stmt_asn->bind_param("i", $app_id);
            $stmt_asn->execute();
            $has_active = $stmt_asn->get_result()->num_rows > 0;
            $stmt_asn->close();

            if ($has_active && $st !== 'resubmitted') {
                $error = 'This application already has an active evaluator assignment.';
            } elseif ($has_active && $st === 'resubmitted') {
                $stmt_cur = $database->prepare("SELECT evaluator_id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
                $stmt_cur->bind_param("i", $app_id);
                $stmt_cur->execute();
                $asn_row = $stmt_cur->get_result()->fetch_assoc();
                $stmt_cur->close();
                $cur_ev = (int) ($asn_row['evaluator_id'] ?? 0);
                if ($cur_ev !== $evaluator_id) {
                    $error = 'This resubmitted application is already assigned. Choose the same evaluator to return it to the review queue.';
                } elseif (application_status_change($database, $app_id, 'assign', $admin_email, 'Returned to evaluator after client resubmission')) {
                    $stmt_lock = $database->prepare("
                        UPDATE applications
                        SET current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_lock->bind_param("ii", $evaluator_id, $app_id);
                    $stmt_lock->execute();
                    $stmt_lock->close();

                    notify_client_for_application(
                        $database,
                        $app_id,
                        'Application Assigned',
                        'Your resubmitted application has been assigned back to an evaluator for review.'
                    );
                    notify_evaluator_for_application(
                        $database,
                        $app_id,
                        'Application Resubmitted',
                        "Application #$app_id has been resubmitted by the client and returned to your queue."
                    );
                    $success = "Application #$app_id set back to assigned for evaluation.";
                } else {
                    $error = 'Failed to update application status.';
                }
            } else {
                $stmt = $database->prepare("INSERT INTO assignments (application_id, evaluator_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("ii", $app_id, $evaluator_id);
                if ($stmt->execute()) {
                    $stmt->close();
                    $ev_stmt = $database->prepare("SELECT full_name FROM evaluators WHERE eid = ?");
                    $ev_stmt->bind_param("i", $evaluator_id);
                    $ev_stmt->execute();
                    $ev_name = $ev_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Evaluator';
                    $ev_stmt->close();
                    application_status_change($database, $app_id, 'assign', $admin_email, "Assigned to evaluator: $ev_name");

                    $stmt_lock = $database->prepare("
                        UPDATE applications
                        SET current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_lock->bind_param("ii", $evaluator_id, $app_id);
                    $stmt_lock->execute();
                    $stmt_lock->close();

                    notify_client_for_application(
                        $database,
                        $app_id,
                        'Application Assigned',
                        "Your application has been assigned to evaluator {$ev_name}."
                    );
                    notify_evaluator_for_application(
                        $database,
                        $app_id,
                        'New Application Assigned',
                        "Application #$app_id has been assigned to you for evaluation."
                    );
                    $success = "Application #$app_id assigned successfully.";
                } else {
                    $stmt->close();
                    $error = 'Assignment failed.';
                }
            }
        } elseif (!$row) {
            $error = 'Application not found.';
        } else {
            $error = 'Application must be unassign or resubmitted to assign.';
        }
    } else {
        $error = 'Invalid application or evaluator.';
    }
}

// Admin reopens a for_compliance case back to the SAME evaluator who
// originally handled it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reopen') {
    $app_id = (int) ($_POST['application_id'] ?? 0);
    if ($app_id && adminReopenToEvaluator($database, $app_id, $admin_email)) {
        notify_evaluator_for_application(
            $database,
            $app_id,
            'Application Reopened',
            "Application #$app_id has been reopened by Admin and returned to your queue."
        );
        notify_client_for_application(
            $database,
            $app_id,
            'Application Under Review Again',
            'Your application has been returned to the evaluator for continued review.'
        );
        $applicant_name = 'Applicant';
        $stmt_name = $database->prepare("
            SELECT CONCAT(c.first_name, ' ', c.last_name) as applicant_name
            FROM applications a
            LEFT JOIN clients c ON a.client_id = c.cid
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt_name->bind_param("i", $app_id);
        $stmt_name->execute();
        $name_row = $stmt_name->get_result()->fetch_assoc();
        $stmt_name->close();
        if (!empty($name_row['applicant_name'])) {
            $applicant_name = trim($name_row['applicant_name']);
        }
        $success = "Application for {$applicant_name} reopened and sent back to the original evaluator.";
    } else {
        $error = 'Could not reopen this application — it may not be in a for_compliance state, or no evaluator is on record for it.';
    }
}

// NOTE: "Evaluate Directly" is no longer handled on this page. Clicking the
// button in the table now redirects the admin to view-application.php,
// which already has the full Evaluate Directly panel (Approve/Disapprove
// with comments) wired up.

// Admin reassigns a for_compliance (or in-progress) case to a different evaluator.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign') {
    $app_id = (int) ($_POST['application_id'] ?? 0);
    $new_evaluator_id = (int) ($_POST['evaluator_id'] ?? 0);
    if ($app_id && $new_evaluator_id) {
        if (adminReassignApplication($database, $app_id, $new_evaluator_id, $admin_email)) {
            $ev_stmt = $database->prepare("SELECT full_name FROM evaluators WHERE eid = ?");
            $ev_stmt->bind_param("i", $new_evaluator_id);
            $ev_stmt->execute();
            $ev_name = $ev_stmt->get_result()->fetch_assoc()['full_name'] ?? 'Evaluator';
            $ev_stmt->close();

            notify_evaluator_for_application(
                $database,
                $app_id,
                'Application Reassigned to You',
                "Application #$app_id has been reassigned to you by Admin."
            );
            notify_client_for_application(
                $database,
                $app_id,
                'Application Reassigned',
                "Your application has been reassigned to evaluator {$ev_name}."
            );
            $success = "Application #$app_id reassigned to {$ev_name}.";
        } else {
            $error = 'Reassignment failed.';
        }
    } else {
        $error = 'Invalid application or evaluator.';
    }
}
// Fetch applications with optional status filter
$applications = [];
$sql = "
    SELECT a.id, a.status, a.submitted_at, a.updated_at,
           a.current_evaluator_id, a.handling_party, a.locked_at, a.notes,
           r.rsf_number, r.position_title, r.employer_name, r.worksite_country,
           CONCAT(c.first_name, ' ', c.last_name) as applicant_name, c.email as applicant_email,
           ev.full_name as current_evaluator_name,
           asn.reassign_count
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients c ON a.client_id = c.cid
    LEFT JOIN evaluators ev ON a.current_evaluator_id = ev.eid
    LEFT JOIN (
        SELECT application_id, COUNT(*) AS reassign_count
        FROM assignments
        GROUP BY application_id
    ) asn ON asn.application_id = a.id
    WHERE 1=1
";
$params = [];
$types = "";
$valid_single = ['pending', 'resubmitted', 'under_evaluation', 'for_compliance', 'approved', 'disapproved'];
if ($status_filter === 'unassign' || $status_filter === 'unassigned') {
    $sql .= " AND a.status IN ('unassign', 'unassigned')";
} elseif ($status_filter === 'assign' || $status_filter === 'assigned') {
    $sql .= " AND a.status IN ('assign', 'assigned')";
} elseif ($status_filter && in_array($status_filter, $valid_single, true)) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
$sql .= " ORDER BY a.updated_at DESC";

if ($params) {
    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $res = $database->query($sql);
    if ($res) $applications = $res->fetch_all(MYSQLI_ASSOC);
}

// Evaluators for dropdown
$evaluators = [];
$res = $database->query("SELECT eid, full_name, email FROM evaluators ORDER BY full_name");
if ($res) $evaluators = $res->fetch_all(MYSQLI_ASSOC);

// Stats (unassign / assign roll up legacy spellings)
$stats = ['pending' => 0, 'unassign' => 0, 'resubmitted' => 0, 'assign' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'approved' => 0, 'disapproved' => 0];
$res = $database->query("SELECT status, COUNT(*) as cnt FROM applications GROUP BY status");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $s = $r['status'];
        $c = (int) $r['cnt'];
        if ($s === 'unassigned' || $s === 'unassign') {
            $stats['unassign'] += $c;
        } elseif ($s === 'assigned' || $s === 'assign') {
            $stats['assign'] += $c;
        } elseif (isset($stats[$s])) {
            $stats[$s] += $c;
        }
    }
}

// KPI card definitions (built from the $stats roll-up above, no extra query)
$total_all = array_sum($stats);

$kpi_defs = [
    ['key' => '',                 'label' => 'All Applications', 'icon' => 'fa-list',               'color' => '#60a5fa', 'count' => $total_all],
    ['key' => 'pending',          'label' => 'Pending',          'icon' => 'fa-clock',              'color' => '#3b82f6', 'count' => $stats['pending']],
    /*['key' => 'unassign',         'label' => 'Unassign',         'icon' => 'fa-user-slash',         'color' => '#f59e0b', 'count' => $stats['unassign']],*/
    ['key' => 'resubmitted',      'label' => 'Resubmitted',      'icon' => 'fa-rotate-right',       'color' => '#f59e0b', 'count' => $stats['resubmitted']],
    ['key' => 'under_evaluation', 'label' => 'Under Evaluation', 'icon' => 'fa-search',             'color' => '#a78bfa', 'count' => $stats['under_evaluation']],
    ['key' => 'for_compliance',   'label' => 'For Compliance',   'icon' => 'fa-triangle-exclamation', 'color' => '#fb923c', 'count' => $stats['for_compliance']],
    ['key' => 'approved',         'label' => 'Approved',         'icon' => 'fa-check-circle',       'color' => '#10b981', 'count' => $stats['approved']],
    ['key' => 'disapproved',      'label' => 'Disapproved',      'icon' => 'fa-times-circle',       'color' => '#ef4444', 'count' => $stats['disapproved']],
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applications – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .font-display {
            font-family: 'DM Serif Display', serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        .kpi-card {
            text-decoration: none;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
        }

        /* Fade-in-up entrance, staggered per tile/row via inline delay */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            opacity: 0;
            animation: fadeInUp .4s ease forwards;
        }

        /* Hide scrollbar on the horizontally-scrolling table wrapper while
           keeping it scrollable (mirrors the evaluator portal pattern) */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Visual cue for stale/locked rows */
        .row-stale {
            background: linear-gradient(90deg, rgba(217, 119, 6, .04), transparent 40%);
        }
    </style>
</head>

<body class="bg-[var(--bg,#f4f6fb)]">

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn"><i class="fas fa-bell"></i><span class="notif-dot"></span></div>
            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar"><?php
                                        $parts = explode(' ', trim($admin_name));
                                        echo htmlspecialchars(count($parts) >= 2
                                            ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
                                            : strtoupper(substr($admin_name, 0, 2)));
                                        ?></div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Applications Management</h2>
                <p><?= count($applications) ?> total applications
                    <?= $status_filter ? '• ' . ucfirst(str_replace('_', ' ', $status_filter)) : '• All Applications' ?></p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- KPI FILTER CARDS -->
        <div class="flex justify-center mb-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 max-w-7xl w-full px-4">
                <?php foreach ($kpi_defs as $i => $kpi):
                    $is_active = ($kpi['key'] === '' && $status_filter === '')
                        || ($kpi['key'] !== '' && $status_filter === $kpi['key'])
                        || ($kpi['key'] === 'unassign' && in_array($status_filter, ['unassign', 'unassigned'], true));
                    $delay = .05 + ($i * .03);
                ?>
                    <a href="applications.php<?= $kpi['key'] ? '?status=' . urlencode($kpi['key']) : '' ?>"
                        class="kpi-card fade-in group relative overflow-hidden rounded-2xl <?= $is_active ? 'border-2 ring-1' : 'border' ?> bg-white p-4 flex flex-col justify-between gap-2 no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                        style="animation-delay:<?= $delay ?>s;
                       border-color:<?= $is_active ? $kpi['color'] : 'var(--border)' ?>;<?= $is_active ? '--tw-ring-color:' . $kpi['color'] . ';' : '' ?>">
                        <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-widest text-gray-500">
                            <i class="fas <?= $kpi['icon'] ?>" style="color:<?= $kpi['color'] ?>"></i> <?= $kpi['label'] ?>
                        </span>
                        <span class="text-3xl font-extrabold text-gray-900"><?= $kpi['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($error): ?>
            <div class="fade-in flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-2xl mb-6 text-sm">
                <i class="fas fa-circle-exclamation text-red-500"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success && strpos($success, 'reopened') === false): ?>
            <div class="fade-in flex items-center gap-2.5 bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-2xl mb-6 text-sm">
                <i class="fas fa-circle-check text-green-500"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="card fade-in rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden" style="animation-delay:.1s">
            <div class="card-head">
                <div class="card-title">
                    <span class="ctdot" style="background:var(--indigo)"></span>
                    <?= $status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications' ?>
                </div>
            </div>

            <div class="overflow-x-auto no-scrollbar">
                <table class="w-full min-w-[1150px]">
                    <thead>
                        <tr class="bg-[var(--surface)] border-b border-[var(--border)]">
                            <!--<th class="px-6 py-5 text-left text-xs font-bold uppercase tracking-widest text-[var(--text3)]">ID</th>-->
                            <th class="px-6 py-4 text-left text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Applicant</th>
                            <th class="px-6 py-4 text-left text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Position / RSF</th>
                            <th class="px-6 py-4 text-center text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Status</th>
                            <th class="px-6 py-4 text-left text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Handling</th>
                            <th class="px-6 py-4 text-left text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Submitted / Updated</th>
                            <th class="px-6 py-4 text-center text-[10.5px] font-bold uppercase tracking-wide text-[var(--text3)]">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)] text-sm">
                        <?php if (empty($applications)): ?>
                            <tr>
                                <td colspan="6" class="px-8 py-16 text-center text-[var(--text3)]">
                                    <i class="fas fa-inbox text-4xl text-[var(--border)] block mb-3"></i>
                                    <span class="text-sm font-bold text-[var(--text2)] block">No applications found</span>
                                    <span class="text-xs">Try a different filter above.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($applications as $i => $app): ?>
                                <?php
                                $statusClass = 'bg-gray-100 text-gray-700';
                                $statusIcon  = 'fa-circle';
                                if ($app['status'] === 'approved') {
                                    $statusClass = 'bg-emerald-100 text-emerald-700';
                                    $statusIcon  = 'fa-check-circle';
                                } elseif (in_array($app['status'], ['under_evaluation', 'assign', 'assigned'])) {
                                    $statusClass = 'bg-indigo-100 text-indigo-700';
                                    $statusIcon  = $app['status'] === 'under_evaluation' ? 'fa-search' : 'fa-user-check';
                                } elseif ($app['status'] === 'for_compliance') {
                                    $statusClass = 'bg-orange-100 text-orange-700';
                                    $statusIcon  = 'fa-triangle-exclamation';
                                } elseif ($app['status'] === 'disapproved') {
                                    $statusClass = 'bg-red-100 text-red-700';
                                    $statusIcon  = 'fa-times-circle';
                                } elseif (in_array($app['status'], ['unassign', 'unassigned', 'resubmitted'])) {
                                    $statusClass = 'bg-amber-100 text-amber-700';
                                    $statusIcon  = 'fa-rotate-right';
                                } elseif ($app['status'] === 'pending') {
                                    $statusClass = 'bg-blue-100 text-blue-700';
                                    $statusIcon  = 'fa-clock';
                                }
                                $rowDelay = .05 + (min($i, 8) * .02);
                                $isStale  = !empty($app['locked_at'])
                                    && in_array($app['status'], ['under_evaluation', 'for_compliance'], true)
                                    && ((time() - strtotime($app['locked_at'])) / 3600) > 48;
                                ?>
                                <tr class="fade-in hover:bg-[var(--surface)] transition-colors <?= $isStale ? 'row-stale' : '' ?>" style="animation-delay:<?= $rowDelay ?>s">
                                    <!--<td class="px-6 py-5 font-semibold text-[var(--navy)]">#<?= $app['id'] ?></td>-->
                                    <td class="px-6 py-5">
                                        <div class="font-medium">
                                            <a href="view-application.php?id=<?= (int) $app['id'] ?>" class="hover:underline" style="color:var(--navy);">
                                                <?= htmlspecialchars($app['applicant_name'] ?? '—') ?>
                                            </a>
                                        </div>
                                        <div class="text-xs text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                                    </td>
                                    <td class="px-6 py-5">
                                        <div><?= htmlspecialchars($app['position_title']) ?></div>
                                        <div class="text-xs text-[var(--text3)]"><?= htmlspecialchars($app['rsf_number']) ?></div>
                                        <div class="text-xs text-[var(--text3)]">
                                            <?= htmlspecialchars($app['employer_name']) ?><?= !empty($app['worksite_country']) ? ' · ' . htmlspecialchars($app['worksite_country']) : '' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-5 text-center">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10.5px] font-bold uppercase tracking-wide <?= $statusClass ?>"
                                            <?php if (!empty($app['notes'])): ?>title="<?= htmlspecialchars($app['notes'], ENT_QUOTES) ?>" <?php endif; ?>>
                                            <i class="fas <?= $statusIcon ?> text-[9px]"></i><?= htmlspecialchars(str_replace('_', ' ', ucfirst($app['status']))) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-5 text-xs">
                                        <?php if (!empty($app['current_evaluator_name'])): ?>
                                            <div class="font-semibold" style="color:var(--navy);"><?= htmlspecialchars($app['current_evaluator_name']) ?></div>
                                            <div class="text-[var(--text3)]">
                                                <?= $app['handling_party'] === 'admin' ? 'With Admin' : 'With Evaluator' ?>
                                                <?php if (!empty($app['reassign_count']) && (int) $app['reassign_count'] > 1): ?>
                                                    · reassigned <?= (int) $app['reassign_count'] - 1 ?>x
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                            if (!empty($app['locked_at']) && in_array($app['status'], ['under_evaluation', 'for_compliance'], true)) {
                                                $locked_hours = (time() - strtotime($app['locked_at'])) / 3600;
                                                if ($locked_hours > 48) {
                                                    echo '<div class="text-amber-600 font-semibold mt-0.5"><i class="fas fa-triangle-exclamation"></i> Stale since '
                                                        . date('M d', strtotime($app['locked_at'])) . '</div>';
                                                }
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span class="text-[var(--text3)]">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-5 text-xs text-[var(--text3)]">
                                        <div>Sub: <?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
                                        <div>Upd: <?= date('M d, Y', strtotime($app['updated_at'])) ?></div>
                                    </td>
                                    <td class="px-6 py-5">
                                        <?php if (in_array($app['status'], ['unassign', 'unassigned'], true) && !empty($evaluators)): ?>
                                            <form method="POST" class="flex items-center gap-3">
                                                <input type="hidden" name="action" value="assign">
                                                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                <select name="evaluator_id" required
                                                    class="border border-[var(--border)] bg-white rounded-xl px-4 py-2 text-sm focus:outline-none focus:border-[var(--blue-lt)]">
                                                    <option value="">Select evaluator...</option>
                                                    <?php foreach ($evaluators as $ev): ?>
                                                        <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit"
                                                    class="bg-[var(--blue)] hover:bg-[#1e40af] hover:-translate-y-0.5 text-white px-5 py-2 rounded-xl text-sm font-semibold transition">
                                                    Assign
                                                </button>
                                            </form>
                                        <?php elseif (in_array($app['status'], ['for_compliance', 'resubmitted'], true)): ?>
                                            <div class="flex flex-col gap-2">
                                                <?php if (!empty($app['current_evaluator_name'])): ?>
                                                    <div class="text-xs text-[var(--text3)]">
                                                        Owner on record: <span class="font-semibold text-[var(--navy)]"><?= htmlspecialchars($app['current_evaluator_name']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <?php if (!empty($app['current_evaluator_id'])): ?>
                                                        <form method="POST" id="reopenForm<?= (int) $app['id'] ?>">
                                                            <input type="hidden" name="action" value="reopen">
                                                            <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                            <button type="button"
                                                                data-app-id="<?= (int) $app['id'] ?>"
                                                                data-evaluator-name="<?= htmlspecialchars($app['current_evaluator_name'] ?? 'the evaluator', ENT_QUOTES) ?>"
                                                                onclick="confirmReopen(this)"
                                                                class="bg-emerald-600 hover:bg-emerald-700 hover:-translate-y-0.5 text-white px-4 py-2 rounded-xl text-xs font-semibold transition">
                                                                <i class="fas fa-rotate-left"></i> Reopen
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <a href="view-application.php?id=<?= (int) $app['id'] ?>"
                                                        class="bg-[var(--navy)] hover:bg-[#0b1f4b] hover:-translate-y-0.5 text-white px-4 py-2 rounded-xl text-xs font-semibold transition inline-block">
                                                        <i class="fas fa-gavel"></i> Evaluate Directly
                                                    </a>

                                                    <?php if (!empty($evaluators)): ?>
                                                        <details class="relative">
                                                            <summary class="cursor-pointer list-none bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-xl text-xs font-semibold transition inline-block">
                                                                <i class="fas fa-user-pen"></i> Reassign
                                                            </summary>
                                                            <form method="POST" class="mt-2 flex items-center gap-2">
                                                                <input type="hidden" name="action" value="reassign">
                                                                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                                <select name="evaluator_id" required
                                                                    class="border border-[var(--border)] bg-white rounded-xl px-3 py-1.5 text-xs focus:outline-none focus:border-[var(--blue-lt)]">
                                                                    <option value="">Select evaluator...</option>
                                                                    <?php foreach ($evaluators as $ev): ?>
                                                                        <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="submit"
                                                                    class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-xl text-xs font-semibold transition">
                                                                    Go
                                                                </button>
                                                            </form>
                                                        </details>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[var(--text3)] text-sm">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk active" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <?php
                // Dynamic badge count
                $badge_stmt = $database->prepare("
                SELECT COUNT(*) as total 
                FROM applications 
                WHERE status IN ('pending', 'for_compliance', 'resubmitted')
            ");
                $badge_stmt->execute();
                $badge_result = $badge_stmt->get_result()->fetch_assoc();
                $active_apps = $badge_result['total'] ?? 0;
                ?>
                <span class="dk-badge"><?= $active_apps ?></span>
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Apps</span>
                <div class="flyout active" id="appsMenu">
                    <div class="flyout-arr"></div>
                    <a href="applications.php" class="fi"><i class="fas fa-list" style="color:#60a5fa"></i>All Applications</a>
                    <a href="applications.php?status=pending" class="fi"><i class="fas fa-clock" style="color:#fbbf24"></i>Pending</a>
                    <a href="applications.php?status=unassign" class="fi"><i class="fas fa-user-slash" style="color:#f87171"></i>Unassign</a>
                    <a href="applications.php?status=assign" class="fi"><i class="fas fa-user-check" style="color:#60a5fa"></i>Assign</a>
                    <a href="applications.php?status=under_evaluation" class="fi"><i class="fas fa-search" style="color:#a78bfa"></i>Under Evaluation</a>
                    <a href="applications.php?status=for_compliance" class="fi"><i class="fas fa-triangle-exclamation" style="color:#fb923c"></i>For Compliance</a>
                    <a href="applications.php?status=approved" class="fi"><i class="fas fa-check-circle" style="color:#34d399"></i>Approved</a>
                    <a href="applications.php?status=disapproved" class="fi"><i class="fas fa-times-circle" style="color:#f87171"></i>Disapproved</a>
                </div>
            </div>
            <div class="dk-div"></div>
            <a href="user.php" class="dk" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
            </a>
            <a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>
            <a href="job-orders.php" class="dk" data-tip="Job Orders">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">RSF</span>
            </a>
            <a href="try.php" class="dk" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="analytics.php" class="dk">
                <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i>
                <span class="dk-lbl">Analytics</span>
            </a>
            <div class="dk-div"></div>
            <a href="settings.html" class="dk" data-tip="Settings">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>
            <div class="dk" data-tip="Logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <script>
        // Live date
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        // Reopen: ask for confirmation via SweetAlert before submitting.
        function confirmReopen(btn) {
            const appId = btn.dataset.appId;
            const evaluatorName = btn.dataset.evaluatorName;
            Swal.fire({
                title: 'Reopen this application?',
                text: `This will send it back to ${evaluatorName} for continued review.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, reopen it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('reopenForm' + appId).submit();
                }
                // If cancelled, Swal closes on its own and nothing else happens.
            });
        }

        <?php if ($success && strpos($success, 'reopened') !== false): ?>
            // Reopen succeeded on the server; confirm it back to the admin.
            Swal.fire({
                title: 'Reopened!',
                text: <?= json_encode($success) ?>,
                icon: 'success',
                confirmButtonColor: '#059669'
            });
        <?php endif; ?>
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>