<?php
// Admin Analytics API
session_start();
require_once '../db.php'; // provides $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();
header('Content-Type: application/json');

$action      = $_GET['action'] ?? '';
$monthParam  = $_GET['month'] ?? 'all';

// Validate: must be 'all' or strictly YYYY-MM
if ($monthParam !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = 'all';
}

[$rangeStart, $rangeEnd] = getDateRange($monthParam);

try {
    switch ($action) {
        case 'summary':
            echo json_encode(getSummary($database, $rangeStart, $rangeEnd));
            break;
        case 'stages':
            echo json_encode(getStages($database, $rangeStart, $rangeEnd));
            break;
        case 'avg_time':
            echo json_encode(getAvgTime($database, $rangeStart, $rangeEnd));
            break;
        case 'overdue':
            echo json_encode(getOverdue($database));
            break;
        case 'weekly':
            echo json_encode(getWeekly($database, $monthParam, $rangeStart, $rangeEnd));
            break;
        case 'services':
            echo json_encode(getServices($database, $rangeStart, $rangeEnd));
            break;
        case 'svc_monthly':
            echo json_encode(getSvcMonthly($database, $rangeStart, $rangeEnd));
            break;
        case 'destinations':
            echo json_encode(getDestinations($database, $rangeStart, $rangeEnd));
            break;
        case 'evaluators':
            echo json_encode(getEvaluators($database, $rangeStart, $rangeEnd));
            break;
        case 'job_orders':
            echo json_encode(getJobOrders($database, $rangeStart, $rangeEnd));
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/* ── DATE RANGE HELPER ───────────────────────────────────────────────
   'all'      -> Jan 1 .. Dec 31 of the current year
   'YYYY-MM'  -> first day .. last day of that month                 */
function getDateRange(string $month): array
{
    $year = date('Y');

    if ($month === 'all') {
        return ["$year-01-01 00:00:00", "$year-12-31 23:59:59"];
    }

    $ts = strtotime($month . '-01');
    if ($ts === false) {
        return ["$year-01-01 00:00:00", "$year-12-31 23:59:59"];
    }

    return [date('Y-m-01 00:00:00', $ts), date('Y-m-t 23:59:59', $ts)];
}

/* 1. SUMMARY KPI CARDS */
function getSummary(mysqli $db, string $start, string $end): array
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM applications WHERE submitted_at BETWEEN ? AND ?");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM applications
         WHERE status IN ('unassign','unassigned') AND submitted_at BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $unassigned = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) FROM evaluators WHERE created_at BETWEEN ? AND ?");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $evaluators = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE created_at BETWEEN ? AND ?");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $clients = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return [
        'total'      => $total,
        'unassigned' => $unassigned,
        'evaluators' => $evaluators,
        'clients'    => $clients,
    ];
}

/* 2. APPLICATIONS BY STAGE (STATUS) */
function getStages(mysqli $db, string $start, string $end): array
{
    $order = [
        'pending'          => ['label' => 'Pending',       'color' => '#3b82f6'],
        'unassign'         => ['label' => 'Unassigned',    'color' => '#ef4444'],
        'assign'           => ['label' => 'Assigned',      'color' => '#60a5fa'],
        'under_evaluation' => ['label' => 'Under Eval.',   'color' => '#a78bfa'],
        'for_compliance'   => ['label' => 'Compliance',    'color' => '#f97316'],
        'resubmitted'      => ['label' => 'Resubmitted',   'color' => '#6366f1'],
        'approved'         => ['label' => 'Approved',      'color' => '#059669'],
        'disapproved'      => ['label' => 'Disapproved',   'color' => '#dc2626'],
    ];

    $stmt = $db->prepare(
        "SELECT status, COUNT(*) AS cnt FROM applications
         WHERE submitted_at BETWEEN ? AND ? GROUP BY status"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $counts = [];
    while ($r = $res->fetch_assoc()) {
        $s = $r['status'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + (int) $r['cnt'];
    }
    $stmt->close();

    $max    = max(array_values($counts) ?: [1]);
    $result = [];
    foreach ($order as $key => $meta) {
        $c = $counts[$key] ?? 0;
        $result[] = [
            'label' => $meta['label'],
            'color' => $meta['color'],
            'count' => $c,
            'pct'   => $max > 0 ? round($c / $max * 100) : 0,
        ];
    }
    return $result;
}

/* 3. AVERAGE TIME PER STAGE (from status_history), scoped to range */
function getAvgTime(mysqli $db, string $start, string $end): array
{
    $stmt = $db->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, a.submitted_at, sh.changed_at)) AS hrs
         FROM status_history sh
         JOIN applications a ON a.id = sh.application_id
         WHERE sh.from_status = 'pending'
           AND sh.changed_at BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $submission = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status = 'under_evaluation'
          AND sh2.changed_at > sh1.changed_at
         WHERE sh1.to_status IN ('assign','assigned')
           AND sh2.changed_at BETWEEN ? AND ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $assignment = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status IN ('approved','disapproved')
          AND sh2.changed_at > sh1.changed_at
         WHERE sh1.to_status = 'under_evaluation'
           AND sh2.changed_at BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $review = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, e.evaluated_at, a.updated_at)) AS hrs
         FROM evaluations e
         JOIN applications a ON a.id = e.application_id
         WHERE e.decision IN ('approved','disapproved')
           AND e.evaluated_at BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $decision = round((float) ($stmt->get_result()->fetch_row()[0] ?? 0), 2);
    $stmt->close();

    $max = max($submission, $assignment, $review, $decision, 0.1);

    return [
        ['label' => 'Submission', 'hours' => $submission, 'color' => '#3b82f6', 'pct' => round($submission / $max * 100)],
        ['label' => 'Assignment', 'hours' => $assignment, 'color' => '#f59e0b', 'pct' => round($assignment / $max * 100)],
        ['label' => 'Review',     'hours' => $review,     'color' => '#ef4444', 'pct' => round($review     / $max * 100)],
        ['label' => 'Decision',   'hours' => $decision,   'color' => '#059669', 'pct' => round($decision   / $max * 100)],
    ];
}

/* 4. OVERDUE (pending/under_evaluation older than 7 days), scoped to range */
function getOverdue(mysqli $db, int $days = 7): array
{
    $stmt = $db->prepare(
        "SELECT a.id, a.status, a.submitted_at,
                CONCAT(c.first_name,' ',c.last_name) AS applicant,
                r.rsf_number, r.position_title,
                DATEDIFF(NOW(), a.submitted_at) AS days_waiting
         FROM applications a
         LEFT JOIN clients c ON c.cid = a.client_id
         LEFT JOIN rsf_job_orders r ON r.id = a.rsf_id
         WHERE a.status NOT IN ('approved','disapproved')
           AND a.submitted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
         ORDER BY days_waiting DESC"
    );
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id'           => (int) $r['id'],
            'applicant'    => $r['applicant'],
            'status'       => $r['status'],
            'rsf'          => $r['rsf_number'],
            'position'     => $r['position_title'],
            'days_waiting' => (int) $r['days_waiting'],
        ];
    }
    $stmt->close();
    return ['count' => count($items), 'items' => $items];
}

/* 5. SUBMISSION TREND
   - 'all' (current year)  -> monthly buckets (Jan..Dec)
   - specific month        -> weekly buckets within that month       */
function getWeekly(mysqli $db, string $monthParam, string $start, string $end): array
{
    if ($monthParam === 'all') {
        $labels      = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $submitted   = array_fill(1, 12, 0);
        $approved    = array_fill(1, 12, 0);
        $disapproved = array_fill(1, 12, 0);

        $stmt = $db->prepare(
            "SELECT MONTH(submitted_at) AS mo, COUNT(*) AS cnt
             FROM applications WHERE submitted_at BETWEEN ? AND ? GROUP BY mo"
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $submitted[(int) $r['mo']] = (int) $r['cnt'];
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT MONTH(updated_at) AS mo, status, COUNT(*) AS cnt
             FROM applications
             WHERE status IN ('approved','disapproved') AND updated_at BETWEEN ? AND ?
             GROUP BY mo, status"
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $mo = (int) $r['mo'];
            if ($r['status'] === 'approved')    $approved[$mo]    = (int) $r['cnt'];
            if ($r['status'] === 'disapproved') $disapproved[$mo] = (int) $r['cnt'];
        }
        $stmt->close();

        return [
            'labels'      => $labels,
            'submitted'   => array_values($submitted),
            'approved'    => array_values($approved),
            'disapproved' => array_values($disapproved),
        ];
    }

    // Specific month -> weekly buckets
    $weeks  = [];
    $cursor = strtotime($start);
    $endTs  = strtotime($end);
    while ($cursor <= $endTs) {
        $weeks[] = date('Y', $cursor) . '-W' . str_pad((int) date('W', $cursor), 2, '0', STR_PAD_LEFT);
        $cursor  = strtotime('+1 week', $cursor);
    }
    $lastWeek = date('Y', $endTs) . '-W' . str_pad((int) date('W', $endTs), 2, '0', STR_PAD_LEFT);
    if (!in_array($lastWeek, $weeks, true)) $weeks[] = $lastWeek;
    $weeks = array_values(array_unique($weeks));

    $submitted   = array_fill_keys($weeks, 0);
    $approved    = array_fill_keys($weeks, 0);
    $disapproved = array_fill_keys($weeks, 0);

    $stmt = $db->prepare(
        "SELECT CONCAT(YEAR(submitted_at),'-W',LPAD(WEEK(submitted_at,3),2,'0')) AS wk, COUNT(*) AS cnt
         FROM applications WHERE submitted_at BETWEEN ? AND ? GROUP BY wk"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) if (isset($submitted[$r['wk']])) $submitted[$r['wk']] = (int) $r['cnt'];
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT CONCAT(YEAR(updated_at),'-W',LPAD(WEEK(updated_at,3),2,'0')) AS wk, status, COUNT(*) AS cnt
         FROM applications
         WHERE status IN ('approved','disapproved') AND updated_at BETWEEN ? AND ?
         GROUP BY wk, status"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if ($r['status'] === 'approved'    && isset($approved[$r['wk']]))    $approved[$r['wk']]    = (int) $r['cnt'];
        if ($r['status'] === 'disapproved' && isset($disapproved[$r['wk']])) $disapproved[$r['wk']] = (int) $r['cnt'];
    }
    $stmt->close();

    $labels = array_map(fn($w) => 'W' . ltrim(explode('-W', $w)[1], '0'), $weeks);

    return [
        'labels'      => $labels,
        'submitted'   => array_values($submitted),
        'approved'    => array_values($approved),
        'disapproved' => array_values($disapproved),
    ];
}

/* 6. TOP SERVICES BY APPLICATION VOLUME, scoped to range */
function getServices(mysqli $db, string $start, string $end): array
{
    $stmt = $db->prepare(
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.classification, r.slots_available, r.deadline,
                COUNT(a.id) AS applied
         FROM rsf_job_orders r
         LEFT JOIN applications a ON a.rsf_id = r.id AND a.submitted_at BETWEEN ? AND ?
         GROUP BY r.id
         ORDER BY applied DESC
         LIMIT 6"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $max  = 1;
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        if ((int) $r['applied'] > $max) $max = (int) $r['applied'];
    }
    $stmt->close();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'rsf'      => $r['rsf_number'],
            'title'    => $r['position_title'],
            'employer' => $r['employer_name'],
            'country'  => $r['worksite_country'],
            'type'     => $r['classification'] ?? 'Government',
            'slots'    => (int) $r['slots_available'],
            'applied'  => (int) $r['applied'],
            'pct'      => round((int) $r['applied'] / $max * 100),
            'deadline' => $r['deadline'],
        ];
    }
    return $items;
}

/* 7. MONTHLY JOB ORDERS POSTED vs APPLICATIONS RECEIVED, scoped to range */
function getSvcMonthly(mysqli $db, string $start, string $end): array
{
    $posted   = array_fill(1, 12, 0);
    $received = array_fill(1, 12, 0);

    $stmt = $db->prepare(
        "SELECT MONTH(created_at) AS mo, COUNT(*) AS cnt
         FROM rsf_job_orders
         WHERE created_at BETWEEN ? AND ?
         GROUP BY mo"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $posted[(int) $r['mo']] = (int) $r['cnt'];
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT MONTH(submitted_at) AS mo, COUNT(*) AS cnt
         FROM applications
         WHERE submitted_at BETWEEN ? AND ?
         GROUP BY mo"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $received[(int) $r['mo']] = (int) $r['cnt'];
    $stmt->close();

    return [
        'labels'   => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'posted'   => array_values($posted),
        'received' => array_values($received),
    ];
}

/* 8. TOP DESTINATION COUNTRIES, scoped to range */
function getDestinations(mysqli $db, string $start, string $end): array
{
    $stmt = $db->prepare(
        "SELECT r.worksite_country AS country, COUNT(a.id) AS cnt
         FROM applications a
         JOIN rsf_job_orders r ON r.id = a.rsf_id
         WHERE a.submitted_at BETWEEN ? AND ?
         GROUP BY r.worksite_country
         ORDER BY cnt DESC"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ['country' => $r['country'] ?: 'Unspecified', 'cnt' => (int) $r['cnt']];
    }
    $stmt->close();

    $palette = ['#1d4ed8', '#059669', '#d97706', '#6366f1', '#dc2626', '#94a3b8', '#0891b2', '#7c3aed'];

    // Keep top 6, fold the remainder into "Other" so totals still match the filtered period
    $TOP_N = 6;
    $top   = array_slice($rows, 0, $TOP_N);
    $rest  = array_slice($rows, $TOP_N);

    if (!empty($rest)) {
        $otherTotal = array_sum(array_column($rest, 'cnt'));
        if ($otherTotal > 0) {
            $top[] = ['country' => 'Other', 'cnt' => $otherTotal];
        }
    }

    $labels = [];
    $data   = [];
    $colors = [];
    foreach ($top as $i => $row) {
        $labels[] = $row['country'];
        $data[]   = $row['cnt'];
        $colors[] = $palette[$i % count($palette)]; // cycle, never runs out
    }

    $total  = array_sum($data) ?: 1;
    $legend = [];
    foreach ($labels as $i => $l) {
        $legend[] = ['label' => $l, 'pct' => round($data[$i] / $total * 100), 'color' => $colors[$i]];
    }

    return ['labels' => $labels, 'data' => $data, 'colors' => $colors, 'legend' => $legend];
}

/* 9. EVALUATOR PERFORMANCE & WORKLOAD, scoped to range */
function getEvaluators(mysqli $db, string $start, string $end): array
{
    $wlColors = ['#059669', '#d97706', '#3b82f6', '#6366f1', '#dc2626', '#94a3b8'];

    $stmt = $db->prepare(
        "SELECT e.eid, e.full_name, e.email,
                COUNT(DISTINCT asn.application_id) AS handled
         FROM evaluators e
         LEFT JOIN assignments asn ON asn.evaluator_id = e.eid AND asn.assigned_at BETWEEN ? AND ?
         GROUP BY e.eid
         ORDER BY handled DESC"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows    = [];
    $maxH    = 1;
    $wlNames = [];
    $wlData  = [];
    $idx     = 0;

    while ($r = $res->fetch_assoc()) {
        $h = (int) $r['handled'];
        if ($h > $maxH) $maxH = $h;

        $initials = implode('', array_map(
            fn($p) => strtoupper($p[0] ?? ''),
            array_filter(explode(' ', $r['full_name']))
        ));
        $initials = substr($initials, 0, 2);

        $color  = $wlColors[$idx % count($wlColors)];
        $rows[] = [
            'name'     => $r['full_name'],
            'email'    => $r['email'],
            'initials' => $initials,
            'handled'  => $h,
            'pct'      => 0,
            'color'    => $color,
        ];
        $nameParts = explode(' ', $r['full_name']);
        $wlNames[] = ($nameParts[0] ?? '') . ' ' . (isset($nameParts[1][0]) ? $nameParts[1][0] . '.' : '');
        $wlData[]  = $h;
        $idx++;
    }
    $stmt->close();

    foreach ($rows as &$row) {
        $row['pct'] = $maxH > 0 ? round($row['handled'] / $maxH * 100) : 0;
    }

    return [
        'staff'           => $rows,
        'workload_names'  => $wlNames,
        'workload_data'   => $wlData,
        'workload_colors' => array_slice($wlColors, 0, count($wlNames)),
    ];
}

/* 10. ACTIVE JOB ORDERS — deadline not yet passed; applied count scoped to range */
function getJobOrders(mysqli $db, string $start, string $end): array
{
    $stmt = $db->prepare(
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.slots_available, r.deadline,
                COUNT(a.id) AS applied
         FROM rsf_job_orders r
         LEFT JOIN applications a ON a.rsf_id = r.id AND a.submitted_at BETWEEN ? AND ?
         WHERE r.deadline >= CURDATE()
         GROUP BY r.id
         ORDER BY r.deadline ASC"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $jobs = [];
    while ($r = $res->fetch_assoc()) {
        $slots   = (int) $r['slots_available'];
        $applied = (int) $r['applied'];
        $pct     = $slots > 0 ? min(100, round($applied / $slots * 100)) : 0;

        $jobs[] = [
            'rsf'       => $r['rsf_number'],
            'title'     => $r['position_title'],
            'employer'  => $r['employer_name'],
            'country'   => $r['worksite_country'],
            'slots'     => $slots,
            'applied'   => $applied,
            'fill_pct'  => $pct,
            'deadline'  => $r['deadline'],
            'days_left' => (int) ceil((strtotime($r['deadline']) - time()) / 86400),
        ];
    }
    $stmt->close();
    return $jobs;
}
