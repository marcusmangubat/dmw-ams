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
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$error = '';
$success = '';

// Search box — matches Applicant Name, Gmail (client email), RSF #, Country,
// and Handling (who currently has it: Admin/Evaluator, or the evaluator's name).
$search_filter = trim($_GET['q'] ?? '');

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
                        get_applicant_name($database, $app_id) . "'s application has been resubmitted by the client and returned to your queue."
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
                        get_applicant_name($database, $app_id) . "'s application has been assigned to you for evaluation."
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
        $applicant_name = get_applicant_name($database, $app_id);

        notify_evaluator_for_application(
            $database,
            $app_id,
            'Application Reopened',
            "{$applicant_name}'s application has been reopened by Admin and returned to your queue. Please review the application details."
        );
        notify_client_for_application(
            $database,
            $app_id,
            'Application Under Review Again',
            'Your application has been returned to the evaluator for continued review.'
        );
        $success = "{$applicant_name}'s application has been reopened and sent back to the original evaluator.";
        $success_type = 'reopen';
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

            $applicant_name = get_applicant_name($database, $app_id);

            notify_evaluator_for_application(
                $database,
                $app_id,
                'Application Reassigned to You',
                "{$applicant_name}'s application has been reassigned to you by Admin. Please review the application details."
            );
            notify_client_for_application(
                $database,
                $app_id,
                'Application Reassigned',
                "Your application has been reassigned to evaluator {$ev_name}."
            );
            $success = "{$applicant_name}'s application has been reassigned to {$ev_name}.";
            $success_type = 'reassign';
        } else {
            $error = 'Reassignment failed.';
        }
    } else {
        $error = 'Invalid application or evaluator.';
    }
}
// Pagination settings
$per_page = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Fetch applications with optional status filter
$applications = [];
$sql = "
    SELECT a.id, a.status, a.submitted_at, a.updated_at,
           a.current_evaluator_id, a.handling_party, a.locked_at, a.notes,
           r.rsf_number, r.position_title, r.employer_name, r.worksite_country,
           CONCAT(c.first_name, ' ', c.last_name) as applicant_name, c.email as applicant_email,
           c.profile_picture as applicant_picture,
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
// countSql needs the same joins as $sql (minus the reassign_count subquery,
// which isn't needed just to count rows) so the search filter below can
// match on name/email/rsf/country/handling for the total-pages calculation too.
$countSql = "
    SELECT COUNT(*) as total
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients c ON a.client_id = c.cid
    LEFT JOIN evaluators ev ON a.current_evaluator_id = ev.eid
    WHERE 1=1
";
$params = [];
$types = "";
$valid_single = ['pending', 'resubmitted', 'under_evaluation', 'for_compliance', 'approved', 'disapproved'];
if ($status_filter === 'unassign' || $status_filter === 'unassigned') {
    $sql .= " AND a.status IN ('unassign', 'unassigned')";
    $countSql .= " AND a.status IN ('unassign', 'unassigned')";
} elseif ($status_filter === 'assign' || $status_filter === 'assigned') {
    $sql .= " AND a.status IN ('assign', 'assigned')";
    $countSql .= " AND a.status IN ('assign', 'assigned')";
} elseif ($status_filter && in_array($status_filter, $valid_single, true)) {
    $sql .= " AND a.status = ?";
    $countSql .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Admin search box — Applicant Name, Gmail, RSF, Country, Handling.
if ($search_filter !== '') {
    $searchCond = " AND (
        CONCAT(c.first_name, ' ', c.last_name) LIKE ?
        OR c.email LIKE ?
        OR r.rsf_number LIKE ?
        OR r.worksite_country LIKE ?
        OR a.handling_party LIKE ?
        OR ev.full_name LIKE ?
    )";
    $sql .= $searchCond;
    $countSql .= $searchCond;
    $like = '%' . $search_filter . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= "ssssss";
}

// Total count for this filter, used to compute page count
$total_filtered = 0;
if ($params) {
    $stmt_count = $database->prepare($countSql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt_count->close();
} else {
    $res_count = $database->query($countSql);
    if ($res_count) $total_filtered = (int) ($res_count->fetch_assoc()['total'] ?? 0);
}
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$sql .= " ORDER BY a.updated_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $database->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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

<?php ob_start(); ?>
<?php if (empty($applications)): ?>
    <tr>
        <td colspan="6" class="px-8 py-16 text-center text-[var(--text3)]">
            <i class="fas fa-inbox text-4xl text-[var(--border)] block mb-3"></i>
            <span class="text-sm font-bold text-[var(--text2)] block">No applications found</span>
            <span class="text-xs">
                <?php if ($search_filter !== ''): ?>
                    No results for "<?= htmlspecialchars($search_filter) ?>"<?= $status_filter ? ' in "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?>. Try a different name, Gmail, RSF #, country, or handling.
                <?php else: ?>
                    Try a different filter above.
                <?php endif; ?>
            </span>
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
            <?php
            $app_has_pic = !empty($app['applicant_picture']);
            $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
            $ap_parts = explode(' ', trim($app['applicant_name'] ?? ''));
            $ap_avi = strtoupper(count($ap_parts) >= 2
                ? substr($ap_parts[0], 0, 1) . substr(end($ap_parts), 0, 1)
                : substr($app['applicant_name'] ?? 'NA', 0, 2));
            ?>
            <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5">
                <div class="flex items-center gap-1.5 sm:gap-2.5 lg:gap-3">
                    <div class="flex h-6 w-6 sm:h-7 sm:w-7 lg:h-9 lg:w-9 flex-shrink-0 items-center justify-center rounded-lg lg:rounded-xl bg-[#eef2ff] text-[8px] sm:text-[9.5px] lg:text-[11px] font-extrabold text-[var(--indigo)]"
                        <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                        <?= $app_has_pic ? '' : htmlspecialchars($ap_avi) ?>
                    </div>
                    <div class="min-w-0">
                        <div class="font-medium truncate text-[11px] sm:text-[12.5px] lg:text-sm">
                            <a href="view-application.php?id=<?= (int) $app['id'] ?>" class="hover:underline" style="color:var(--navy);">
                                <?= htmlspecialchars($app['applicant_name'] ?? '—') ?>
                            </a>
                        </div>
                        <div class="text-[9px] sm:text-[10.5px] lg:text-xs text-[var(--text3)] truncate"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                    </div>
                </div>
            </td>
            <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-[11px] sm:text-[12.5px] lg:text-sm">
                <div class="truncate"><?= htmlspecialchars($app['position_title']) ?></div>
                <div class="text-[9px] sm:text-[10.5px] lg:text-xs text-[var(--text3)] truncate"><?= htmlspecialchars($app['rsf_number']) ?></div>
                <div class="text-[9px] sm:text-[10.5px] lg:text-xs text-[var(--text3)] truncate">
                    <?= htmlspecialchars($app['employer_name']) ?><?= !empty($app['worksite_country']) ? ' · ' . htmlspecialchars($app['worksite_country']) : '' ?>
                </div>
            </td>
            <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center">
                <span class="inline-flex items-center gap-1 sm:gap-1.5 px-2 py-1 sm:px-2.5 sm:py-1.5 lg:px-3 rounded-full text-[8.5px] sm:text-[9.5px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide <?= $statusClass ?>"
                    <?php if (!empty($app['notes'])): ?>title="<?= htmlspecialchars($app['notes'], ENT_QUOTES) ?>" <?php endif; ?>>
                    <i class="fas <?= $statusIcon ?> text-[7.5px] sm:text-[9px]"></i><?= htmlspecialchars(str_replace('_', ' ', ucfirst($app['status']))) ?>
                </span>
            </td>
            <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-[9.5px] sm:text-[11px] lg:text-xs">
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
            <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-[9.5px] sm:text-[11px] lg:text-xs text-[var(--text3)]">
                <div>Sub: <?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
                <div>Upd: <?= date('M d, Y', strtotime($app['updated_at'])) ?></div>
            </td>
            <td class="px-2 py-2 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5">
                <?php if (in_array($app['status'], ['unassign', 'unassigned'], true) && !empty($evaluators)): ?>
                    <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap">
                        <a href="view-application.php?id=<?= (int) $app['id'] ?>"
                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition inline-block whitespace-nowrap">
                            <i class="fas fa-eye"></i> <span class="hidden sm:inline">View</span>
                        </a>
                        <form method="POST" class="flex items-center gap-1.5 sm:gap-2">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                            <select name="evaluator_id" required
                                class="border border-[var(--border)] bg-white rounded-lg sm:rounded-xl px-2 py-1.5 sm:px-3 sm:py-2 text-[10px] sm:text-[11px] lg:text-xs focus:outline-none focus:border-[var(--blue-lt)] max-w-[110px] sm:max-w-none">
                                <option value="">Select evaluator...</option>
                                <?php foreach ($evaluators as $ev): ?>
                                    <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit"
                                class="bg-[var(--blue)] hover:bg-[#1e40af] hover:-translate-y-0.5 text-white px-2.5 py-1.5 sm:px-4 sm:py-2 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition whitespace-nowrap">
                                Assign
                            </button>
                        </form>
                    </div>
                <?php elseif (in_array($app['status'], ['for_compliance', 'resubmitted'], true)): ?>
                    <div class="flex flex-col gap-2">
                        <?php if (!empty($app['current_evaluator_name'])): ?>
                            <div class="text-xs text-[var(--text3)]">
                                Owner on record: <span class="font-semibold text-[var(--navy)]"><?= htmlspecialchars($app['current_evaluator_name']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap">
                            <?php if (!empty($app['current_evaluator_id'])): ?>
                                <form method="POST" id="reopenForm<?= (int) $app['id'] ?>">
                                    <input type="hidden" name="action" value="reopen">
                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                    <button type="button"
                                        data-app-id="<?= (int) $app['id'] ?>"
                                        data-evaluator-name="<?= htmlspecialchars($app['current_evaluator_name'] ?? 'the evaluator', ENT_QUOTES) ?>"
                                        onclick="confirmReopen(this)"
                                        class="bg-emerald-600 hover:bg-emerald-700 hover:-translate-y-0.5 text-white px-2 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition whitespace-nowrap">
                                        <i class="fas fa-rotate-left"></i> <span class="hidden sm:inline">Reopen</span>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <a href="view-application.php?id=<?= (int) $app['id'] ?>"
                                class="bg-[var(--navy)] hover:bg-[#0b1f4b] hover:-translate-y-0.5 text-white px-2 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition inline-block whitespace-nowrap">
                                <i class="fas fa-gavel"></i> <span class="hidden sm:inline">Evaluate Directly</span>
                            </a>

                            <?php if (!empty($evaluators)): ?>
                                <details class="relative">
                                    <summary class="cursor-pointer list-none bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition inline-block whitespace-nowrap">
                                        <i class="fas fa-user-pen"></i> <span class="hidden sm:inline">Reassign</span>
                                    </summary>
                                    <form method="POST" class="mt-2 flex items-center gap-1.5 sm:gap-2">
                                        <input type="hidden" name="action" value="reassign">
                                        <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                        <select name="evaluator_id" required oninvalid="this.setCustomValidity('Please select an evaluator from the list.')" oninput="this.setCustomValidity('')"
                                            class="border border-[var(--border)] bg-white rounded-lg sm:rounded-xl px-2 py-1.5 text-[10px] sm:text-[11px] lg:text-xs focus:outline-none focus:border-[var(--blue-lt)] max-w-[110px] sm:max-w-none">
                                            <option value="">Select evaluator...</option>
                                            <?php foreach ($evaluators as $ev): ?>
                                                <option value="<?= $ev['eid'] ?>"><?= htmlspecialchars($ev['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit"
                                            class="bg-amber-500 hover:bg-amber-600 text-white px-2.5 py-1.5 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition whitespace-nowrap">
                                            Go
                                        </button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="view-application.php?id=<?= (int) $app['id'] ?>"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl text-[10px] sm:text-[11px] lg:text-xs font-semibold transition inline-block whitespace-nowrap">
                        <i class="fas fa-eye"></i> <span class="hidden sm:inline">View</span>
                    </a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
<?php $tbody_html = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between px-6 py-4 border-t border-[var(--border)] text-sm">
        <span class="text-[var(--text3)]">Page <?= $page ?> of <?= $total_pages ?></span>
        <div class="flex items-center gap-1.5">
            <?php
            $qs = ($status_filter ? '&status=' . urlencode($status_filter) : '')
                . ($search_filter !== '' ? '&q=' . urlencode($search_filter) : '');
            for ($p = 1; $p <= $total_pages; $p++):
            ?>
                <a href="applications.php?page=<?= $p ?><?= $qs ?>"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-semibold transition <?= $p === $page ? 'bg-[var(--blue)] text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
<?php endif; ?>
<?php $pagination_html = ob_get_clean(); ?>

<?php
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'tbody' => $tbody_html,
        'pagination' => $pagination_html,
        'total' => $total_filtered,
        'page' => $page,
        'total_pages' => $total_pages,
        'heading' => ($status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications')
            . ($search_filter !== '' ? ' · matching "' . $search_filter . '"' : ''),
        'success' => $success,
        'error' => $error,
        'success_type' => $success_type ?? null,
        'stats' => array_merge($stats, ['all' => $total_all]),
    ]);
    exit;
}
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
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
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
        <div class="subbar-top flex items-center justify-between gap-4 flex-wrap">
            <div class="subbar-greeting">
                <h2>Applications Management</h2>
                <p><span id="totalCount"><?= $total_filtered ?></span> Total Applications &nbsp;|&nbsp; Page <span id="currentPage"><?= $page ?></span> of <span id="totalPages"><?= $total_pages ?></span> &nbsp;|&nbsp;
                    <?= $status_filter ? '• ' . ucfirst(str_replace('_', ' ', $status_filter)) : '• All Applications' ?></p>
            </div>
        </div>
        <div class="subbar-bottom flex items-center gap-4">
            <a href="dashboard.php" class="filter-pill ml-auto flex-shrink-0" style="text-decoration:none">
                <i class="fas fa-house" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Back to Dashboard</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Search (live — filters the list + KPI-scoped table as you type, no page reload) -->
        <div class="card fade-in rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden" style="animation-delay:.1s">
            <div class="card-head flex items-center justify-between gap-4 flex-wrap">
                <div class="card-title">
                    <span class="ctdot" style="background:var(--indigo)"></span>
                    <span id="cardHeading"><?= $status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications' ?></span>
                </div>

                <div class="relative max-w-2xl w-full sm:w-auto sm:min-w-[320px]">
                    <i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-[13px] text-[var(--text3)]"></i>
                    <input
                        type="text"
                        id="app-search-input"
                        value="<?= htmlspecialchars($search_filter) ?>"
                        placeholder="Search by applicant name, Gmail, RSF #, country, or handling…"
                        autocomplete="off"
                        class="w-full rounded-2xl border border-[var(--border)] bg-white py-3 pl-11 pr-11 text-[13px] font-medium text-[var(--text1)] outline-none transition focus:border-[var(--navy)] focus:ring-1 focus:ring-[var(--navy)]">
                    <button type="button" id="app-search-clear"
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 text-[12px] text-[var(--text3)] hover:text-[var(--text1)]"
                        title="Clear search" style="<?= $search_filter === '' ? 'display:none' : '' ?>">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- KPI FILTER CARDS -->
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 sm:gap-3 mb-8">
                <?php foreach ($kpi_defs as $i => $kpi):
                    $is_active = ($kpi['key'] === '' && $status_filter === '')
                        || ($kpi['key'] !== '' && $status_filter === $kpi['key'])
                        || ($kpi['key'] === 'unassign' && in_array($status_filter, ['unassign', 'unassigned'], true));
                    $delay = .05 + ($i * .03);
                ?>
                    <?php
                    $kpi_qs = $kpi['key'] ? '?status=' . urlencode($kpi['key']) : '';
                    if ($search_filter !== '') {
                        $kpi_qs .= ($kpi_qs ? '&' : '?') . 'q=' . urlencode($search_filter);
                    }
                    ?>
                    <a href="applications.php<?= $kpi_qs ?>"
                        class="kpi-card fade-in group relative overflow-hidden rounded-xl sm:rounded-2xl <?= $is_active ? 'border-2 ring-1' : 'border' ?> bg-white p-2.5 sm:p-3 md:p-4 flex flex-col justify-between gap-1 sm:gap-1.5 md:gap-2 no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                        style="animation-delay:<?= $delay ?>s;
           border-color:<?= $is_active ? $kpi['color'] : 'var(--border)' ?>;<?= $is_active ? '--tw-ring-color:' . $kpi['color'] . ';' : '' ?>">
                        <span class="flex items-center gap-1 sm:gap-1.5 text-[9px] sm:text-[10.5px] md:text-xs font-bold uppercase tracking-tight sm:tracking-wide md:tracking-widest text-gray-500 truncate">
                            <i class="fas <?= $kpi['icon'] ?> text-[9px] sm:text-xs" style="color:<?= $kpi['color'] ?>"></i>
                            <span class="truncate"><?= $kpi['label'] ?></span>
                        </span>
                        <span class="text-lg sm:text-xl md:text-3xl font-extrabold text-gray-900" id="kpi-count-<?= $kpi['key'] !== '' ? $kpi['key'] : 'all' ?>">
                            <?= $kpi['count'] ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($error): ?>
                <div class="fade-in flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-2xl mb-6 text-sm">
                    <i class="fas fa-circle-exclamation text-red-500"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>



            <div class="card fade-in rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden" style="animation-delay:.1s">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--indigo)"></span>
                        <span id="cardHeading"><?= $status_filter ? ucfirst(str_replace('_', ' ', $status_filter)) . ' Applications' : 'All Applications' ?></span>
                    </div>
                </div>

                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full min-w-[640px] sm:min-w-[820px] lg:min-w-[1150px] table-fixed">
                        <colgroup>
                            <col class="w-[140px] sm:w-[180px] lg:w-[220px]">
                            <col class="w-[150px] sm:w-[190px] lg:w-[240px]">
                            <col class="w-[90px] sm:w-[110px] lg:w-[140px]">
                            <col class="w-[110px] sm:w-[135px] lg:w-[170px]">
                            <col class="w-[100px] sm:w-[120px] lg:w-[150px]">
                            <col class="w-[150px] sm:w-[185px] lg:w-[230px]">
                        </colgroup>
                        <thead>
                            <tr class="bg-[var(--surface)] border-b border-[var(--border)]">
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Applicant</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Position / RSF</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Status</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Handling</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Submitted / Updated</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[10.5px] font-bold uppercase tracking-tight sm:tracking-wide text-[var(--text3)]">Action</th>
                            </tr>
                        </thead>
                        <tbody id="appsTbody" class="divide-y divide-[var(--border)] text-sm">
                            <?= $tbody_html ?>
                        </tbody>
                    </table>
                </div>
                <div id="paginationWrap">
                    <?= $pagination_html ?>
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
                <!-- <a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>-->
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

        <script>
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
                        submitReopenForm(appId);
                    }
                });
            }

            function updateKpiCounts(stats) {
                if (!stats) return;
                Object.keys(stats).forEach(key => {
                    const el = document.getElementById('kpi-count-' + key);
                    if (el) el.textContent = stats[key];
                });
            }

            async function submitReopenForm(appId) {
                const form = document.getElementById('reopenForm' + appId);
                const params = new URLSearchParams(window.location.search);
                params.set('ajax', '1');

                try {
                    const res = await fetch('applications.php?' + params.toString(), {
                        method: 'POST',
                        body: new FormData(form)
                    });
                    const data = await res.json();

                    document.getElementById('appsTbody').innerHTML = data.tbody;
                    document.getElementById('paginationWrap').innerHTML = data.pagination;
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('currentPage').textContent = data.page;
                    document.getElementById('totalPages').textContent = data.total_pages;
                    document.getElementById('cardHeading').textContent = data.heading;
                    updateKpiCounts(data.stats);

                    if (data.success_type === 'reopen' && data.success) {
                        Swal.fire({
                            title: 'Reopened!',
                            text: data.success,
                            icon: 'success',
                            confirmButtonColor: '#059669'
                        });
                    } else if (data.error) {
                        Swal.fire({
                            title: 'Reopen failed',
                            text: data.error,
                            icon: 'error',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                } catch (err) {
                    Swal.fire({
                        title: 'Something went wrong',
                        text: 'Please try again.',
                        icon: 'error'
                    });
                }
            }

            document.addEventListener('submit', async function(e) {
                const form = e.target.closest('form');
                if (!form) return;
                const actionInput = form.querySelector('input[name="action"]');
                if (!actionInput || actionInput.value !== 'reassign') return;

                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                const params = new URLSearchParams(window.location.search);
                params.set('ajax', '1');

                try {
                    const res = await fetch('applications.php?' + params.toString(), {
                        method: 'POST',
                        body: new FormData(form)
                    });
                    const data = await res.json();

                    document.getElementById('appsTbody').innerHTML = data.tbody;
                    document.getElementById('paginationWrap').innerHTML = data.pagination;
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('currentPage').textContent = data.page;
                    document.getElementById('totalPages').textContent = data.total_pages;
                    document.getElementById('cardHeading').textContent = data.heading;
                    updateKpiCounts(data.stats);

                    if (data.success_type === 'reassign' && data.success) {
                        Swal.fire({
                            title: 'Reassigned!',
                            text: data.success,
                            icon: 'success',
                            confirmButtonColor: '#d97706'
                        });
                    } else if (data.error) {
                        Swal.fire({
                            title: 'Reassignment failed',
                            text: data.error,
                            icon: 'error',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                } catch (err) {
                    Swal.fire({
                        title: 'Something went wrong',
                        text: 'Please try again.',
                        icon: 'error'
                    });
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        </script>
        <script type="module" src="../js/main.js"></script>
        <script>
            // No-refresh pagination: fetches just the table rows + pagination
            // strip via the ?ajax=1 JSON branch above, and swaps them in place.
            async function loadAppsPage(url) {
                const target = new URL(url, window.location.href);
                target.searchParams.set('ajax', '1');

                const tbody = document.getElementById('appsTbody');
                tbody.style.opacity = '0.5';

                try {
                    const res = await fetch(target.toString());
                    const data = await res.json();

                    document.getElementById('appsTbody').innerHTML = data.tbody;
                    document.getElementById('paginationWrap').innerHTML = data.pagination;
                    document.getElementById('totalCount').textContent = data.total;
                    document.getElementById('currentPage').textContent = data.page;
                    document.getElementById('totalPages').textContent = data.total_pages;
                    document.getElementById('cardHeading').textContent = data.heading;

                    const cleanUrl = new URL(url, window.location.href);
                    cleanUrl.searchParams.delete('ajax');
                    history.pushState({}, '', cleanUrl);
                } catch (e) {
                    window.location.href = url; // fallback to a normal navigation on failure
                } finally {
                    tbody.style.opacity = '1';
                }
            }
            document.addEventListener('click', function(e) {
                const link = e.target.closest('#paginationWrap a');
                if (link) {
                    e.preventDefault();
                    loadAppsPage(link.getAttribute('href'));
                }
            });

            window.addEventListener('popstate', function() {
                loadAppsPage(window.location.href);
            });

            // Live search — same no-refresh path as pagination, debounced as
            // the admin types. Preserves the current status filter, always
            // resets to page 1 since the result set changes.
            const searchInput = document.getElementById('app-search-input');
            const searchClearBtn = document.getElementById('app-search-clear');
            let searchDebounce = null;

            function currentStatusFilter() {
                return new URL(window.location.href).searchParams.get('status') || '';
            }

            function runAppsSearch() {
                const term = searchInput.value.trim();
                searchClearBtn.style.display = term ? '' : 'none';

                const params = new URLSearchParams();
                const status = currentStatusFilter();
                if (status) params.set('status', status);
                if (term) params.set('q', term);
                params.set('page', '1');

                loadAppsPage('applications.php?' + params.toString());
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    searchClearBtn.style.display = searchInput.value ? '' : 'none';
                    clearTimeout(searchDebounce);
                    searchDebounce = setTimeout(runAppsSearch, 300);
                });
            }

            if (searchClearBtn) {
                searchClearBtn.addEventListener('click', () => {
                    searchInput.value = '';
                    searchClearBtn.style.display = 'none';
                    clearTimeout(searchDebounce);
                    runAppsSearch();
                });
            }
        </script>
        <script>
            (function() {
                /* Wraps the Reassign form's native <select name="evaluator_id">
                   with a styled trigger + dropdown panel. The panel is appended
                   to <body> and positioned `fixed` (computed from the trigger's
                   on-screen position) with a very high z-index, so it always
                   renders above the floating dock / sticky sidebar instead of
                   getting hidden behind them by a parent stacking context.

                   The original <select> stays in the form (hidden) with its
                   `name` intact, so the surrounding <form method="POST"> still
                   submits exactly the same field — no PHP changes needed.

                   required-note: this select had `required`, but browsers skip
                   required-validation on hidden elements, so that check is
                   replaced with a manual one on form submit. */
                const select = document.querySelector('select[name="evaluator_id"]');
                if (!select) return;

                const form = select.closest('form');
                const placeholderOpt = select.querySelector('option[value=""]');
                const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

                const wrapper = document.createElement('div');
                wrapper.style.cssText = 'position:relative;flex:1;min-width:180px;';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.style.display = 'none';
                select.tabIndex = -1;
                select.removeAttribute('required'); // replaced by manual check below

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.style.cssText = 'width:100%;border:1px solid var(--border);background:#fff;border-radius:10px;padding:9px 12px;font-size:12.5px;color:var(--text1);display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:border-color .15s;';
                trigger.innerHTML = '<span class="rs-trigger-label" style="color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>' +
                    '<i class="fas fa-chevron-down" style="font-size:10px;color:var(--text3);transition:transform .15s;flex-shrink:0;"></i>';
                wrapper.appendChild(trigger);

                // ── Appended to <body>, positioned fixed, very high z-index ──
                const panel = document.createElement('div');
                panel.setAttribute('role', 'listbox');
                panel.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 30px rgba(11,31,75,.2);z-index:99999;overflow:hidden;padding:4px 0;max-height:220px;overflow-y:auto;';
                document.body.appendChild(panel);

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;font-size:12.5px;color:var(--text1);cursor:pointer;';
                    row.dataset.value = optionEl.value;
                    row.addEventListener('mouseenter', () => row.style.background = '#f8fafc');
                    row.addEventListener('mouseleave', () => row.style.background = row.dataset.selected === '1' ? '#eff6ff' : '');
                    const label = document.createElement('span');
                    label.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                    label.textContent = optionEl.textContent;
                    row.appendChild(label);
                    row.addEventListener('click', () => {
                        select.value = (select.value === optionEl.value) ? '' : optionEl.value;
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
                    Array.from(select.options).forEach(opt => {
                        if (opt.value === '') return; // "Select evaluator..." is a true placeholder here
                        panel.appendChild(buildRow(opt));
                    });
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
                            icon.style.cssText = 'font-size:10px;color:var(--blue);flex-shrink:0;';
                            row.appendChild(icon);
                        } else if (!selected && check) {
                            check.remove();
                        }
                    });
                }

                function refresh() {
                    const opt = select.options[select.selectedIndex];
                    const label = trigger.querySelector('.rs-trigger-label');
                    if (select.value === '' || !opt) {
                        label.textContent = placeholder;
                        label.style.color = 'var(--text3)';
                        label.style.fontWeight = '400';
                    } else {
                        label.textContent = opt.textContent;
                        label.style.color = 'var(--text1)';
                        label.style.fontWeight = '600';
                    }
                    trigger.style.borderColor = 'var(--border)';
                    markSelected();
                }

                function positionPanel() {
                    const rect = trigger.getBoundingClientRect();
                    panel.style.width = rect.width + 'px';
                    panel.style.top = (rect.bottom + 6) + 'px';
                    let left = rect.left;
                    const panelWidth = rect.width;
                    const maxLeft = window.innerWidth - panelWidth - 8;
                    if (left > maxLeft) left = Math.max(8, maxLeft);
                    panel.style.left = left + 'px';
                }

                function onReposition() {
                    if (panel.style.display === 'block') positionPanel();
                }

                function openPanel() {
                    document.querySelectorAll('[role="listbox"]').forEach(p => {
                        if (p !== panel) p.style.display = 'none';
                    });
                    renderList();
                    positionPanel();
                    panel.style.display = 'block';
                    trigger.style.borderColor = 'var(--blue-lt, #3b82f6)';
                    trigger.setAttribute('aria-expanded', 'true');
                    trigger.querySelector('.fa-chevron-down').style.transform = 'rotate(180deg)';
                    window.addEventListener('scroll', onReposition, true);
                    window.addEventListener('resize', onReposition);
                }

                function closePanel() {
                    panel.style.display = 'none';
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.querySelector('.fa-chevron-down').style.transform = '';
                    window.removeEventListener('scroll', onReposition, true);
                    window.removeEventListener('resize', onReposition);
                }

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    panel.style.display === 'block' ? closePanel() : openPanel();
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

                // Manual required-check, since the hidden native <select> is
                // exempt from HTML5 constraint validation.
                if (form) {
                    form.addEventListener('submit', (e) => {
                        if (select.value === '') {
                            e.preventDefault();
                            trigger.style.borderColor = '#dc2626';
                            openPanel();
                            Swal.fire({
                                title: 'Select an evaluator',
                                text: 'Please choose an evaluator to reassign this case to.',
                                icon: 'warning',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    });
                }
            })();
        </script>
</body>

</html>