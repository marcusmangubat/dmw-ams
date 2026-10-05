<?php

/**
 * evaluator-reports.php  –  DMW RO IV-A Evaluator Self-Service Reports
 * Printable + Excel-exportable report suite, scoped to the logged-in
 * evaluator only. Mirrors the structure/style of the admin's try.php.
 *
 * Reports:
 *   1. My Workload Summary       — everything ever assigned to me (assignments table)
 *   2. My Decision History       — every evaluation I've ever recorded
 *   3. My Approval Rate & Turnaround — approve/disapprove ratio + avg. days to decide
 */
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Resolve the logged-in evaluator's eid + name — everything below is scoped to this eid only
$stmt = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $_SESSION['user_email']);
$stmt->execute();
$evaluatorRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$evaluatorRow) {
    http_response_code(403);
    die('Evaluator profile not found for this account.');
}
$evaluator_id   = (int) $evaluatorRow['eid'];
$evaluator_name = $evaluatorRow['full_name'] ?: $_SESSION['user_email'];

// Greeting + avatar initials — same pattern used across the project's headers
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($evaluator_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);

/* ═══════════════════════════════════════════════════════════════════
   FILTER — a single optional month filter, same UX pattern as try.php
═══════════════════════════════════════════════════════════════════ */
$f_month = trim($_GET['month'] ?? '');

function whereSql(array $where): string
{
    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}

function bindDynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') return;
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function q(mysqli $db, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error . ' — SQL: ' . $sql);
    }
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
}

function fullName(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? '',
    ])));
}

function decisionBadgeClass(?string $decision): string
{
    return match ($decision) {
        'approved'      => 'b-approved',
        'disapproved'   => 'b-disapproved',
        'for_compliance' => 'b-pending',
        default          => 'b-other',
    };
}

// Base "scoped to me" filter, reused by every query below
$where  = ['asg.evaluator_id = ?'];
$types  = 'i';
$params = [$evaluator_id];
if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
    $start = $f_month . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($start));
    $where[]  = 'asg.assigned_at BETWEEN ? AND ?';
    $types   .= 'ss';
    $params[] = $start;
    $params[] = $end;
}
$whereSql = whereSql($where);

// Month options for the filter — full calendar months for every year that
// appears in this evaluator's assignment history (falls back to the current
// year if they have no assignments yet), so all months are selectable even
// if a given month currently has zero records.
$yearOptions = [];
$res = q($database, "
    SELECT DISTINCT YEAR(asg.assigned_at) AS yr
    FROM assignments asg
    WHERE asg.evaluator_id = ?
    ORDER BY yr DESC
", 'i', [$evaluator_id]);
while ($row = $res->fetch_assoc()) $yearOptions[] = (int) $row['yr'];
if (!$yearOptions) $yearOptions[] = (int) date('Y');

$monthOptions = [];
foreach ($yearOptions as $yr) {
    for ($m = 12; $m >= 1; $m--) {
        $monthOptions[] = $yr . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    }
}

/* ═══════════════════════════════════════════════════════════════════
   REPORT 1 — My Workload Summary  (source: assignments, "ever assigned")
═══════════════════════════════════════════════════════════════════ */

// 1a. Assignment status breakdown (active / completed / reassigned)
$assignmentStatusSummary = [];
$res = q($database, "
    SELECT asg.status, COUNT(*) AS cnt
    FROM assignments asg
    $whereSql
    GROUP BY asg.status
", $types, $params);
while ($row = $res->fetch_assoc()) $assignmentStatusSummary[$row['status']] = (int) $row['cnt'];

$totalEverAssigned = array_sum($assignmentStatusSummary);
$distinctApplications = 0;
$res = q($database, "
    SELECT COUNT(DISTINCT asg.application_id) AS cnt
    FROM assignments asg
    $whereSql
", $types, $params);
$distinctApplications = (int) ($res->fetch_assoc()['cnt'] ?? 0);

// 1b. Current status of every application ever assigned to me (distinct applications)
$currentStatusSummary = [];
$res = q($database, "
    SELECT a.status, COUNT(DISTINCT a.id) AS cnt
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    $whereSql
    GROUP BY a.status
    ORDER BY cnt DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $currentStatusSummary[] = $row;

// 1c. Monthly assigned volume (all history, grouped by month)
$monthlyAssigned = [];
$res = q($database, "
    SELECT DATE_FORMAT(asg.assigned_at, '%Y-%m') AS ym, COUNT(*) AS cnt
    FROM assignments asg
    $whereSql
    GROUP BY ym
    ORDER BY ym ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $monthlyAssigned[] = $row;

// 1d. Detail table — every assignment ever given to me
$assignmentDetail = [];
$res = q($database, "
    SELECT asg.id AS assignment_id, asg.status AS assignment_status, asg.assigned_at,
           a.id AS application_id, a.status AS application_status, a.submitted_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.position_title, j.worksite_country
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $whereSql
    ORDER BY asg.assigned_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $assignmentDetail[] = $row;

/* ═══════════════════════════════════════════════════════════════════
   REPORT 2 — My Decision History  (source: evaluations, every record I made)
═══════════════════════════════════════════════════════════════════ */
$evalWhere  = ['e.evaluator_id = ?'];
$evalTypes  = 'i';
$evalParams = [$evaluator_id];
if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
    $start = $f_month . '-01 00:00:00';
    $end   = date('Y-m-t 23:59:59', strtotime($start));
    $evalWhere[]  = 'e.evaluated_at BETWEEN ? AND ?';
    $evalTypes   .= 'ss';
    $evalParams[] = $start;
    $evalParams[] = $end;
}
$evalWhereSql = whereSql($evalWhere);

$decisionHistory = [];
$res = q($database, "
    SELECT e.id, e.application_id, e.evaluation_type, e.decision, e.comments, e.evaluated_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.position_title, j.worksite_country
    FROM evaluations e
    JOIN applications a ON a.id = e.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $evalWhereSql
    ORDER BY e.evaluated_at DESC
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $decisionHistory[] = $row;

$decisionTypeSummary = [];
$res = q($database, "
    SELECT e.decision, COUNT(*) AS cnt
    FROM evaluations e
    $evalWhereSql
    GROUP BY e.decision
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $decisionTypeSummary[] = $row;

/* ═══════════════════════════════════════════════════════════════════
   REPORT 3 — My Approval Rate & Turnaround
═══════════════════════════════════════════════════════════════════ */
$finalWhere  = array_merge($evalWhere, ["e.decision IN ('approved','disapproved')"]);
$finalWhereSql = whereSql($finalWhere);

$res = q($database, "
    SELECT COUNT(*) AS total,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved,
           AVG(DATEDIFF(e.evaluated_at, a.submitted_at)) AS avg_days,
           MIN(DATEDIFF(e.evaluated_at, a.submitted_at)) AS min_days,
           MAX(DATEDIFF(e.evaluated_at, a.submitted_at)) AS max_days
    FROM evaluations e
    JOIN applications a ON a.id = e.application_id
    $finalWhereSql
", $evalTypes, $evalParams);
$turnaroundStats = $res->fetch_assoc();
$finalTotal      = (int) ($turnaroundStats['total'] ?? 0);
$approvedCount   = (int) ($turnaroundStats['approved'] ?? 0);
$disapprovedCount = (int) ($turnaroundStats['disapproved'] ?? 0);
$approvalRate    = $finalTotal ? round($approvedCount / $finalTotal * 100, 1) : null;
$avgTurnaround   = $turnaroundStats['avg_days'] !== null ? round((float) $turnaroundStats['avg_days'], 1) : null;

// Bounce-back rate — how many times I sent an application back for compliance
$complianceWhere = array_merge($evalWhere, ["e.decision = 'for_compliance'"]);
$complianceWhereSql = whereSql($complianceWhere);
$res = q($database, "
    SELECT COUNT(*) AS cnt
    FROM evaluations e
    $complianceWhereSql
", $evalTypes, $evalParams);
$complianceCount = (int) ($res->fetch_assoc()['cnt'] ?? 0);

// Monthly approved vs disapproved trend
$monthlyDecisions = [];
$res = q($database, "
    SELECT DATE_FORMAT(e.evaluated_at, '%Y-%m') AS ym,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved
    FROM evaluations e
    $finalWhereSql
    GROUP BY ym
    ORDER BY ym ASC
", $evalTypes, $evalParams);
while ($row = $res->fetch_assoc()) $monthlyDecisions[] = $row;

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

/* ═══════════════════════════════════════════════════════════════════
   REPORT 4 — My Document Compliance
   Required vs. submitted documents, limited to applications ever
   assigned to me (via assignments table), deduplicated per application.
═══════════════════════════════════════════════════════════════════ */
$docCompliance = [];
$res = q($database, "
    SELECT a.id, a.rsf_id, a.submitted_at,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.employer_name,
           (SELECT COUNT(*) FROM rsf_required_documents rd
              WHERE rd.rsf_id = a.rsf_id AND rd.is_required = 1) AS required_count,
           (SELECT COUNT(DISTINCT rd2.document_type)
              FROM rsf_required_documents rd2
              JOIN application_documents ad
                ON ad.application_id = a.id AND ad.document_type = rd2.document_type
              WHERE rd2.rsf_id = a.rsf_id AND rd2.is_required = 1) AS submitted_count
    FROM assignments asg
    JOIN applications a ON a.id = asg.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $whereSql
    GROUP BY a.id
    ORDER BY a.submitted_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $docCompliance[] = $row;

$missingStmt = $database->prepare("
    SELECT rd.document_name
    FROM rsf_required_documents rd
    WHERE rd.rsf_id = ? AND rd.is_required = 1
      AND rd.document_type NOT IN (
          SELECT ad.document_type FROM application_documents ad WHERE ad.application_id = ?
      )
");
foreach ($docCompliance as &$d) {
    $required  = (int) $d['required_count'];
    $submitted = (int) $d['submitted_count'];
    $d['completion_pct'] = $required > 0 ? round(($submitted / $required) * 100) : 100;

    $rsfId = (int) $d['rsf_id'];
    $appId = (int) $d['id'];
    $missingStmt->bind_param('ii', $rsfId, $appId);
    $missingStmt->execute();
    $missingRes = $missingStmt->get_result();
    $names = [];
    while ($m = $missingRes->fetch_assoc()) $names[] = $m['document_name'];
    $d['missing_documents'] = $names ? implode(', ', $names) : '—';
}
unset($d);
$missingStmt->close();

$docCompleteCount   = count(array_filter($docCompliance, fn($d) => $d['completion_pct'] == 100));
$docIncompleteCount = count($docCompliance) - $docCompleteCount;
$docAvgCompletion   = $docCompliance ? round(array_sum(array_column($docCompliance, 'completion_pct')) / count($docCompliance)) : 0;

// Dock "Apps" badge — my currently active assignments only
$badge_stmt = $database->prepare("SELECT COUNT(*) AS total FROM assignments WHERE evaluator_id = ? AND status = 'active'");
$badge_stmt->bind_param('i', $evaluator_id);
$badge_stmt->execute();
$active_apps = (int) ($badge_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$badge_stmt->close();

$filterRecap = $f_month !== '' ? 'Month: ' . date('F Y', strtotime($f_month . '-01')) : 'All records (no filter applied)';
$generatedAt = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Reports – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <style>
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        :root {
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
            --ok: #10b981;
            --amber: #d97706;
            --indigo: #6366f1;
            --gold: #eab308;
            --red: #dc2626;
            --text1: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
            --bdr: #e2e8f0;
            --surface: #ffffff;
            --surface2: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #eef2f7;
            color: var(--text1);
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--surface);
            border-bottom: 1px solid var(--bdr);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .toolbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 14px;
        }

        .toolbar-title img {
            height: 28px;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--bdr);
            background: var(--surface2);
            color: var(--text1);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: filter .15s;
        }

        .btn-primary {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        .btn-green {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
        }

        .btn:hover {
            filter: brightness(1.08);
        }

        .tabs {
            display: flex;
            gap: 6px;
            padding: 10px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--bdr);
            overflow-x: auto;
        }

        .tab-btn {
            flex-shrink: 0;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--bdr);
            background: var(--surface2);
            color: var(--text2);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .tab-btn.active {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        .sheet {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 16px 110px;
        }

        .letterhead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }

        .letterhead-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .letterhead-left img {
            width: 44px;
        }

        .letterhead-left h1 {
            font-size: 15px;
            margin: 0;
        }

        .letterhead-left p {
            font-size: 11px;
            color: var(--text2);
            margin: 2px 0 0;
        }

        .letterhead-right {
            font-size: 11px;
            color: var(--text2);
            text-align: right;
            line-height: 1.6;
        }

        .report-section {
            display: none;
        }

        .report-section.active {
            display: block;
        }

        .report-card {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .report-card h2 {
            font-size: 15px;
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .report-card .sub {
            font-size: 11.5px;
            color: var(--text2);
            margin: 0 0 16px;
        }

        .tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .tile {
            background: var(--surface2);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 14px;
            text-align: center;
        }

        .tv {
            font-size: 22px;
            font-weight: 800;
            color: var(--blue);
        }

        .tl {
            font-size: 10.5px;
            color: var(--text2);
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .chart-wrap {
            max-width: 420px;
            margin: 0 auto 18px;
        }

        table.report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }

        table.report-table th {
            background: var(--surface2);
            text-align: left;
            padding: 8px 10px;
            font-weight: 700;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: var(--text2);
            border-bottom: 2px solid var(--bdr);
            white-space: nowrap;
        }

        table.report-table td {
            padding: 7px 10px;
            border-bottom: 1px solid var(--bdr);
            color: var(--text1);
        }

        table.report-table tbody tr:nth-child(even) {
            background: #fbfcfe;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
        }

        .b-approved {
            background: #d1fae5;
            color: #047857;
        }

        .b-disapproved {
            background: #fee2e2;
            color: #b91c1c;
        }

        .b-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .b-other {
            background: #e0e7ff;
            color: #3730a3;
        }

        .empty-row td {
            text-align: center;
            color: var(--text3);
            padding: 24px;
            font-style: italic;
        }

        .footer-note {
            font-size: 10.5px;
            color: var(--text3);
            text-align: center;
            margin-top: 18px;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .sheet {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .letterhead,
            .report-card {
                border: none;
                box-shadow: none;
                padding: 0 0 12px;
            }

            body:not(.print-all) .report-section {
                display: none;
            }

            body:not(.print-all) .report-section.active {
                display: block;
            }

            body.print-all .report-section {
                display: block !important;
                page-break-after: always;
            }

            body.print-all .report-section:last-child {
                page-break-after: auto;
            }

            table.report-table {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>

    <!-- ── TOP BAR ──────────────────────────────────────────────────── -->
    <header class="topbar no-print">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; EVALUATOR PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ── SUBBAR ────────────────────────────────────────────────────── -->
    <div class="subbar no-print">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($evaluator_name) ?></h2>
                <p>Your personal workload, decisions, and performance</p>
            </div>
        </div>
        <form class="filter-scroll" method="GET" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" id="reportFilterForm">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select name="month" id="filter-month" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Time</option>
                    <?php foreach ($monthOptions as $ym): ?>
                        <option value="<?= htmlspecialchars($ym) ?>" <?= $ym === $f_month ? 'selected' : '' ?>>
                            <?= htmlspecialchars(date('M Y', strtotime($ym . '-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="filter-pill" style="cursor:pointer;border:none">
                <i class="fas fa-filter" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Apply Filter</span>
            </button>
            <?php if ($f_month !== ''): ?>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="filter-pill" style="text-decoration:none">
                    <i class="fas fa-xmark" style="color:var(--red)"></i>
                    <span style="font-weight:600;font-size:11px">Clear</span>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── TOOLBAR ─────────────────────────────────────────────────── -->
    <div class="toolbar no-print">
        <div class="toolbar-title">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <span>DMW RO IV-A — My Reports</span>
        </div>
        <div class="toolbar-actions">
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <button class="btn" onclick="printCurrent()"><i class="fas fa-print"></i> Print This Report</button>
            <button class="btn btn-green" onclick="exportCurrent()"><i class="fas fa-file-excel"></i> Export to Excel</button>
            <button class="btn btn-green" onclick="exportCurrentPDF()"><i class="fas fa-file-pdf"></i> Export to PDF</button>
            <button class="btn btn-primary" onclick="printAll()"><i class="fas fa-print"></i> Print All Reports</button>
            <button class="btn btn-primary" onclick="exportAll()"><i class="fas fa-file-excel"></i> Export All (Excel)</button>
            <button class="btn btn-primary" onclick="exportAllPDF()"><i class="fas fa-file-pdf"></i> Export All (PDF)</button>
        </div>
    </div>

    <!-- ── TABS ────────────────────────────────────────────────────── -->
    <div class="tabs no-print" id="tabs">
        <button class="tab-btn active" data-target="rep-workload">My Workload Summary</button>
        <button class="tab-btn" data-target="rep-decisions">My Decision History</button>
        <button class="tab-btn" data-target="rep-rate">My Approval Rate &amp; Turnaround</button>
        <button class="tab-btn" data-target="rep-doccompliance">My Document Compliance</button>
    </div>

    <div class="sheet">

        <!-- Letterhead shown on every report -->
        <div class="letterhead">
            <div class="letterhead-left">
                <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW logo">
                <div>
                    <h1>Department of Migrant Workers — Regional Office IV-A</h1>
                    <p>Evaluator Self-Service Reports</p>
                </div>
            </div>
            <div class="letterhead-right">
                <div>Generated: <b><?= htmlspecialchars($generatedAt) ?></b></div>
                <div>Evaluator: <b><?= htmlspecialchars($evaluator_name) ?></b></div>
                <div>Filter: <b><?= htmlspecialchars($filterRecap) ?></b></div>
            </div>
        </div>

        <!-- ══════════════════════════ REPORT 1: MY WORKLOAD SUMMARY ══════════════════════════ -->
        <section class="report-section active" id="rep-workload">
            <div class="report-card">
                <h2><i class="fas fa-briefcase" style="color:var(--blue)"></i> My Workload Summary</h2>
                <p class="sub">Every application ever assigned to me, whether still active, completed, or reassigned to someone else.</p>

                <div class="tiles">
                    <div class="tile">
                        <div class="tv"><?= $totalEverAssigned ?></div>
                        <div class="tl">Total Assignments</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $distinctApplications ?></div>
                        <div class="tl">Distinct Applications</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $assignmentStatusSummary['active'] ?? 0 ?></div>
                        <div class="tl">Currently Active</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $assignmentStatusSummary['completed'] ?? 0 ?></div>
                        <div class="tl">Completed</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $assignmentStatusSummary['reassigned'] ?? 0 ?></div>
                        <div class="tl">Reassigned Away</div>
                    </div>
                </div>

                <div class="chart-wrap"><canvas id="chartMonthlyAssigned"></canvas></div>

                <h2 style="font-size:13px;margin-top:24px"><i class="fas fa-layer-group" style="color:var(--indigo)"></i> Current Status of My Applications</h2>
                <table class="report-table" id="tbl-current-status" style="margin-bottom:20px">
                    <thead>
                        <tr>
                            <th>Application Status</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$currentStatusSummary): ?><tr class="empty-row">
                                <td colspan="2">No applications have been assigned to you yet.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($currentStatusSummary as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['status']))) ?></td>
                                <td><?= (int) $s['cnt'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2 style="font-size:13px"><i class="fas fa-list-check" style="color:var(--amber)"></i> Assignment Detail</h2>
                <table class="report-table" id="tbl-assignment-detail">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>RSF #</th>
                            <th>Position</th>
                            <th>Country</th>
                            <th>Assignment Status</th>
                            <th>Application Status</th>
                            <th>Assigned At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$assignmentDetail): ?><tr class="empty-row">
                                <td colspan="7">No assignments found for the selected filter.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($assignmentDetail as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars(fullName($d)) ?></td>
                                <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                <td><?= htmlspecialchars($d['position_title']) ?></td>
                                <td><?= htmlspecialchars($d['worksite_country']) ?></td>
                                <td><span class="badge <?= $d['assignment_status'] === 'active' ? 'b-pending' : ($d['assignment_status'] === 'completed' ? 'b-approved' : 'b-other') ?>"><?= htmlspecialchars(ucfirst($d['assignment_status'])) ?></span></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['application_status']))) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['assigned_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 2: MY DECISION HISTORY ══════════════════════════ -->
        <section class="report-section" id="rep-decisions">
            <div class="report-card">
                <h2><i class="fas fa-clipboard-check" style="color:var(--indigo)"></i> My Decision History</h2>
                <p class="sub">Every evaluation record I have ever submitted — initial decisions and compliance reviews alike.</p>

                <div class="tiles">
                    <?php foreach ($decisionTypeSummary as $d): ?>
                        <div class="tile">
                            <div class="tv"><?= (int) $d['cnt'] ?></div>
                            <div class="tl"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? 'Unspecified'))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <table class="report-table" id="tbl-decision-history">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Position / Country</th>
                            <th>Type</th>
                            <th>Decision</th>
                            <th>Comments</th>
                            <th>Evaluated At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$decisionHistory): ?><tr class="empty-row">
                                <td colspan="6">No evaluation records found for the selected filter.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($decisionHistory as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars(fullName($d)) ?></td>
                                <td><?= htmlspecialchars($d['position_title']) ?> — <?= htmlspecialchars($d['worksite_country']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($d['evaluation_type'] ?? '')) ?></td>
                                <td><span class="badge <?= decisionBadgeClass($d['decision']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['decision'] ?? '—'))) ?></span></td>
                                <td><?= htmlspecialchars($d['comments'] ?: '—') ?></td>
                                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($d['evaluated_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 3: MY APPROVAL RATE & TURNAROUND ══════════════════════════ -->
        <section class="report-section" id="rep-rate">
            <div class="report-card">
                <h2><i class="fas fa-gauge-high" style="color:var(--ok)"></i> My Approval Rate &amp; Turnaround</h2>
                <p class="sub">Based on final decisions only (approved / disapproved). Turnaround = days between an application's submission and my final decision.</p>

                <div class="tiles">
                    <div class="tile">
                        <div class="tv"><?= $finalTotal ?></div>
                        <div class="tl">Final Decisions Made</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $approvedCount ?></div>
                        <div class="tl">Approved</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $disapprovedCount ?></div>
                        <div class="tl">Disapproved</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $approvalRate !== null ? $approvalRate . '%' : '—' ?></div>
                        <div class="tl">Approval Rate</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $avgTurnaround !== null ? $avgTurnaround : '—' ?></div>
                        <div class="tl">Avg. Turnaround (days)</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $complianceCount ?></div>
                        <div class="tl">Sent Back For Compliance</div>
                    </div>
                </div>

                <div class="chart-wrap"><canvas id="chartApprovalRate"></canvas></div>

                <h2 style="font-size:13px;margin-top:24px"><i class="fas fa-chart-line" style="color:var(--blue-lt)"></i> Monthly Decisions</h2>
                <table class="report-table" id="tbl-monthly-decisions">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Approved</th>
                            <th>Disapproved</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$monthlyDecisions): ?><tr class="empty-row">
                                <td colspan="4">No final decisions found for the selected filter.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($monthlyDecisions as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M Y', strtotime($m['ym'] . '-01'))) ?></td>
                                <td><?= (int) $m['approved'] ?></td>
                                <td><?= (int) $m['disapproved'] ?></td>
                                <td><?= (int) $m['approved'] + (int) $m['disapproved'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="sub" style="margin-top:10px">
                    Fastest decision: <b><?= $turnaroundStats['min_days'] ?? '—' ?> day(s)</b> &nbsp;•&nbsp;
                    Slowest decision: <b><?= $turnaroundStats['max_days'] ?? '—' ?> day(s)</b>
                </p>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 4: MY DOCUMENT COMPLIANCE ══════════════════════════ -->
        <section class="report-section" id="rep-doccompliance">
            <div class="report-card">
                <h2><i class="fas fa-file-circle-check" style="color:var(--ok)"></i> My Document Compliance</h2>
                <p class="sub">Required vs. submitted documents for applications that have been assigned to me.</p>

                <div class="tiles">
                    <div class="tile">
                        <div class="tv"><?= count($docCompliance) ?></div>
                        <div class="tl">Applications Checked</div>
                    </div>
                    <div class="tile">
                        <div class="tv" style="color:var(--ok)"><?= $docCompleteCount ?></div>
                        <div class="tl">Fully Compliant</div>
                    </div>
                    <div class="tile">
                        <div class="tv" style="color:var(--red)"><?= $docIncompleteCount ?></div>
                        <div class="tl">Incomplete</div>
                    </div>
                    <div class="tile">
                        <div class="tv"><?= $docAvgCompletion ?>%</div>
                        <div class="tl">Avg. Completion</div>
                    </div>
                </div>

                <table class="report-table" id="tbl-doccompliance">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>RSF #</th>
                            <th>Employer</th>
                            <th>Required</th>
                            <th>Submitted</th>
                            <th>Completion</th>
                            <th>Missing Documents</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$docCompliance): ?><tr class="empty-row">
                                <td colspan="7">No applications have been assigned to you yet for the selected filter.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($docCompliance as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars(fullName($d)) ?></td>
                                <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                <td><?= htmlspecialchars($d['employer_name']) ?></td>
                                <td><?= (int) $d['required_count'] ?></td>
                                <td><?= (int) $d['submitted_count'] ?></td>
                                <td><span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int) $d['completion_pct'] ?>%</span></td>
                                <td><?= htmlspecialchars($d['missing_documents']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="footer-note">DMW Regional Office IV-A — CALABARZON &nbsp;•&nbsp; Generated automatically from your Evaluator Portal &nbsp;•&nbsp; For official use only</p>
    </div>

    <!-- ── JAVASCRIPT ────────────────────────────────────────────────── -->
    <script>
        const REPORT_TITLES = {
            'rep-workload': 'My Workload Summary',
            'rep-decisions': 'My Decision History',
            'rep-rate': 'My Approval Rate and Turnaround',
            'rep-doccompliance': 'My Document Compliance'
        };

        /* ── Tab switching ───────────────────────────────────────── */
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.report-section').forEach(s => s.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.target).classList.add('active');
            });
        });

        function activeSectionId() {
            return document.querySelector('.report-section.active').id;
        }

        /* ── Print ───────────────────────────────────────────────── */
        function printCurrent() {
            window.print();
        }

        function printAll() {
            document.body.classList.add('print-all');
            window.print();
        }

        window.addEventListener('afterprint', () => {
            document.body.classList.remove('print-all');
        });

        /* ── Excel export (SheetJS) ──────────────────────────────── */
        function tablesInSection(sectionId) {
            return Array.from(document.querySelectorAll('#' + sectionId + ' table.report-table'));
        }

        function safeSheetName(name) {
            return name.replace(/[\\\/\?\*\[\]:]/g, ' ').substring(0, 31);
        }

        function exportCurrent() {
            const id = activeSectionId();
            const tables = tablesInSection(id);
            if (!tables.length) return;

            const wb = XLSX.utils.book_new();
            tables.forEach((tbl, i) => {
                const ws = XLSX.utils.table_to_sheet(tbl);
                const name = safeSheetName(tables.length > 1 ? `${REPORT_TITLES[id]} ${i + 1}` : REPORT_TITLES[id]);
                XLSX.utils.book_append_sheet(wb, ws, name || `Sheet${i + 1}`);
            });
            XLSX.writeFile(wb, `${EVALUATOR_FILE_SLUG}_${REPORT_TITLES[id].replace(/\s+/g, '_')}.xlsx`);
        }

        function exportAll() {
            const wb = XLSX.utils.book_new();
            document.querySelectorAll('.report-section').forEach(section => {
                const tables = tablesInSection(section.id);
                tables.forEach((tbl, i) => {
                    const ws = XLSX.utils.table_to_sheet(tbl);
                    const baseName = REPORT_TITLES[section.id] || section.id;
                    const name = safeSheetName(tables.length > 1 ? `${baseName} ${i + 1}` : baseName);
                    XLSX.utils.book_append_sheet(wb, ws, name || section.id);
                });
            });
            XLSX.writeFile(wb, `${EVALUATOR_FILE_SLUG}_Evaluator_Reports.xlsx`);
        }

        /* ── Filename helper — evaluator name, filesystem-safe ───── */
        const EVALUATOR_FILE_SLUG = <?= json_encode($evaluator_name) ?>
            .replace(/[\\\/:*?"<>|]/g, '') // strip characters illegal in filenames
            .trim()
            .replace(/\s+/g, '_');

        /* ── PDF export (jsPDF + AutoTable) ──────────────────────── */
        const PDF_META = {
            evaluator: <?= json_encode($evaluator_name) ?>,
            generatedAt: <?= json_encode($generatedAt) ?>,
            filter: <?= json_encode($filterRecap) ?>
        };

        function pdfAddLetterhead(doc, title) {
            doc.setFontSize(13);
            doc.setFont(undefined, 'bold');
            doc.text('Department of Migrant Workers — Regional Office IV-A', 40, 40);
            doc.setFontSize(10);
            doc.setFont(undefined, 'normal');
            doc.text(title, 40, 58);
            doc.setFontSize(9);
            doc.setTextColor(100);
            doc.text(`Evaluator: ${PDF_META.evaluator}`, 40, 76);
            doc.text(`Generated: ${PDF_META.generatedAt}`, 40, 90);
            doc.text(`Filter: ${PDF_META.filter}`, 40, 104);
            doc.setTextColor(0);
            return 128; // next Y position
        }

        function pdfAddTiles(doc, sectionId, startY) {
            const tiles = document.querySelectorAll('#' + sectionId + ' .tile');
            if (!tiles.length) return startY;

            let x = 40,
                y = startY,
                col = 0;
            const colWidth = 150,
                rowHeight = 34;

            tiles.forEach(t => {
                const val = t.querySelector('.tv')?.textContent?.trim() ?? '';
                const label = t.querySelector('.tl')?.textContent?.trim() ?? '';

                doc.setFontSize(11);
                doc.setFont(undefined, 'bold');
                doc.text(val, x, y);

                doc.setFontSize(7);
                doc.setFont(undefined, 'normal');
                doc.setTextColor(100);
                doc.text(label, x, y + 12, {
                    maxWidth: colWidth - 10
                });
                doc.setTextColor(0);

                col++;
                x += colWidth;
                if (col % 6 === 0) {
                    x = 40;
                    y += rowHeight;
                }
            });

            if (col % 6 !== 0) y += rowHeight;
            return y + 14;
        }

        function pdfAddTables(doc, sectionId, startY) {
            const tables = tablesInSection(sectionId);
            let y = startY;

            tables.forEach(tbl => {
                doc.autoTable({
                    html: tbl,
                    startY: y,
                    styles: {
                        fontSize: 7,
                        cellPadding: 3,
                        overflow: 'linebreak'
                    },
                    headStyles: {
                        fillColor: [29, 78, 216],
                        textColor: 255
                    },
                    alternateRowStyles: {
                        fillColor: [248, 250, 252]
                    },
                    margin: {
                        left: 40,
                        right: 40
                    },
                    theme: 'grid'
                });
                y = doc.lastAutoTable.finalY + 24;
            });

            return y;
        }

        function exportCurrentPDF() {
            const id = activeSectionId();
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'pt',
                format: 'a4'
            });

            let y = pdfAddLetterhead(doc, REPORT_TITLES[id]);
            y = pdfAddTiles(doc, id, y);
            pdfAddTables(doc, id, y);

            doc.save(`${EVALUATOR_FILE_SLUG}_${REPORT_TITLES[id].replace(/\s+/g, '_')}.pdf`);
        }

        function exportAllPDF() {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'pt',
                format: 'a4'
            });

            document.querySelectorAll('.report-section').forEach((section, i) => {
                if (i > 0) doc.addPage();
                let y = pdfAddLetterhead(doc, REPORT_TITLES[section.id] || section.id);
                y = pdfAddTiles(doc, section.id, y);
                pdfAddTables(doc, section.id, y);
            });

            doc.save(`${EVALUATOR_FILE_SLUG}_Evaluator_Reports.pdf`);
        }

        /* ── Charts ──────────────────────────────────────────────── */

        /* ── Charts ──────────────────────────────────────────────── */
        const monthlyLabels = <?= json_encode(array_map(fn($r) => date('M Y', strtotime($r['ym'] . '-01')), $monthlyAssigned)) ?>;
        const monthlyData = <?= json_encode(array_map('intval', array_column($monthlyAssigned, 'cnt'))) ?>;
        new Chart(document.getElementById('chartMonthlyAssigned'), {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Assignments Received',
                    data: monthlyData,
                    backgroundColor: 'rgba(29,78,216,.15)',
                    borderColor: '#1d4ed8',
                    borderWidth: 2,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('chartApprovalRate'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Disapproved'],
                datasets: [{
                    data: [<?= $approvedCount ?>, <?= $disapprovedCount ?>],
                    backgroundColor: ['#059669', '#dc2626']
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        /* ── Live date + time ────────────────────────────────────────────── */
        function updateLiveDateTime() {
            const el = document.getElementById('live-date');
            if (!el) return;

            const now = new Date();

            const datePart = now.toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

            const timePart = now.toLocaleTimeString('en-PH', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            }).toLowerCase();

            el.textContent = `${datePart} | ${timePart}`;
        }

        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000); // refresh every second

        /* ── Filter form: submit on change, and guard the Apply click ── */
        document.getElementById('filter-month')?.addEventListener('change', () => {
            document.getElementById('reportFilterForm').submit();
        });

        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        });
    </script>

    <!--FLOATING DOCK  (exact dashboard.php structure -->
    <div class="dock-wrap no-print">
        <nav class="dock">

            <a href="index.php" class="dk">
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

            <a href="reports.php" class="dk active">
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

    <script type="module" src="../js/main.js"></script>
    <?php include '../includes/login_toast.php'; ?>
</body>

</html>