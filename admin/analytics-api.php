<?php
/**
 * analytics-api.php
 * DMW RO IV-A – Admin Analytics Backend
 * Drop inside your admin/ folder alongside analytics.php
 *
 * Actions:
 *   summary       – 4 KPI cards (total apps, unassigned, active evaluators, registered clients)
 *   stages        – application count per status (stage list + % bars)
 *   avg_time      – avg days per workflow stage from status_history
 *   overdue       – pending/under_evaluation apps older than 7 days
 *   weekly        – last 8 weeks: submitted / approved / disapproved per week
 *   services      – top job positions by application volume
 *   svc_monthly   – monthly app volume (current year, gov vs private)
 *   destinations  – top worksite_country doughnut
 *   evaluators    – per-evaluator handled count + workload distribution
 *   job_orders    – active RSF orders with fill rate
 */

session_start();
require_once '../db.php'; // provides $database (mysqli)

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'summary':      echo json_encode(getSummary($database));     break;
        case 'stages':       echo json_encode(getStages($database));      break;
        case 'avg_time':     echo json_encode(getAvgTime($database));     break;
        case 'overdue':      echo json_encode(getOverdue($database));     break;
        case 'weekly':       echo json_encode(getWeekly($database));      break;
        case 'services':     echo json_encode(getServices($database));    break;
        case 'svc_monthly':  echo json_encode(getSvcMonthly($database));  break;
        case 'destinations': echo json_encode(getDestinations($database));break;
        case 'evaluators':   echo json_encode(getEvaluators($database));  break;
        case 'job_orders':   echo json_encode(getJobOrders($database));   break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/* ═══════════════════════════════════════════════════════════════
   1. SUMMARY KPI CARDS
   total apps / unassigned / active evaluators / registered clients
═══════════════════════════════════════════════════════════════ */
function getSummary(mysqli $db): array
{
    $total = (int) $db->query("SELECT COUNT(*) FROM applications")->fetch_row()[0];

    // Unassigned: roll up both spellings
    $res = $db->query(
        "SELECT COUNT(*) FROM applications WHERE status IN ('unassign','unassigned')"
    );
    $unassigned = (int) $res->fetch_row()[0];

    $evaluators = (int) $db->query("SELECT COUNT(*) FROM evaluators")->fetch_row()[0];
    $clients    = (int) $db->query("SELECT COUNT(*) FROM clients")->fetch_row()[0];

    return [
        'total'      => $total,
        'unassigned' => $unassigned,
        'evaluators' => $evaluators,
        'clients'    => $clients,
    ];
}

/* ═══════════════════════════════════════════════════════════════
   2. APPLICATIONS BY STAGE (STATUS)
═══════════════════════════════════════════════════════════════ */
function getStages(mysqli $db): array
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

    $counts = [];
    $res = $db->query("SELECT status, COUNT(*) AS cnt FROM applications GROUP BY status");
    while ($r = $res->fetch_assoc()) {
        $s = $r['status'];
        if ($s === 'unassigned') $s = 'unassign';
        if ($s === 'assigned')   $s = 'assign';
        $counts[$s] = ($counts[$s] ?? 0) + (int) $r['cnt'];
    }

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

/* ═══════════════════════════════════════════════════════════════
   3. AVERAGE TIME PER STAGE  (from status_history)
   We measure median/avg hours between consecutive transitions.
   Stages: submission→first_assign, assign→under_eval, under_eval→decision
═══════════════════════════════════════════════════════════════ */
function getAvgTime(mysqli $db): array
{
    // Submission → first status change (pending → anything)
    $res = $db->query(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, a.submitted_at, sh.changed_at)) AS hrs
         FROM status_history sh
         JOIN applications a ON a.id = sh.application_id
         WHERE sh.from_status = 'pending'"
    );
    $submission = round((float) $res->fetch_row()[0] / 24, 1);

    // Assign → under_evaluation
    $res = $db->query(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status = 'under_evaluation'
          AND sh2.changed_at > sh1.changed_at
         WHERE sh1.to_status IN ('assign','assigned')
         LIMIT 1"
    );
    $assignment = round((float) $res->fetch_row()[0] / 24, 1);

    // Under evaluation → terminal (approved / disapproved)
    $res = $db->query(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, sh1.changed_at, sh2.changed_at)) AS hrs
         FROM status_history sh1
         JOIN status_history sh2
           ON sh1.application_id = sh2.application_id
          AND sh2.to_status IN ('approved','disapproved')
          AND sh2.changed_at > sh1.changed_at
         WHERE sh1.to_status = 'under_evaluation'"
    );
    $review = round((float) $res->fetch_row()[0] / 24, 1);

    // Decision → finalized (approved/disapproved) – same as review end for now
    $decision = 0;
    $stmt = $db->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR, e.evaluated_at, a.updated_at)) AS hrs
         FROM evaluations e
         JOIN applications a ON a.id = e.application_id
         WHERE e.decision IN ('approved','disapproved')"
    );
    $stmt->execute();
    $decision = round((float) $stmt->get_result()->fetch_row()[0] / 24, 1);
    $stmt->close();

    $max = max($submission, $assignment, $review, $decision, 0.1);

    return [
        ['label' => 'Submission', 'days' => $submission, 'color' => '#3b82f6', 'pct' => round($submission / $max * 100)],
        ['label' => 'Assignment', 'days' => $assignment, 'color' => '#f59e0b', 'pct' => round($assignment / $max * 100)],
        ['label' => 'Review',     'days' => $review,     'color' => '#ef4444', 'pct' => round($review     / $max * 100)],
        ['label' => 'Decision',   'days' => $decision,   'color' => '#059669', 'pct' => round($decision   / $max * 100)],
    ];
}

/* ═══════════════════════════════════════════════════════════════
   4. OVERDUE  (pending / under_evaluation  older than 7 days)
═══════════════════════════════════════════════════════════════ */
function getOverdue(mysqli $db): array
{
    $res = $db->query(
        "SELECT a.id, a.status, a.submitted_at,
                CONCAT(c.first_name,' ',c.last_name) AS applicant,
                r.rsf_number, r.position_title,
                DATEDIFF(NOW(), a.submitted_at) AS days_waiting
         FROM applications a
         LEFT JOIN clients c ON c.cid = a.client_id
         LEFT JOIN rsf_job_orders r ON r.id = a.rsf_id
         WHERE a.status IN ('pending','under_evaluation','unassign','assign')
           AND a.submitted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY days_waiting DESC"
    );

    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id'           => $r['id'],
            'applicant'    => $r['applicant'],
            'status'       => $r['status'],
            'rsf'          => $r['rsf_number'],
            'position'     => $r['position_title'],
            'days_waiting' => (int) $r['days_waiting'],
        ];
    }
    return ['count' => count($items), 'items' => $items];
}

/* ═══════════════════════════════════════════════════════════════
   5. WEEKLY SUBMISSION TREND  (last 8 weeks)
   submitted / approved / disapproved per ISO week
═══════════════════════════════════════════════════════════════ */
function getWeekly(mysqli $db): array
{
    // Build last 8 week keys (YYYY-Www)
    $weeks = [];
    for ($i = 7; $i >= 0; $i--) {
        $ts      = strtotime("-$i weeks");
        $weeks[] = date('Y', $ts) . '-W' . str_pad((int)date('W', $ts), 2, '0', STR_PAD_LEFT);
    }

    $submitted   = array_fill_keys($weeks, 0);
    $approved    = array_fill_keys($weeks, 0);
    $disapproved = array_fill_keys($weeks, 0);

    $res = $db->query(
        "SELECT CONCAT(YEAR(submitted_at),'-W',LPAD(WEEK(submitted_at,3),2,'0')) AS wk,
                COUNT(*) AS cnt
         FROM applications
         WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
         GROUP BY wk"
    );
    while ($r = $res->fetch_assoc()) {
        if (isset($submitted[$r['wk']])) $submitted[$r['wk']] = (int)$r['cnt'];
    }

    $res = $db->query(
        "SELECT CONCAT(YEAR(updated_at),'-W',LPAD(WEEK(updated_at,3),2,'0')) AS wk,
                status, COUNT(*) AS cnt
         FROM applications
         WHERE status IN ('approved','disapproved')
           AND updated_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
         GROUP BY wk, status"
    );
    while ($r = $res->fetch_assoc()) {
        if ($r['status'] === 'approved'    && isset($approved[$r['wk']]))    $approved[$r['wk']]    = (int)$r['cnt'];
        if ($r['status'] === 'disapproved' && isset($disapproved[$r['wk']])) $disapproved[$r['wk']] = (int)$r['cnt'];
    }

    // Make human-readable labels (W1, W2…)
    $labels = array_map(fn($w) => 'W' . ltrim(explode('-W', $w)[1], '0'), $weeks);

    return [
        'labels'      => $labels,
        'submitted'   => array_values($submitted),
        'approved'    => array_values($approved),
        'disapproved' => array_values($disapproved),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   6. TOP SERVICES BY APPLICATION VOLUME
   (grouped by RSF position_title)
═══════════════════════════════════════════════════════════════ */
function getServices(mysqli $db): array
{
    $res = $db->query(
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.classification, r.slots_available, r.deadline,
                COUNT(a.id) AS applied
         FROM rsf_job_orders r
         LEFT JOIN applications a ON a.rsf_id = r.id
         GROUP BY r.id
         ORDER BY applied DESC
         LIMIT 6"
    );

    $items = [];
    $max   = 1;
    $rows  = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        if ((int)$r['applied'] > $max) $max = (int)$r['applied'];
    }

    foreach ($rows as $r) {
        $items[] = [
            'rsf'       => $r['rsf_number'],
            'title'     => $r['position_title'],
            'employer'  => $r['employer_name'],
            'country'   => $r['worksite_country'],
            'type'      => $r['classification'] ?? 'Government',
            'slots'     => (int)$r['slots_available'],
            'applied'   => (int)$r['applied'],
            'pct'       => round((int)$r['applied'] / $max * 100),
            'deadline'  => $r['deadline'],
        ];
    }
    return $items;
}

/* ═══════════════════════════════════════════════════════════════
   7. MONTHLY SERVICE USAGE (current year)
   Government vs Private stacked bar
═══════════════════════════════════════════════════════════════ */
function getSvcMonthly(mysqli $db): array
{
    $year = (int)date('Y');
    $gov  = array_fill(1, 12, 0);
    $priv = array_fill(1, 12, 0);

    $stmt = $db->prepare(
        "SELECT MONTH(a.submitted_at) AS mo, r.classification, COUNT(*) AS cnt
         FROM applications a
         JOIN rsf_job_orders r ON r.id = a.rsf_id
         WHERE YEAR(a.submitted_at) = ?
         GROUP BY mo, r.classification"
    );
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    while ($r = $res->fetch_assoc()) {
        $mo = (int)$r['mo'];
        if (strtolower($r['classification'] ?? '') === 'private') {
            $priv[$mo] += (int)$r['cnt'];
        } else {
            $gov[$mo]  += (int)$r['cnt'];
        }
    }

    return [
        'labels' => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        'gov'    => array_values($gov),
        'priv'   => array_values($priv),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   8. TOP DESTINATION COUNTRIES (doughnut)
═══════════════════════════════════════════════════════════════ */
function getDestinations(mysqli $db): array
{
    $res = $db->query(
        "SELECT r.worksite_country AS country, COUNT(a.id) AS cnt
         FROM applications a
         JOIN rsf_job_orders r ON r.id = a.rsf_id
         GROUP BY r.worksite_country
         ORDER BY cnt DESC"
    );

    $labels  = [];
    $data    = [];
    $palette = ['#1d4ed8','#059669','#d97706','#6366f1','#dc2626','#94a3b8'];

    while ($r = $res->fetch_assoc()) {
        $labels[] = $r['country'];
        $data[]   = (int)$r['cnt'];
    }

    $total  = array_sum($data) ?: 1;
    $colors = array_slice($palette, 0, count($labels));

    $legend = [];
    foreach ($labels as $i => $l) {
        $legend[] = ['label' => $l, 'pct' => round($data[$i] / $total * 100), 'color' => $colors[$i]];
    }

    return ['labels' => $labels, 'data' => $data, 'colors' => $colors, 'legend' => $legend];
}

/* ═══════════════════════════════════════════════════════════════
   9. EVALUATOR PERFORMANCE & WORKLOAD
═══════════════════════════════════════════════════════════════ */
function getEvaluators(mysqli $db): array
{
    $wlColors = ['#059669','#d97706','#3b82f6','#6366f1','#dc2626','#94a3b8'];

    // Total evaluated (final decision) per evaluator
    $res = $db->query(
        "SELECT e.eid, e.full_name, e.email,
                COUNT(DISTINCT asn.application_id) AS handled
         FROM evaluators e
         LEFT JOIN assignments asn ON asn.evaluator_id = e.eid
         GROUP BY e.eid
         ORDER BY handled DESC"
    );

    $rows    = [];
    $maxH    = 1;
    $wlNames = [];
    $wlData  = [];

    $idx = 0;
    while ($r = $res->fetch_assoc()) {
        $h = (int)$r['handled'];
        if ($h > $maxH) $maxH = $h;

        $initials = implode('', array_map(fn($p) => strtoupper($p[0] ?? ''),
            array_filter(explode(' ', $r['full_name']))));
        $initials = substr($initials, 0, 2);

        $color   = $wlColors[$idx % count($wlColors)];
        $rows[]  = [
            'name'     => $r['full_name'],
            'email'    => $r['email'],
            'initials' => $initials,
            'handled'  => $h,
            'pct'      => 0, // filled below
            'color'    => $color,
        ];
        $wlNames[] = explode(' ', $r['full_name'])[0] . ' ' . (explode(' ', $r['full_name'])[1][0] ?? '') . '.';
        $wlData[]  = $h;
        $idx++;
    }

    foreach ($rows as &$row) {
        $row['pct'] = $maxH > 0 ? round($row['handled'] / $maxH * 100) : 0;
    }

    return [
        'staff'          => $rows,
        'workload_names' => $wlNames,
        'workload_data'  => $wlData,
        'workload_colors'=> array_slice($wlColors, 0, count($wlNames)),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   10. ACTIVE JOB ORDERS (deadline not passed)
═══════════════════════════════════════════════════════════════ */
function getJobOrders(mysqli $db): array
{
    $res = $db->query(
        "SELECT r.id, r.rsf_number, r.position_title, r.employer_name,
                r.worksite_country, r.slots_available, r.deadline,
                COUNT(a.id) AS applied
         FROM rsf_job_orders r
         LEFT JOIN applications a ON a.rsf_id = r.id
         WHERE r.deadline >= CURDATE()
         GROUP BY r.id
         ORDER BY r.deadline ASC"
    );

    $jobs = [];
    while ($r = $res->fetch_assoc()) {
        $slots   = (int)$r['slots_available'];
        $applied = (int)$r['applied'];
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
            'days_left' => (int)ceil((strtotime($r['deadline']) - time()) / 86400),
        ];
    }
    return $jobs;
}
