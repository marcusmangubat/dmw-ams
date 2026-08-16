<?php

/**
 * dashboard-api.php
 * DMW RO IV-A – Admin Dashboard Backend
 *
 * Drop this file next to dashboard.php (inside your admin/ folder).
 * dashboard.php calls it via fetch() with ?action=<name>
 *
 * Actions: stats | monthly | status | job_orders | destinations | report | notifications
 */

session_start();
require_once '../db.php'; // gives us $database (mysqli)

// Auth guard — same pattern as applications.php
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

/* ═══════════════════════════════════════════════════════════════════
   FILTER HELPER
   Reads ?month=YYYY-MM & ?province= & ?job_type= & ?country= from the
   querystring and turns them into reusable JOIN + WHERE + bind-param
   fragments. Every report query below threads through this so the
   subbar filter pills actually narrow the data.
   ─ month     → applications.submitted_at within that month
   ─ province  → clients.province        (needs JOIN clients)
   ─ job_type  → rsf_job_orders.position_title  (needs JOIN rsf_job_orders)
   ─ country   → rsf_job_orders.worksite_country (needs JOIN rsf_job_orders)
═══════════════════════════════════════════════════════════════════ */
function buildFilters(string $appAlias = 'a'): array
{
    $month    = trim($_GET['month'] ?? '');
    $province = trim($_GET['province'] ?? '');
    $jobType  = trim($_GET['job_type'] ?? '');
    $country  = trim($_GET['country'] ?? '');

    $joins  = [];
    $where  = [];
    $types  = '';
    $params = [];

    $needsClient = $province !== '';
    $needsJob    = $jobType !== '' || $country !== '';

    if ($needsClient) {
        $joins[] = "JOIN clients fc ON fc.cid = {$appAlias}.client_id";
    }
    if ($needsJob) {
        $joins[] = "JOIN rsf_job_orders fj ON fj.id = {$appAlias}.rsf_id";
    }

    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($start));
        $where[]  = "{$appAlias}.submitted_at BETWEEN ? AND ?";
        $types   .= 'ss';
        $params[] = $start;
        $params[] = $end;
    }
    if ($province !== '') {
        $where[]  = "fc.province = ?";
        $types   .= 's';
        $params[] = $province;
    }
    if ($jobType !== '') {
        $where[]  = "fj.position_title = ?";
        $types   .= 's';
        $params[] = $jobType;
    }
    if ($country !== '') {
        $where[]  = "fj.worksite_country = ?";
        $types   .= 's';
        $params[] = $country;
    }

    return [
        'join'   => $joins ? (' ' . implode(' ', $joins) . ' ') : '',
        'where'  => $where ? (' AND ' . implode(' AND ', $where)) : '',
        'types'  => $types,
        'params' => $params,
        'month'  => $month, // raw month value, some queries need it directly
    ];
}

/* Bind a dynamic list of params onto a prepared statement */
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

try {
    switch ($action) {
        case 'stats':
            echo json_encode(getStats($database, buildFilters()));
            break;
        case 'monthly':
            echo json_encode(getMonthly($database, buildFilters()));
            break;
        case 'status':
            echo json_encode(getStatusBreakdown($database, buildFilters()));
            break;
        case 'job_orders':
            echo json_encode(getJobOrders($database, buildFilters('a')));
            break;
        case 'destinations':
            echo json_encode(getDestinations($database, buildFilters()));
            break;
        case 'report':
            echo json_encode(getReportRows($database, buildFilters()));
            break;
        case 'job_types':
            echo json_encode(getJobTypes($database));
            break;
        case 'notifications':
            echo json_encode(getNotifications($database, $_SESSION['user_email']));
            break;
        case 'mark_read':
            markAllRead($database, $_SESSION['user_email']);
            echo json_encode(['ok' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/* 
   1. KPI STATS
   ─ Total apps (all time)
   ─ Pending / Under Evaluation / Approved counts
   ─ Month-over-month % change
   ─ Average evaluation days */
function getStats(mysqli $db, array $f): array
{
    // Total applications (filtered)
    $sql  = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE 1=1 {$f['where']}";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    // Counts grouped by status (filtered)
    $sql  = "SELECT a.status, COUNT(*) AS cnt FROM applications a {$f['join']} WHERE 1=1 {$f['where']} GROUP BY a.status";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $s = $row['status'];
        $c = (int) $row['cnt'];
        // Roll up legacy spellings (unassign / unassigned, assign / assigned)
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + $c;
    }
    $stmt->close();

    $pending   = $counts['pending']          ?? 0;
    $underEval = $counts['under_evaluation'] ?? 0;
    $approved  = $counts['approved']         ?? 0;
    $unassign  = $counts['unassign']         ?? 0;

    // Month-over-month: use the selected month filter as "this month" if
    // present, otherwise fall back to the real current month.
    if ($f['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
        $thisStart = $f['month'] . '-01 00:00:00';
    } else {
        $thisStart = date('Y-m-01 00:00:00');
    }
    $thisEnd   = date('Y-m-t 23:59:59', strtotime($thisStart));
    $prevStart = date('Y-m-01 00:00:00', strtotime($thisStart . ' -1 month'));
    $prevEnd   = date('Y-m-t 23:59:59',  strtotime($thisStart . ' -1 month'));

    $sql  = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE a.submitted_at BETWEEN ? AND ? {$f['where']}";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'ss' . $f['types'], array_merge([$thisStart, $thisEnd], $f['params']));
    $stmt->execute();
    $thisMo = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'ss' . $f['types'], array_merge([$prevStart, $prevEnd], $f['params']));
    $stmt->execute();
    $prevMo = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $changePct = $prevMo > 0
        ? round((($thisMo - $prevMo) / $prevMo) * 100, 1)
        : null;

    // Approved this month vs last (filtered)
    $sql  = "SELECT COUNT(*) FROM applications a {$f['join']} WHERE a.status = 'approved' AND a.updated_at BETWEEN ? AND ? {$f['where']}";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'ss' . $f['types'], array_merge([$thisStart, $thisEnd], $f['params']));
    $stmt->execute();
    $thisApproved = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'ss' . $f['types'], array_merge([$prevStart, $prevEnd], $f['params']));
    $stmt->execute();
    $prevApproved = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $approvedChangePct = $prevApproved > 0
        ? round((($thisApproved - $prevApproved) / $prevApproved) * 100, 1)
        : null;

    // Average evaluation days (submitted → evaluated), filtered
    $sql = "SELECT AVG(DATEDIFF(e.evaluated_at, a.submitted_at)) AS avg_days
            FROM evaluations e
            JOIN applications a ON a.id = e.application_id
            {$f['join']}
            WHERE e.decision IS NOT NULL {$f['where']}";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $avgRow  = $stmt->get_result()->fetch_row();
    $avgDays = $avgRow && $avgRow[0] !== null ? round((float) $avgRow[0], 1) : null;
    $stmt->close();

    return [
        'total'            => $total,
        'pending'          => $pending,
        'under_evaluation' => $underEval,
        'approved'         => $approved,
        'unassign'         => $unassign,
        'change_pct'       => $changePct,
        'approved_change'  => $approvedChangePct,
        'avg_eval_days'    => $avgDays,
    ];
}

/* ═══════════════════════════════════════════════════════════════════
   2. MONTHLY BAR CHART
   ─ Applications submitted per month (current year)
   ─ Approved per month (current year)
═══════════════════════════════════════════════════════════════════ */
function getMonthly(mysqli $db, array $f): array
{
    $year      = (int) date('Y');
    $submitted = array_fill(1, 12, 0);
    $approved  = array_fill(1, 12, 0);

    $sql = "SELECT MONTH(a.submitted_at) AS mo, COUNT(*) AS cnt
            FROM applications a {$f['join']}
            WHERE YEAR(a.submitted_at) = ? {$f['where']}
            GROUP BY mo";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'i' . $f['types'], array_merge([$year], $f['params']));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $submitted[(int) $row['mo']] = (int) $row['cnt'];
    }
    $stmt->close();

    $sql = "SELECT MONTH(a.updated_at) AS mo, COUNT(*) AS cnt
            FROM applications a {$f['join']}
            WHERE a.status = 'approved' AND YEAR(a.updated_at) = ? {$f['where']}
            GROUP BY mo";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, 'i' . $f['types'], array_merge([$year], $f['params']));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $approved[(int) $row['mo']] = (int) $row['cnt'];
    }
    $stmt->close();

    return [
        'labels'    => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'submitted' => array_values($submitted),
        'approved'  => array_values($approved),
    ];
}

/* ═══════════════════════════════════════════════════════════════════
   3. STATUS BREAKDOWN BARS
═══════════════════════════════════════════════════════════════════ */
function getStatusBreakdown(mysqli $db, array $f): array
{
    $order = [
        'pending'          => ['label' => 'Pending',       'color' => '#f59e0b'],
        'unassign'         => ['label' => 'Unassigned',    'color' => '#ef4444'],
        'assign'           => ['label' => 'Assigned',      'color' => '#3b82f6'],
        'under_evaluation' => ['label' => 'Under Eval.',   'color' => '#6366f1'],
        'for_compliance'   => ['label' => 'Compliance',    'color' => '#f97316'],
        'resubmitted'      => ['label' => 'Resubmitted',   'color' => '#8b5cf6'],
        'approved'         => ['label' => 'Approved',      'color' => '#10b981'],
        'disapproved'      => ['label' => 'Disapproved',   'color' => '#dc2626'],
    ];

    $sql  = "SELECT a.status, COUNT(*) AS cnt FROM applications a {$f['join']} WHERE 1=1 {$f['where']} GROUP BY a.status";
    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $s = $row['status'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + (int) $row['cnt'];
    }
    $stmt->close();

    $total  = array_sum($counts) ?: 1;
    $result = [];

    foreach ($order as $key => $meta) {
        $c        = $counts[$key] ?? 0;
        $result[] = [
            'status' => $key,
            'label'  => $meta['label'],
            'color'  => $meta['color'],
            'count'  => $c,
            'pct'    => round($c / $total * 100, 1),
        ];
    }

    return $result;
}

/* ═══════════════════════════════════════════════════════════════════
   4. ACTIVE JOB ORDERS (deadline not yet passed)
═══════════════════════════════════════════════════════════════════ */
function getJobOrders(mysqli $db, array $f): array
{
    // Pull job_type/country straight from the querystring — these filters
    // describe the job posting itself, not the applicant, so they apply
    // directly to rsf_job_orders rather than through the applications join.
    $jobType = trim($_GET['job_type'] ?? '');
    $country = trim($_GET['country'] ?? '');

    $where  = ['j.deadline >= CURDATE()'];
    $types  = '';
    $params = [];

    if ($jobType !== '') {
        $where[]  = 'j.position_title = ?';
        $types   .= 's';
        $params[] = $jobType;
    }
    if ($country !== '') {
        $where[]  = 'j.worksite_country = ?';
        $types   .= 's';
        $params[] = $country;
    }

    $sql = "SELECT j.id, j.rsf_number, j.position_title, j.employer_name,
                   j.worksite_country, j.slots_available, j.deadline,
                   COUNT(a.id) AS applied
            FROM rsf_job_orders j
            LEFT JOIN applications a ON a.rsf_id = j.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY j.id
            ORDER BY j.deadline ASC
            LIMIT 5";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();

    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $slots   = (int) $row['slots_available'];
        $applied = (int) $row['applied'];
        $pct     = $slots > 0 ? min(100, round($applied / $slots * 100)) : 0;

        $jobs[] = [
            'id'         => $row['id'],
            'rsf_number' => $row['rsf_number'],
            'title'      => $row['position_title'],
            'employer'   => $row['employer_name'],
            'country'    => $row['worksite_country'],
            'slots'      => $slots,
            'applied'    => $applied,
            'fill_pct'   => $pct,
            'deadline'   => $row['deadline'],
            'days_left'  => (int) ceil((strtotime($row['deadline']) - time()) / 86400),
        ];
    }
    $stmt->close();

    return $jobs;
}

/* ═══════════════════════════════════════════════════════════════════
   5. DESTINATION COUNTRIES DOUGHNUT
═══════════════════════════════════════════════════════════════════ */
function getDestinations(mysqli $db, array $f): array
{
    $sql = "SELECT j.worksite_country AS country, COUNT(a.id) AS cnt
            FROM applications a
            JOIN rsf_job_orders j ON j.id = a.rsf_id
            {$f['join']}
            WHERE 1=1 {$f['where']}
            GROUP BY j.worksite_country
            ORDER BY cnt DESC";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $labels = [];
    $data   = [];
    $palette = ['#1d4ed8', '#059669', '#d97706', '#6366f1', '#dc2626', '#94a3b8'];

    while ($row = $res->fetch_assoc()) {
        $labels[] = $row['country'];
        $data[]   = (int) $row['cnt'];
    }
    $stmt->close();

    $colors = array_slice($palette, 0, count($labels));

    return ['labels' => $labels, 'data' => $data, 'colors' => $colors];
}

/* ═══════════════════════════════════════════════════════════════════
   6b. REPORT ROWS — the actual filtered application records, used by
   report.php to render a printable / PDF-exportable table. Applies
   the exact same month / province / job_type / country filters as
   the rest of the dashboard, so "print" always matches what the
   admin currently has selected in the subbar.
═══════════════════════════════════════════════════════════════════ */
function getReportRows(mysqli $db, array $f): array
{
    // Always need clients (applicant name) and rsf_job_orders (job/country)
    // for the report regardless of whether they're used as filters, so we
    // don't rely on buildFilters()'s conditional joins here.
    $sql = "SELECT
                a.id,
                a.status,
                a.submitted_at,
                a.updated_at,
                c.first_name,
                c.middle_name,
                c.last_name,
                c.suffix,
                c.province,
                c.city_municipality,
                j.rsf_number,
                j.position_title,
                j.employer_name,
                j.worksite_country
            FROM applications a
            JOIN clients c ON c.cid = a.client_id
            JOIN rsf_job_orders j ON j.id = a.rsf_id
            {$f['join']}
            WHERE 1=1 {$f['where']}
            ORDER BY a.submitted_at DESC";

    $stmt = $db->prepare($sql);
    bindDynamic($stmt, $f['types'], $f['params']);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim(implode(' ', array_filter([
            $row['first_name'],
            $row['middle_name'],
            $row['last_name'],
            $row['suffix'],
        ])));

        $rows[] = [
            'id'              => (int) $row['id'],
            'applicant'       => $name,
            'province'        => $row['province'],
            'city'            => $row['city_municipality'],
            'rsf_number'      => $row['rsf_number'],
            'position_title'  => $row['position_title'],
            'employer_name'   => $row['employer_name'],
            'country'         => $row['worksite_country'],
            'status'          => $row['status'],
            'submitted_at'    => $row['submitted_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }
    $stmt->close();

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'filters'      => [
            'month'    => trim($_GET['month'] ?? ''),
            'province' => trim($_GET['province'] ?? ''),
            'job_type' => trim($_GET['job_type'] ?? ''),
            'country'  => trim($_GET['country'] ?? ''),
        ],
        'count' => count($rows),
        'rows'  => $rows,
    ];
}

/* ═══════════════════════════════════════════════════════════════════
   7. DISTINCT JOB TYPES (for the "All Job Types" filter pill)
═══════════════════════════════════════════════════════════════════ */
function getJobTypes(mysqli $db): array
{
    $res = $db->query(
        "SELECT DISTINCT position_title FROM rsf_job_orders
         WHERE position_title IS NOT NULL AND position_title <> ''
         ORDER BY position_title ASC"
    );
    $types = [];
    while ($row = $res->fetch_assoc()) {
        $types[] = $row['position_title'];
    }
    return $types;
}

/* ═══════════════════════════════════════════════════════════════════
   6. ADMIN NOTIFICATIONS
═══════════════════════════════════════════════════════════════════ */
function getNotifications(mysqli $db, string $admin_email): array
{
    $stmt = $db->prepare(
        "SELECT id, title, message, type, is_read, created_at, application_id
         FROM notifications
         WHERE user_email = ? AND usertype = 'admin'
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $items  = [];
    $unread = 0;

    while ($row = $res->fetch_assoc()) {
        if (!(int) $row['is_read']) $unread++;
        $items[] = [
            'id'             => (int) $row['id'],
            'title'          => $row['title'],
            'message'        => $row['message'],
            'type'           => $row['type'],
            'is_read'        => (bool) (int) $row['is_read'],
            'application_id' => $row['application_id'],
            'time_ago'       => timeAgo($row['created_at']),
        ];
    }

    return ['unread' => $unread, 'items' => $items];
}

function markAllRead(mysqli $db, string $admin_email): void
{
    $stmt = $db->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_email = ? AND usertype = 'admin' AND is_read = 0"
    );
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $stmt->close();
}

/* ─── Helper ─────────────────────────────────────────────────────── */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60)   . 'm ago';
    if ($diff < 86400) return floor($diff / 3600)  . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
