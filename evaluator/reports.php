<?php
// evaluator/reports.php – DMW Evaluator Reports
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
// 1. MY WORKLOAD — active assignments
// ────────────────────────────────────────────────
$workload          = [];
$workload_total     = 0;
$workload_under_eval = 0;
$workload_for_compliance = 0;

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id,
            a.status,
            a.submitted_at,
            r.rsf_number,
            r.position_title,
            r.worksite_country AS country,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name
        FROM assignments asn
        INNER JOIN applications   a ON asn.application_id = a.id
        INNER JOIN rsf_job_orders r ON a.rsf_id           = r.id
        LEFT  JOIN clients        c ON a.client_id        = c.cid
        WHERE asn.evaluator_id = ?
          AND asn.status       = 'active'
        ORDER BY a.submitted_at ASC
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $workload = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $workload_total = count($workload);
    foreach ($workload as $w) {
        if ($w['status'] === 'under_evaluation') $workload_under_eval++;
        if ($w['status'] === 'for_compliance')   $workload_for_compliance++;
    }
}

// ────────────────────────────────────────────────
// 2. MY DECISION LOG — evaluations I've made
// ────────────────────────────────────────────────
$decisions       = [];
$decision_counts = ['approved' => 0, 'for_compliance' => 0, 'disapproved' => 0, 'total' => 0];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            e.id,
            e.decision,
            e.comments,
            e.evaluated_at,
            a.id AS application_id,
            r.rsf_number,
            r.position_title,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name
        FROM evaluations e
        INNER JOIN applications   a ON e.application_id = a.id
        INNER JOIN rsf_job_orders r ON a.rsf_id          = r.id
        LEFT  JOIN clients        c ON a.client_id       = c.cid
        WHERE e.evaluator_id = ?
        ORDER BY e.evaluated_at DESC
        LIMIT 25
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $decisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt_dc = $database->prepare("
        SELECT decision, COUNT(*) AS cnt
        FROM evaluations
        WHERE evaluator_id = ?
        GROUP BY decision
    ");
    $stmt_dc->bind_param("i", $evaluator_id);
    $stmt_dc->execute();
    $dc_res = $stmt_dc->get_result();
    while ($row = $dc_res->fetch_assoc()) {
        $key = $row['decision'];
        $cnt = (int)$row['cnt'];
        if (array_key_exists($key, $decision_counts)) {
            $decision_counts[$key] = $cnt;
        }
        $decision_counts['total'] += $cnt;
    }
    $stmt_dc->close();
}

// ────────────────────────────────────────────────
// 3. TURNAROUND TIME — assigned_at vs evaluated_at
// ────────────────────────────────────────────────
$turnaround_rows = [];
$avg_turnaround_hours = null;

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id AS application_id,
            r.rsf_number,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name,
            asn.assigned_at,
            e.evaluated_at,
            TIMESTAMPDIFF(HOUR, asn.assigned_at, e.evaluated_at) AS hours_taken
        FROM assignments asn
        INNER JOIN applications   a ON asn.application_id = a.id
        INNER JOIN rsf_job_orders r ON a.rsf_id           = r.id
        LEFT  JOIN clients        c ON a.client_id        = c.cid
        INNER JOIN evaluations    e ON e.application_id   = a.id 
                                    AND e.evaluator_id     = asn.evaluator_id
        WHERE asn.evaluator_id = ?
        ORDER BY e.evaluated_at DESC
        LIMIT 25
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $turnaround_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($turnaround_rows)) {
        $sum = 0;
        foreach ($turnaround_rows as $t) {
            $sum += (int)$t['hours_taken'];
        }
        $avg_turnaround_hours = round($sum / count($turnaround_rows), 1);
    }
}

// ────────────────────────────────────────────────
// 4. COMPLIANCE / RESUBMISSION TRACKER
// ────────────────────────────────────────────────
$compliance_rows = [];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id AS application_id,
            r.rsf_number,
            a.status AS current_status,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name,
            sh_out.changed_at AS flagged_at,
            sh_in.changed_at  AS resubmitted_at
        FROM assignments asn
        INNER JOIN applications   a ON asn.application_id = a.id
        INNER JOIN rsf_job_orders r ON a.rsf_id           = r.id
        LEFT  JOIN clients        c ON a.client_id        = c.cid
        INNER JOIN status_history sh_out 
                ON sh_out.application_id = a.id 
               AND sh_out.to_status      = 'for_compliance'
        LEFT  JOIN status_history sh_in 
                ON sh_in.application_id  = a.id 
               AND sh_in.from_status     = 'for_compliance' 
               AND sh_in.to_status       = 'resubmitted'
        WHERE asn.evaluator_id = ?
        GROUP BY a.id
        ORDER BY sh_out.changed_at DESC
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $compliance_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ────────────────────────────────────────────────
// 5. DOCUMENT CHECKLIST — required vs uploaded
// ────────────────────────────────────────────────
$doc_checklist = [];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id AS application_id,
            r.rsf_number,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name,
            COUNT(DISTINCT rd.id) AS required_count,
            COUNT(DISTINCT ad.required_doc_id) AS uploaded_count
        FROM assignments asn
        INNER JOIN applications          a  ON asn.application_id = a.id
        INNER JOIN rsf_job_orders        r  ON a.rsf_id           = r.id
        LEFT  JOIN clients               c  ON a.client_id        = c.cid
        LEFT  JOIN rsf_required_documents rd ON rd.rsf_id          = r.id
        LEFT  JOIN application_documents  ad ON ad.application_id  = a.id 
                                              AND ad.required_doc_id = rd.id
        WHERE asn.evaluator_id = ?
          AND asn.status       = 'active'
        GROUP BY a.id
        ORDER BY a.submitted_at ASC
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $doc_checklist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Greeting / avatar (same as index.php)
$hour       = (int)date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($evaluator_name))[0] ?: $evaluator_name;

$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// Helper: status pill class (same mapping as index.php)
function pillClass(string $status): string
{
    return match ($status) {
        'approved'         => 'sp-approved',
        'under_evaluation' => 'sp-under',
        'for_compliance'   => 'sp-compliance',
        'disapproved'      => 'sp-disapproved',
        'assign', 'assigned' => 'sp-assigned',
        'resubmitted'       => 'sp-under',
        default             => 'sp-default',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reports – DMW Evaluator Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Page-specific additions that extend dashboard.css ── */

        .tab-row {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            margin-bottom: 14px;
            padding-bottom: 2px;
        }

        .tab-btn {
            flex-shrink: 0;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 99px;
            padding: 8px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text2);
            cursor: pointer;
            transition: background .16s, color .16s, border-color .16s;
            white-space: nowrap;
        }

        .tab-btn.active {
            background: var(--indigo);
            border-color: var(--indigo);
            color: #fff;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th {
            text-align: left;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .report-table td {
            font-size: 12px;
            color: var(--text1);
            padding: 10px 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .report-table tr:last-child td {
            border-bottom: none;
        }

        .report-table .meta-sub {
            font-size: 10.5px;
            color: var(--text3);
            margin-top: 2px;
            font-weight: 500;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .mini-stat-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .mini-stat {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .mini-stat .ms-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--text3);
        }

        .mini-stat .ms-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--text1);
            margin-top: 4px;
            font-family: 'DM Sans', sans-serif;
        }

        .doc-progress-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 110px;
        }

        .doc-progress-bar {
            flex: 1;
            height: 6px;
            background: #eef2ff;
            border-radius: 99px;
            overflow: hidden;
        }

        .doc-progress-fill {
            height: 100%;
            border-radius: 99px;
        }

        .doc-progress-text {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
            flex-shrink: 0;
        }

        .badge-complete {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-incomplete {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-small {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 99px;
        }

        .empty-row {
            padding: 30px 10px;
            text-align: center;
            color: var(--text3);
            font-size: 12px;
            font-weight: 600;
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
                <h2>Reports</h2>
                <p><?= htmlspecialchars($first_name) ?>'s personal evaluation reports</p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ -->
    <div class="main">

        <!-- Tabs -->
        <div class="tab-row fade-in d1">
            <button class="tab-btn active" data-tab="workload">My Workload</button>
            <button class="tab-btn" data-tab="decisions">Decision Log</button>
            <button class="tab-btn" data-tab="turnaround">Turnaround Time</button>
            <button class="tab-btn" data-tab="compliance">Compliance Tracker</button>
            <button class="tab-btn" data-tab="documents">Document Checklist</button>
        </div>

        <!-- ────────────────────────────────────
             TAB 1: MY WORKLOAD
        ──────────────────────────────────── -->
        <div class="tab-panel active" id="panel-workload">

            <div class="mini-stat-row fade-in d2">
                <div class="mini-stat">
                    <div class="ms-label">Active Assignments</div>
                    <div class="ms-value"><?= number_format($workload_total) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">Under Evaluation</div>
                    <div class="ms-value"><?= number_format($workload_under_eval) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">For Compliance</div>
                    <div class="ms-value"><?= number_format($workload_for_compliance) ?></div>
                </div>
            </div>

            <div class="card fade-in d3">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt)"></span>
                        My Assigned Applications
                    </span>
                </div>
                <div class="table-wrap">
                    <?php if (empty($workload)): ?>
                        <div class="empty-row">No active assignments right now.</div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF / Position</th>
                                    <th>Country</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workload as $w): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($w['applicant_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($w['rsf_number'] ?? '—') ?>
                                            <div class="meta-sub"><?= htmlspecialchars($w['position_title'] ?? '—') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($w['country'] ?? '—') ?></td>
                                        <td>
                                            <span class="status-pill pill-small <?= pillClass($w['status']) ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', ucwords($w['status']))) ?>
                                            </span>
                                        </td>
                                        <td><?= $w['submitted_at'] ? date('M d, Y', strtotime($w['submitted_at'])) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ────────────────────────────────────
             TAB 2: DECISION LOG
        ──────────────────────────────────── -->
        <div class="tab-panel" id="panel-decisions">

            <div class="mini-stat-row fade-in d2">
                <div class="mini-stat">
                    <div class="ms-label">Total Evaluations</div>
                    <div class="ms-value"><?= number_format($decision_counts['total']) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">Approved</div>
                    <div class="ms-value" style="color:#059669"><?= number_format($decision_counts['approved']) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">For Compliance</div>
                    <div class="ms-value" style="color:#d97706"><?= number_format($decision_counts['for_compliance']) ?></div>
                </div>
            </div>

            <div class="card fade-in d3">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:var(--indigo)"></span>
                        My Evaluation History
                    </span>
                </div>
                <div class="table-wrap">
                    <?php if (empty($decisions)): ?>
                        <div class="empty-row">No evaluations recorded yet.</div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF</th>
                                    <th>Decision</th>
                                    <th>Comments</th>
                                    <th>Evaluated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisions as $d): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($d['applicant_name']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($d['rsf_number'] ?? '—') ?>
                                            <div class="meta-sub"><?= htmlspecialchars($d['position_title'] ?? '—') ?></div>
                                        </td>
                                        <td>
                                            <span class="status-pill pill-small <?= pillClass($d['decision'] ?? '') ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', ucwords($d['decision'] ?? '—'))) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($d['comments'] !== '' ? $d['comments'] : '—') ?></td>
                                        <td><?= $d['evaluated_at'] ? date('M d, Y g:i A', strtotime($d['evaluated_at'])) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ────────────────────────────────────
             TAB 3: TURNAROUND TIME
        ──────────────────────────────────── -->
        <div class="tab-panel" id="panel-turnaround">

            <div class="mini-stat-row fade-in d2">
                <div class="mini-stat">
                    <div class="ms-label">Avg. Turnaround</div>
                    <div class="ms-value">
                        <?= $avg_turnaround_hours !== null ? number_format($avg_turnaround_hours, 1) . ' hrs' : '—' ?>
                    </div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">Cases Measured</div>
                    <div class="ms-value"><?= number_format(count($turnaround_rows)) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="ms-label">Fastest Case</div>
                    <div class="ms-value">
                        <?php
                        $fastest = null;
                        foreach ($turnaround_rows as $t) {
                            if ($fastest === null || (int)$t['hours_taken'] < $fastest) {
                                $fastest = (int)$t['hours_taken'];
                            }
                        }
                        echo $fastest !== null ? $fastest . ' hrs' : '—';
                        ?>
                    </div>
                </div>
            </div>

            <div class="card fade-in d3">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:#059669"></span>
                        Assignment-to-Decision Time
                    </span>
                </div>
                <div class="table-wrap">
                    <?php if (empty($turnaround_rows)): ?>
                        <div class="empty-row">No completed evaluations to measure yet.</div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF</th>
                                    <th>Assigned</th>
                                    <th>Evaluated</th>
                                    <th>Time Taken</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($turnaround_rows as $t): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($t['applicant_name']) ?></td>
                                        <td><?= htmlspecialchars($t['rsf_number'] ?? '—') ?></td>
                                        <td><?= $t['assigned_at'] ? date('M d, g:i A', strtotime($t['assigned_at'])) : '—' ?></td>
                                        <td><?= $t['evaluated_at'] ? date('M d, g:i A', strtotime($t['evaluated_at'])) : '—' ?></td>
                                        <td>
                                            <strong style="color:var(--indigo)"><?= (int)$t['hours_taken'] ?> hrs</strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ────────────────────────────────────
             TAB 4: COMPLIANCE TRACKER
        ──────────────────────────────────── -->
        <div class="tab-panel" id="panel-compliance">

            <div class="card fade-in d2">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:#d97706"></span>
                        For-Compliance &amp; Resubmission Status
                    </span>
                </div>
                <div class="table-wrap">
                    <?php if (empty($compliance_rows)): ?>
                        <div class="empty-row">No applications have been flagged for compliance.</div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF</th>
                                    <th>Flagged On</th>
                                    <th>Resubmitted</th>
                                    <th>Current Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($compliance_rows as $cr): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($cr['applicant_name']) ?></td>
                                        <td><?= htmlspecialchars($cr['rsf_number'] ?? '—') ?></td>
                                        <td><?= $cr['flagged_at'] ? date('M d, Y', strtotime($cr['flagged_at'])) : '—' ?></td>
                                        <td>
                                            <?php if ($cr['resubmitted_at']): ?>
                                                <?= date('M d, Y', strtotime($cr['resubmitted_at'])) ?>
                                            <?php else: ?>
                                                <span class="pill-small badge-incomplete">Awaiting</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-pill pill-small <?= pillClass($cr['current_status']) ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', ucwords($cr['current_status']))) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ────────────────────────────────────
             TAB 5: DOCUMENT CHECKLIST
        ──────────────────────────────────── -->
        <div class="tab-panel" id="panel-documents">

            <div class="card fade-in d2">
                <div class="card-head">
                    <span class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt)"></span>
                        Document Completeness by Application
                    </span>
                </div>
                <div class="table-wrap">
                    <?php if (empty($doc_checklist)): ?>
                        <div class="empty-row">No active assignments to check documents for.</div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>RSF</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doc_checklist as $dc): ?>
                                    <?php
                                    $required = (int)$dc['required_count'];
                                    $uploaded = (int)$dc['uploaded_count'];
                                    $pct      = $required > 0 ? round(($uploaded / $required) * 100) : 0;
                                    $complete = $required > 0 && $uploaded >= $required;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($dc['applicant_name']) ?></td>
                                        <td><?= htmlspecialchars($dc['rsf_number'] ?? '—') ?></td>
                                        <td>
                                            <div class="doc-progress-wrap">
                                                <div class="doc-progress-bar">
                                                    <div class="doc-progress-fill"
                                                        style="width:<?= $pct ?>%;background:<?= $complete ? '#059669' : '#d97706' ?>">
                                                    </div>
                                                </div>
                                                <span class="doc-progress-text"><?= $uploaded ?>/<?= $required ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="pill-small <?= $complete ? 'badge-complete' : 'badge-incomplete' ?>">
                                                <?= $complete ? 'Complete' : 'Incomplete' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK  (exact dashboard.php structure)
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

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.php" class="dk active">
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

        // Tab switching
        const tabBtns = document.querySelectorAll('.tab-btn');
        const panels = document.querySelectorAll('.tab-panel');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                panels.forEach(p => p.classList.remove('active'));

                btn.classList.add('active');
                document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
            });
        });
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>