<?php

/**
 * report.php  –  DMW RO IV-A Admin Reports
 * Printable + Excel-exportable report suite.
 * Opened from dashboard.php's "Print / Export PDF" button, carrying
 * across whatever month / province / job_type / country filters the
 * admin currently has selected on the dashboard subbar.
 */
session_start();
require_once '../db.php'; // gives us $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

// Admin name (who generated the report) — same lookup pattern as dashboard.php
$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// Greeting + avatar initials — same logic as dashboard.php, needed by the shared header/subheader
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);

/* 
   FILTERS — read straight from the querystring, same four filters as
   the dashboard subbar (month / province / job_type / country).
 */
$f_month    = trim($_GET['month'] ?? '');
$f_province = trim($_GET['province'] ?? '');
$f_jobtype  = trim($_GET['job_type'] ?? '');
$f_country  = trim($_GET['country'] ?? '');

function buildWhere(): array
{
    global $f_month, $f_province, $f_jobtype, $f_country;
    $where  = [];
    $types  = '';
    $params = [];

    if ($f_month !== '' && preg_match('/^\d{4}-\d{2}$/', $f_month)) {
        $start = $f_month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where[]  = 'a.submitted_at BETWEEN ? AND ?';
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($f_province !== '') {
        $where[]  = 'c.province = ?';
        $types   .= 's';
        $params[] = $f_province;
    }
    if ($f_jobtype !== '') {
        $where[]  = 'j.position_title = ?';
        $types   .= 's';
        $params[] = $f_jobtype;
    }
    if ($f_country !== '') {
        $where[]  = 'j.worksite_country = ?';
        $types   .= 's';
        $params[] = $f_country;
    }
    return [$where, $types, $params];
}

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

function statusLabel(string $status): string
{
    $s = $status;
    if ($s === 'unassigned') $s = 'unassign';
    if ($s === 'assigned')   $s = 'assign';
    $labels = [
        'pending'          => 'Pending',
        'unassign'         => 'Unassigned',
        'assign'           => 'Assigned',
        'under_evaluation' => 'Under Evaluation',
        'for_compliance'   => 'For Compliance',
        'resubmitted'      => 'Resubmitted',
        'approved'         => 'Approved',
        'disapproved'      => 'Disapproved',
    ];
    return $labels[$s] ?? ucwords(str_replace('_', ' ', $s));
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

// Option lists for the subheader's Job Type / Destination filter pills
// (reuses rsf_job_orders, the same source dashboard-api.php draws from)
$jobTypeOptions = [];
$res = $database->query("SELECT DISTINCT position_title FROM rsf_job_orders WHERE position_title IS NOT NULL AND position_title <> '' ORDER BY position_title");
while ($row = $res->fetch_assoc()) $jobTypeOptions[] = $row['position_title'];

$countryOptions = [];
$res = $database->query("SELECT DISTINCT worksite_country FROM rsf_job_orders WHERE worksite_country IS NOT NULL AND worksite_country <> '' ORDER BY worksite_country");
while ($row = $res->fetch_assoc()) $countryOptions[] = $row['worksite_country'];

// Dock "Apps" badge — same query as dashboard.php's floating dock
$badge_stmt = $database->prepare("
    SELECT COUNT(*) as total
    FROM applications
    WHERE status IN ('pending', 'for_compliance', 'resubmitted')
");
$badge_stmt->execute();
$active_apps = $badge_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$badge_stmt->close();

[$where, $types, $params] = buildWhere();
$whereSql = whereSql($where);
$baseFrom = "FROM applications a
             JOIN clients c ON c.cid = a.client_id
             JOIN rsf_job_orders j ON j.id = a.rsf_id";

/* 
   REPORT 1 — Applicants by Gender
 */
$genderSummary = [];
$res = q($database, "
    SELECT COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved,
           SUM(a.status = 'disapproved') AS disapproved,
           SUM(a.status NOT IN ('approved','disapproved')) AS in_process
    $baseFrom
    $whereSql
    GROUP BY gender
    ORDER BY gender
", $types, $params);
while ($row = $res->fetch_assoc()) $genderSummary[] = $row;

$genderDetail = [];
$res = q($database, "
    SELECT a.id, c.first_name, c.middle_name, c.last_name, c.suffix,
           COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           c.province, c.city_municipality,
           j.worksite_country AS country, j.position_title,
           a.status, a.submitted_at
    $baseFrom
    $whereSql
    ORDER BY gender ASC, c.last_name ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $genderDetail[] = $row;

/* 
   REPORT 2 — Approved Applicants by Country
 */
$whereApproved = whereSql(array_merge($where, ["a.status = 'approved'"]));

$countrySummary = [];
$res = q($database, "
    SELECT j.worksite_country AS country, COUNT(*) AS approved_count
    $baseFrom
    $whereApproved
    GROUP BY country
    ORDER BY approved_count DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $countrySummary[] = $row;

$countryDetail = [];
$res = q($database, "
    SELECT a.id, c.first_name, c.middle_name, c.last_name, c.suffix,
           COALESCE(NULLIF(TRIM(c.gender), ''), 'Not Specified') AS gender,
           c.province, c.city_municipality,
           j.worksite_country AS country, j.position_title, j.employer_name,
           a.updated_at AS approved_at
    $baseFrom
    $whereApproved
    ORDER BY country ASC, c.last_name ASC
", $types, $params);
while ($row = $res->fetch_assoc()) $countryDetail[] = $row;

/* 
   REPORT 3 — Application Status Summary
 */
$statusCounts = [];
$res = q($database, "
    SELECT a.status, COUNT(*) AS cnt
    $baseFrom
    $whereSql
    GROUP BY a.status
", $types, $params);
while ($row = $res->fetch_assoc()) {
    $s = $row['status'];
    if ($s === 'unassigned') $s = 'unassign';
    if ($s === 'assigned')   $s = 'assign';
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + (int) $row['cnt'];
}
$statusOrder = ['pending', 'unassign', 'assign', 'under_evaluation', 'for_compliance', 'resubmitted', 'approved', 'disapproved'];
$statusTotal = array_sum($statusCounts) ?: 1;
$statusSummary = [];
foreach ($statusOrder as $s) {
    $c = $statusCounts[$s] ?? 0;
    $statusSummary[] = [
        'status' => statusLabel($s),
        'count'  => $c,
        'pct'    => round($c / $statusTotal * 100, 1),
    ];
}

/* 
   REPORT 4 — Applicants by Province / City of Origin
 */
$provinceSummary = [];
$res = q($database, "
    SELECT c.province, c.city_municipality,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved
    $baseFrom
    $whereSql
    GROUP BY c.province, c.city_municipality
    ORDER BY applications DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $provinceSummary[] = $row;

/* 
   REPORT 5 — Applicants by Age Group
 */
$ageSummary = [];
$res = q($database, "
    SELECT
        CASE
            WHEN c.dob IS NULL THEN 'Not Indicated'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) < 18 THEN 'Under 18'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 18 AND 24 THEN '18–24'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 25 AND 34 THEN '25–34'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 35 AND 44 THEN '35–44'
            WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 45 AND 54 THEN '45–54'
            ELSE '55 and above'
        END AS age_group,
        COUNT(DISTINCT c.cid) AS applicants,
        COUNT(a.id) AS applications
    $baseFrom
    $whereSql
    GROUP BY age_group
    ORDER BY FIELD(age_group, 'Under 18','18–24','25–34','35–44','45–54','55 and above','Not Indicated')
", $types, $params);
while ($row = $res->fetch_assoc()) $ageSummary[] = $row;

/* 
   REPORT 6 — Applicants by Educational Attainment
 */
$eduSummary = [];
$res = q($database, "
    SELECT COALESCE(cp.educational_attainment, 'Not Indicated') AS education,
           COUNT(DISTINCT c.cid) AS applicants,
           COUNT(a.id) AS applications,
           SUM(a.status = 'approved') AS approved
    $baseFrom
    LEFT JOIN client_profiles cp ON cp.client_id = c.cid
    $whereSql
    GROUP BY education
    ORDER BY applications DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $eduSummary[] = $row;

/* 
   REPORT 7 — Job Order Slot Utilization
 */
$jobWhere  = [];
$jobTypes  = '';
$jobParams = [];
if ($f_jobtype !== '') {
    $jobWhere[] = 'j.position_title = ?';
    $jobTypes .= 's';
    $jobParams[] = $f_jobtype;
}
if ($f_country !== '') {
    $jobWhere[] = 'j.worksite_country = ?';
    $jobTypes .= 's';
    $jobParams[] = $f_country;
}
$jobWhereSql = whereSql($jobWhere);

$jobSummary = [];
$res = q($database, "
    SELECT j.rsf_number, j.position_title, j.employer_name, j.worksite_country,
           j.slots_available, j.deadline,
           COUNT(a.id) AS applied,
           SUM(a.status = 'approved') AS approved,
           GREATEST(j.slots_available - COALESCE(SUM(a.status = 'approved'), 0), 0) AS remaining
    FROM rsf_job_orders j
    LEFT JOIN applications a ON a.rsf_id = j.id
    $jobWhereSql
    GROUP BY j.id
    ORDER BY j.deadline ASC
", $jobTypes, $jobParams);
while ($row = $res->fetch_assoc()) $jobSummary[] = $row;

/* 
   REPORT 8 — Evaluator Turnaround / Workload
 */
$evalWhere  = array_merge($where, ["e.decision IS NOT NULL"]);
$evalWhereSql = whereSql($evalWhere);

$evalSummary = [];
$res = q($database, "
    SELECT ev.full_name, ev.email,
           COUNT(e.id) AS actions,
           SUM(e.decision = 'approved') AS approved,
           SUM(e.decision = 'disapproved') AS disapproved,
           SUM(e.decision = 'for_compliance') AS for_compliance,
           AVG(CASE WHEN e.decision IN ('approved','disapproved')
                    THEN DATEDIFF(e.evaluated_at, a.submitted_at) END) AS avg_days
    FROM evaluations e
    JOIN evaluators ev ON ev.eid = e.evaluator_id
    JOIN applications a ON a.id = e.application_id
    JOIN clients c ON c.cid = a.client_id
    JOIN rsf_job_orders j ON j.id = a.rsf_id
    $evalWhereSql
    GROUP BY ev.eid
    ORDER BY actions DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $evalSummary[] = $row;

/* 
   REPORT 9 — Document Compliance
   Required vs. submitted documents per application, based on each
   job order's rsf_required_documents list (is_required = 1) matched
   against application_documents.document_type.
 */
$docCompliance = [];
$res = q($database, "
    SELECT a.id, a.rsf_id,
           c.first_name, c.middle_name, c.last_name, c.suffix,
           j.rsf_number, j.employer_name,
           (SELECT COUNT(*) FROM rsf_required_documents rd
              WHERE rd.rsf_id = a.rsf_id AND rd.is_required = 1) AS required_count,
           (SELECT COUNT(DISTINCT rd2.document_type)
              FROM rsf_required_documents rd2
              JOIN application_documents ad
                ON ad.application_id = a.id AND ad.document_type = rd2.document_type
              WHERE rd2.rsf_id = a.rsf_id AND rd2.is_required = 1) AS submitted_count
    $baseFrom
    $whereSql
    ORDER BY a.submitted_at DESC
", $types, $params);
while ($row = $res->fetch_assoc()) $docCompliance[] = $row;

// Attach completion % + missing document names per application.
// Reuses one prepared statement across the loop instead of re-preparing each time.
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

// Human-readable filter recap for the letterhead
$filterBits = [];
if ($f_month !== '')    $filterBits[] = 'Month: ' . date('F Y', strtotime($f_month . '-01'));
if ($f_province !== '') $filterBits[] = 'Province: ' . $f_province;
if ($f_jobtype !== '')  $filterBits[] = 'Job Type: ' . $f_jobtype;
if ($f_country !== '')  $filterBits[] = 'Destination: ' . $f_country;
$filterRecap = $filterBits ? implode('  •  ', $filterBits) : 'All records (no filters applied)';
$generatedAt = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Reports – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        /* Same topbar-brand override used in dashboard.php */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }
    </style>

    <style>
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

        .btn-red {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
        }

        .btn-red:hover {
            filter: brightness(1.08);
        }

        .btn-red:disabled {
            opacity: .6;
            cursor: not-allowed;
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

        /* ── Toolbar (screen only) ───────────────────────────────── */
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
            width: 28px;
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
            background: var(--surface);
            color: var(--text1);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }

        .btn:hover {
            background: var(--surface2);
        }

        .btn-primary {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--blue-lt);
        }

        .btn-green {
            background: var(--ok);
            border-color: var(--ok);
            color: #fff;
        }

        .btn-green:hover {
            filter: brightness(1.08);
        }

        /* ── Tabs (screen only) ─────────────────────────────────── */
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

        /* ── Report canvas ──────────────────────────────────────── */
        .sheet {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 16px 110px;
        }

        .letterhead {
            background: var(--surface);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .letterhead-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .letterhead-left img {
            height: 46px;
        }

        .letterhead h1 {
            font-size: 16px;
            margin: 0 0 2px;
            font-family: 'DM Serif Display', serif;
        }

        .letterhead p {
            margin: 0;
            font-size: 11px;
            color: var(--text2);
        }

        .letterhead-right {
            text-align: right;
            font-size: 11px;
            color: var(--text2);
        }

        .letterhead-right b {
            color: var(--text1);
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
            border-radius: 12px;
            padding: 20px 24px;
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
            font-size: 11px;
            color: var(--text2);
            margin: 0 0 16px;
        }

        .tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .tile {
            background: var(--surface2);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 12px 14px;
        }

        .tile .tv {
            font-size: 22px;
            font-weight: 800;
        }

        .tile .tl {
            font-size: 10.5px;
            color: var(--text2);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .chart-wrap {
            max-width: 360px;
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

        /* ── Print rules ─────────────────────────────────────────── */
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

    <!-- ── TOP BAR (copied from dashboard.php) ─────────────────────── -->
    <header class="topbar no-print">
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
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ── SUBBAR (copied from dashboard.php, wired to try.php's own filters) ── -->
    <div class="subbar no-print">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?></h2>
                <p>Filter the report below, then use the toolbar to print or export</p>
            </div>
        </div>
        <form class="filter-scroll" method="GET" action="try.php">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select name="month" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Months</option>
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        $sel = ($val === $f_month) ? 'selected' : '';
                        echo "<option value=\"$val\" $sel>$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select name="province" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">CALABARZON</option>
                    <?php foreach (['Laguna', 'Batangas', 'Cavite', 'Rizal', 'Quezon'] as $prov): ?>
                        <option value="<?= htmlspecialchars($prov) ?>" <?= $prov === $f_province ? 'selected' : '' ?>><?= htmlspecialchars($prov) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select name="job_type" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Job Types</option>
                    <?php foreach ($jobTypeOptions as $jt): ?>
                        <option value="<?= htmlspecialchars($jt) ?>" <?= $jt === $f_jobtype ? 'selected' : '' ?>><?= htmlspecialchars($jt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select name="country" class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option value="">All Destinations</option>
                    <?php foreach ($countryOptions as $ctry): ?>
                        <option value="<?= htmlspecialchars($ctry) ?>" <?= $ctry === $f_country ? 'selected' : '' ?>><?= htmlspecialchars($ctry) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="filter-pill" style="cursor:pointer;border:none">
                <i class="fas fa-filter" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Apply Filters</span>
            </button>
        </form>
    </div>

    <!-- ── TOOLBAR ─────────────────────────────────────────────────── -->
    <div class="toolbar no-print">
        <div class="toolbar-title">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <span>DMW RO IV-A — Admin Reports</span>
        </div>
        <div class="toolbar-actions">
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <button class="btn" onclick="printCurrent()"><i class="fas fa-print"></i> Print This Report</button>
            <button class="btn btn-green" onclick="exportCurrent()"><i class="fas fa-file-excel"></i> Export to Excel</button>
            <button class="btn btn-red" onclick="downloadCurrentPDF(this)"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <button class="btn btn-primary" onclick="printAll()"><i class="fas fa-print"></i> Print All Reports</button>
            <button class="btn btn-primary" onclick="exportAll()"><i class="fas fa-file-excel"></i> Export All (Excel)</button>
            <button class="btn btn-red" onclick="downloadAllPDF(this)"><i class="fas fa-file-pdf"></i> Download All (PDF)</button>
        </div>
    </div>

    <!-- ── TABS ────────────────────────────────────────────────────── -->
    <div class="tabs no-print" id="tabs">
        <!--<button class="tab-btn active" data-target="rep-gender">Gender</button>-->
        <button class="tab-btn" data-target="rep-country">Approved by Country</button>
        <button class="tab-btn" data-target="rep-status">Status Summary</button>
        <button class="tab-btn" data-target="rep-province">Origin (Province/City)</button>
        <button class="tab-btn" data-target="rep-age">Age Group</button>
        <button class="tab-btn" data-target="rep-edu">Educational Attainment</button>
        <button class="tab-btn" data-target="rep-jobs">Job Order Utilization</button>
        <button class="tab-btn" data-target="rep-evaluators">Evaluator Turnaround</button>
        <button class="tab-btn" data-target="rep-doccompliance">Document Compliance</button>
    </div>

    <div class="sheet">

        <!-- Letterhead shown on every report -->
        <div class="letterhead">
            <div class="letterhead-left">
                <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW logo">
                <div>
                    <h1>Department of Migrant Workers — Regional Office IV-A</h1>
                    <p>Migrant Worker Application Reports</p>
                </div>
            </div>
            <div class="letterhead-right">
                <div>Generated: <b><?= htmlspecialchars($generatedAt) ?></b></div>
                <div>By: <b><?= htmlspecialchars($admin_name) ?></b></div>
                <div>Filters: <b><?= htmlspecialchars($filterRecap) ?></b></div>
            </div>
        </div>

        <!-- ══════════════════════════ REPORT 1: GENDER ══════════════════════════ 
        <section class="report-section active" id="rep-gender">
            <div class="report-card">
                <h2><i class="fas fa-venus-mars" style="color:var(--indigo)"></i> Applicant Reports by Gender</h2>
                <p class="sub">Total number of male and female applicants, with approval breakdown.</p>

                <div class="tiles">
                    <?php foreach ($genderSummary as $g): ?>
                        <div class="tile">
                            <div class="tv"><?= (int)$g['applicants'] ?></div>
                            <div class="tl"><?= htmlspecialchars($g['gender']) ?> Applicants</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="chart-wrap"><canvas id="chartGender"></canvas></div>

                <table class="report-table" id="tbl-gender-summary">
                    <thead>
                        <tr>
                            <th>Gender</th>
                            <th>Applicants</th>
                            <th>Applications</th>
                            <th>Approved</th>
                            <th>Disapproved</th>
                            <th>In Process</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$genderSummary): ?><tr class="empty-row">
                                <td colspan="6">No records found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($genderSummary as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['gender']) ?></td>
                                <td><?= (int)$g['applicants'] ?></td>
                                <td><?= (int)$g['applications'] ?></td>
                                <td><?= (int)$g['approved'] ?></td>
                                <td><?= (int)$g['disapproved'] ?></td>
                                <td><?= (int)$g['in_process'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-card">
                <h2 style="font-size:13px">Applicant Detail (by Gender)</h2>
                <table class="report-table" id="tbl-gender-detail">
                    <thead>
                        <tr>
                            <th>App #</th>
                            <th>Gender</th>
                            <th>Province</th>
                            <th>City</th>
                            <th>Destination</th>
                            <th>Position Applied</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$genderDetail): ?><tr class="empty-row">
                                <td colspan="8">No records found.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($genderDetail as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= htmlspecialchars($r['gender']) ?></td>
                                <td><?= htmlspecialchars($r['province']) ?></td>
                                <td><?= htmlspecialchars($r['city_municipality']) ?></td>
                                <td><?= htmlspecialchars($r['country']) ?></td>
                                <td><?= htmlspecialchars($r['position_title']) ?></td>
                                <?php
                                if ($r['status'] === 'disapproved') $badgeClass = 'disapproved';
                                elseif ($r['status'] === 'approved') $badgeClass = 'approved';
                                elseif ($r['status'] === 'pending') $badgeClass = 'pending';
                                else $badgeClass = 'other';
                                ?>
                                <td><span class="badge b-<?= $badgeClass ?>"><?= htmlspecialchars(statusLabel($r['status'])) ?></span></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($r['submitted_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>-->

        <!-- ══════════════════════════ REPORT 2: APPROVED BY COUNTRY ══════════════════════════ -->
        <section class="report-section" id="rep-country">
            <div class="report-card">
                <h2><i class="fas fa-globe" style="color:var(--amber)"></i> Approved Applicants by Country</h2>
                <p class="sub">Approved applications grouped by worksite/destination country.</p>

                <div class="chart-wrap"><canvas id="chartCountry"></canvas></div>

                <table class="report-table" id="tbl-country-summary">
                    <thead>
                        <tr>
                            <th>Destination Country</th>
                            <th>Approved Applicants</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$countrySummary): ?><tr class="empty-row">
                                <td colspan="2">No approved applications found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($countrySummary as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['country']) ?></td>
                                <td><?= (int)$c['approved_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-card">
                <h2 style="font-size:13px">Approved Applicant Detail</h2>
                <table class="report-table" id="tbl-country-detail">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Gender</th>
                            <th>Origin</th>
                            <th>Country</th>
                            <th>Position</th>
                            <th>Employer</th>
                            <th>Approved On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$countryDetail): ?><tr class="empty-row">
                                <td colspan="8">No records found.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($countryDetail as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars(fullName($r)) ?></td>
                                <td><?= htmlspecialchars($r['gender']) ?></td>
                                <td><?= htmlspecialchars($r['city_municipality'] . ', ' . $r['province']) ?></td>
                                <td><?= htmlspecialchars($r['country']) ?></td>
                                <td><?= htmlspecialchars($r['position_title']) ?></td>
                                <td><?= htmlspecialchars($r['employer_name']) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 3: STATUS SUMMARY ══════════════════════════ -->
        <section class="report-section" id="rep-status">
            <div class="report-card">
                <h2><i class="fas fa-chart-pie" style="color:var(--blue-lt)"></i> Application Status Summary</h2>
                <p class="sub">Overall processing funnel — useful for management reporting and pipeline monitoring.</p>

                <table class="report-table" id="tbl-status">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusSummary as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['status']) ?></td>
                                <td><?= (int)$s['count'] ?></td>
                                <td><?= $s['pct'] ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 4: PROVINCE / ORIGIN ══════════════════════════ -->
        <section class="report-section" id="rep-province">
            <div class="report-card">
                <h2><i class="fas fa-map-location-dot" style="color:var(--ok)"></i> Applicants by Province &amp; City of Origin</h2>
                <p class="sub">Where applicants are coming from within the region — useful for outreach and LGU coordination.</p>

                <table class="report-table" id="tbl-province">
                    <thead>
                        <tr>
                            <th>Province</th>
                            <th>City / Municipality</th>
                            <th>Applicants</th>
                            <th>Applications</th>
                            <th>Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$provinceSummary): ?><tr class="empty-row">
                                <td colspan="5">No records found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($provinceSummary as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['province']) ?></td>
                                <td><?= htmlspecialchars($p['city_municipality']) ?></td>
                                <td><?= (int)$p['applicants'] ?></td>
                                <td><?= (int)$p['applications'] ?></td>
                                <td><?= (int)$p['approved'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 5: AGE GROUP ══════════════════════════ -->
        <section class="report-section" id="rep-age">
            <div class="report-card">
                <h2><i class="fas fa-cake-candles" style="color:var(--gold)"></i> Applicants by Age Group</h2>
                <p class="sub">Demographic breakdown — helps check compliance with job-order age qualifications.</p>

                <table class="report-table" id="tbl-age">
                    <thead>
                        <tr>
                            <th>Age Group</th>
                            <th>Applicants</th>
                            <th>Applications</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$ageSummary): ?><tr class="empty-row">
                                <td colspan="3">No records found.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($ageSummary as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['age_group']) ?></td>
                                <td><?= (int)$a['applicants'] ?></td>
                                <td><?= (int)$a['applications'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 6: EDUCATION ══════════════════════════ -->
        <section class="report-section" id="rep-edu">
            <div class="report-card">
                <h2><i class="fas fa-graduation-cap" style="color:var(--indigo)"></i> Applicants by Educational Attainment</h2>
                <p class="sub">Useful for matching applicant qualifications against job order requirements.</p>

                <table class="report-table" id="tbl-edu">
                    <thead>
                        <tr>
                            <th>Educational Attainment</th>
                            <th>Applicants</th>
                            <th>Applications</th>
                            <th>Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$eduSummary): ?><tr class="empty-row">
                                <td colspan="4">No records found.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($eduSummary as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['education']) ?></td>
                                <td><?= (int)$e['applicants'] ?></td>
                                <td><?= (int)$e['applications'] ?></td>
                                <td><?= (int)$e['approved'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 7: JOB ORDER UTILIZATION ══════════════════════════ -->
        <section class="report-section" id="rep-jobs">
            <div class="report-card">
                <h2><i class="fas fa-briefcase" style="color:var(--amber)"></i> Job Order Slot Utilization</h2>
                <p class="sub">Tracks recruitment progress per RSF job order — applied vs. approved vs. remaining slots.</p>

                <table class="report-table" id="tbl-jobs">
                    <thead>
                        <tr>
                            <th>RSF #</th>
                            <th>Position</th>
                            <th>Employer</th>
                            <th>Country</th>
                            <th>Slots</th>
                            <th>Applied</th>
                            <th>Approved</th>
                            <th>Remaining</th>
                            <th>Deadline</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$jobSummary): ?><tr class="empty-row">
                                <td colspan="9">No job orders found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($jobSummary as $j): ?>
                            <tr>
                                <td><?= htmlspecialchars($j['rsf_number']) ?></td>
                                <td><?= htmlspecialchars($j['position_title']) ?></td>
                                <td><?= htmlspecialchars($j['employer_name']) ?></td>
                                <td><?= htmlspecialchars($j['worksite_country']) ?></td>
                                <td><?= (int)$j['slots_available'] ?></td>
                                <td><?= (int)$j['applied'] ?></td>
                                <td><?= (int)$j['approved'] ?></td>
                                <td><?= (int)$j['remaining'] ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($j['deadline']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 8: EVALUATOR TURNAROUND ══════════════════════════ -->
        <section class="report-section" id="rep-evaluators">
            <div class="report-card">
                <h2><i class="fas fa-user-clock" style="color:var(--red)"></i> Evaluator Turnaround &amp; Workload</h2>
                <p class="sub">Average number of days from submission to final decision, per evaluator — useful for performance monitoring.</p>

                <table class="report-table" id="tbl-evaluators">
                    <thead>
                        <tr>
                            <th>Evaluator</th>
                            <th>Email</th>
                            <th>Total Actions</th>
                            <th>Approved</th>
                            <th>Disapproved</th>
                            <th>For Compliance</th>
                            <th>Avg. Turnaround (days)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$evalSummary): ?><tr class="empty-row">
                                <td colspan="7">No evaluation records found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($evalSummary as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['full_name'] ?? $e['email']) ?></td>
                                <td><?= htmlspecialchars($e['email']) ?></td>
                                <td><?= (int)$e['actions'] ?></td>
                                <td><?= (int)$e['approved'] ?></td>
                                <td><?= (int)$e['disapproved'] ?></td>
                                <td><?= (int)$e['for_compliance'] ?></td>
                                <td><?= $e['avg_days'] !== null ? round((float)$e['avg_days'], 1) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ══════════════════════════ REPORT 9: DOCUMENT COMPLIANCE ══════════════════════════ -->
        <section class="report-section" id="rep-doccompliance">
            <div class="report-card">
                <h2><i class="fas fa-file-circle-check" style="color:var(--ok)"></i> Document Compliance Report</h2>
                <p class="sub">Required vs. submitted documents per application, based on each job order's document requirements.</p>

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
                                <td colspan="7">No applications found for the selected filters.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($docCompliance as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars(fullName($d)) ?></td>
                                <td><?= htmlspecialchars($d['rsf_number']) ?></td>
                                <td><?= htmlspecialchars($d['employer_name']) ?></td>
                                <td><?= (int)$d['required_count'] ?></td>
                                <td><?= (int)$d['submitted_count'] ?></td>
                                <td><span class="badge <?= $d['completion_pct'] == 100 ? 'b-approved' : 'b-disapproved' ?>"><?= (int)$d['completion_pct'] ?>%</span></td>
                                <td><?= htmlspecialchars($d['missing_documents']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="footer-note">DMW Regional Office IV-A — CALABARZON &nbsp;•&nbsp; Generated automatically from the Admin Dashboard &nbsp;•&nbsp; For official use only</p>
    </div>

    <!-- ── JAVASCRIPT ────────────────────────────────────────────────── -->
    <script>
        const REPORT_TITLES = {
            'rep-gender': 'Applicants by Gender',
            'rep-country': 'Approved Applicants by Country',
            'rep-status': 'Application Status Summary',
            'rep-province': 'Applicants by Province-City',
            'rep-age': 'Applicants by Age Group',
            'rep-edu': 'Applicants by Educational Attainment',
            'rep-jobs': 'Job Order Slot Utilization',
            'rep-evaluators': 'Evaluator Turnaround',
            'rep-doccompliance': 'Document Compliance Report'
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
            XLSX.writeFile(wb, `${REPORT_TITLES[id].replace(/\s+/g, '_')}.xlsx`);
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
            XLSX.writeFile(wb, 'DMW_All_Reports.xlsx');
        }

        /* ── PDF Download (html2canvas + jsPDF) ─────────────────── */
        async function generatePdfFromSections(sections, filename) {
            const {
                jsPDF
            } = window.jspdf;
            const pdf = new jsPDF('p', 'pt', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const margin = 24;
            const usableWidth = pageWidth - margin * 2;
            const usableHeight = pageHeight - margin * 2;
            const letterhead = document.querySelector('.letterhead');

            let firstPage = true;

            for (const section of sections) {
                const wasActive = section.classList.contains('active');
                if (!wasActive) section.classList.add('active');

                // Build an offscreen copy: letterhead + this report section
                const wrapper = document.createElement('div');
                wrapper.style.cssText = 'position:fixed;left:-99999px;top:0;background:#fff;';
                wrapper.style.width = document.querySelector('.sheet').clientWidth + 'px';
                wrapper.appendChild(letterhead.cloneNode(true));
                wrapper.appendChild(section.cloneNode(true));
                document.body.appendChild(wrapper);

                const canvas = await html2canvas(wrapper, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                });
                document.body.removeChild(wrapper);
                if (!wasActive) section.classList.remove('active');

                const imgData = canvas.toDataURL('image/png');
                const imgHeight = (canvas.height * usableWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                if (!firstPage) pdf.addPage();
                firstPage = false;

                pdf.addImage(imgData, 'PNG', margin, margin - position, usableWidth, imgHeight);
                heightLeft -= usableHeight;

                while (heightLeft > 0) {
                    position += usableHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', margin, margin - position, usableWidth, imgHeight);
                    heightLeft -= usableHeight;
                }
            }

            pdf.save(filename);
        }

        async function downloadCurrentPDF(btn) {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                const id = activeSectionId();
                await generatePdfFromSections([document.getElementById(id)], `${REPORT_TITLES[id].replace(/\s+/g,'_')}.pdf`);
            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }

        async function downloadAllPDF(btn) {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                const sections = Array.from(document.querySelectorAll('.report-section'));
                await generatePdfFromSections(sections, 'DMW_All_Reports.pdf');
            } catch (err) {
                console.error(err);
                alert('Failed to generate PDF. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }
        /* ── Charts ──────────────────────────────────────────────── */
        const genderLabels = <?= json_encode(array_column($genderSummary, 'gender')) ?>;
        const genderData = <?= json_encode(array_map('intval', array_column($genderSummary, 'applicants'))) ?>;

        const genderColorMap = {
            'Male': '#3b82f6', // blue
            'Female': '#ec4899', // pink
            'Not Specified': '#94a3b8'
        };
        const genderColors = genderLabels.map(g => genderColorMap[g] || '#f59e0b'); // fallback for any other value

        new Chart(document.getElementById('chartGender'), {
            type: 'doughnut',
            data: {
                labels: genderLabels,
                datasets: [{
                    data: genderData,
                    backgroundColor: genderColors
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

        const countryLabels = <?= json_encode(array_column($countrySummary, 'country')) ?>;
        const countryData = <?= json_encode(array_map('intval', array_column($countrySummary, 'approved_count'))) ?>;
        new Chart(document.getElementById('chartCountry'), {
            type: 'bar',
            data: {
                labels: countryLabels,
                datasets: [{
                    label: 'Approved Applicants',
                    data: countryData,
                    backgroundColor: '#1d4ed8',
                    borderRadius: 6
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

        /* Wraps a native <select> with a compact styled trigger + dropdown
           panel. The panel is appended to <body> and positioned `fixed`
           (from the trigger's on-screen position) so it never stretches
           .filter-scroll's horizontal scrollbar. The original <select>
           stays in the DOM (hidden) with its `name` attribute intact, so
           the surrounding <form method="GET" action="try.php"> still
           submits exactly the same querystring as before — no other
           changes needed. */
        function enhancePillSelect(select) {
            if (!select) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'relative inline-flex items-center';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.classList.add('hidden');
            select.tabIndex = -1;

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.className = 'inline-flex items-center gap-1.5 bg-transparent border-none cursor-pointer p-0 m-0 font-inherit text-inherit';
            trigger.innerHTML = '<span class="truncate max-w-[110px]"></span>' +
                '<i class="fas fa-chevron-down text-[9px] opacity-60 transition-transform flex-shrink-0"></i>';
            wrapper.appendChild(trigger);

            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            panel.style.cssText = 'position:fixed;background:var(--surface);border:1.5px solid var(--bdr);border-radius:8px;box-shadow:0 10px 30px rgba(11,31,75,.16);z-index:999;overflow:hidden;padding:4px 0;max-height:240px;overflow-y:auto;width:224px;';
            panel.classList.add('hidden');
            document.body.appendChild(panel);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 12px;font-size:12px;color:var(--text1);cursor:pointer;';
                row.dataset.value = optionEl.value;
                row.addEventListener('mouseenter', () => row.style.background = 'var(--surface2)');
                row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? '#eff6ff' : '');
                const label = document.createElement('span');
                label.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                label.textContent = optionEl.textContent;
                row.appendChild(label);
                row.addEventListener('click', () => {
                    select.value = optionEl.value;
                    closePanel();
                    refresh();
                    select.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                });
                return row;
            }

            function renderList() {
                panel.innerHTML = '';
                Array.from(select.options).forEach(opt => panel.appendChild(buildRow(opt)));
                markSelected();
            }

            function markSelected() {
                panel.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.dataset.selected = selected ? '1' : '0';
                    row.style.background = selected ? '#eff6ff' : '';
                    row.style.color = selected ? 'var(--blue)' : 'var(--text1)';
                    row.style.fontWeight = selected ? '700' : '400';
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check';
                        icon.style.cssText = 'font-size:10px;color:var(--blue-lt);flex-shrink:0;';
                        row.appendChild(icon);
                    } else if (!selected && check) {
                        check.remove();
                    }
                });
            }

            function refresh() {
                const opt = select.options[select.selectedIndex];
                trigger.querySelector('span').textContent = opt ? opt.textContent : '';
                markSelected();
            }

            function positionPanel() {
                const rect = trigger.getBoundingClientRect();
                const panelWidth = 224;
                let left = rect.left;
                const maxLeft = window.innerWidth - panelWidth - 8;
                if (left > maxLeft) left = Math.max(8, maxLeft);
                panel.style.top = (rect.bottom + 6) + 'px';
                panel.style.left = left + 'px';
            }

            function onReposition() {
                if (!panel.classList.contains('hidden')) positionPanel();
            }

            function openPanel() {
                document.querySelectorAll('[role="listbox"]').forEach(p => {
                    if (p !== panel) p.classList.add('hidden');
                });
                renderList();
                positionPanel();
                panel.classList.remove('hidden');
                trigger.setAttribute('aria-expanded', 'true');
                trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
                window.addEventListener('scroll', onReposition, true);
                window.addEventListener('resize', onReposition);
            }

            function closePanel() {
                panel.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
                window.removeEventListener('scroll', onReposition, true);
                window.removeEventListener('resize', onReposition);
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.contains('hidden') ? openPanel() : closePanel();
            });
            trigger.addEventListener('keydown', (e) => {
                if (['Enter', ' ', 'ArrowDown'].includes(e.key)) {
                    e.preventDefault();
                    openPanel();
                }
            });
            panel.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closePanel();
                    trigger.focus();
                }
            });
            panel.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', closePanel);

            refresh();
        }

        // Every <select> inside the filter form gets the same treatment —
        // month, province, job_type, country (identified by name, not id,
        // in this file, so we just grab all of them).
        document.querySelectorAll('.filter-scroll select').forEach(enhancePillSelect);

        /* ── Dock flyout (copied from dashboard.php) ────────────────── */
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
    </script>

    <!-- ── FLOATING DOCK (copied from dashboard.php) ────────────────── -->
    <div class="dock-wrap no-print">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <span class="dk-badge"><?= (int) $active_apps ?></span>
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Apps</span>
                <div class="flyout" id="appsMenu">
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
            <a href="job-orders.php" class="dk" data-tip="Job Orders">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">RSF</span>
            </a>
            <a href="try.php" class="dk active" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="analytics.php" class="dk">
                <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i>
                <span class="dk-lbl">Analytics</span>
            </a>
            <a href="user.php" class="dk" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
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

    <script type="module" src="../js/main.js"></script>
</body>

</html>