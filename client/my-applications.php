<?php

/**
 * Client: My Applications — Refactored Tab-Based Layout
 *  all stages displayed within a tab-based layout,
 * organized in a 'for each' format for every application.
 */
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}
date_default_timezone_set('Asia/Manila');
/* ══════════════════════════════════════════════════════════════════════════════
   HELPERS  (mirrors my-applications.php originals)
══════════════════════════════════════════════════════════════════════════════ */

function myapp_requirement_lines(array $job): array
{
    $lines = [];
    $q = trim((string) ($job['qualifications'] ?? ''));
    if ($q !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $q) as $line) {
            $line = trim(preg_replace('/^[➢\-\*\d\.\)\s]+/u', '', $line));
            if ($line !== '') $lines[] = $line;
        }
    }
    if ($lines === []) {
        $lines[] = 'I confirm that I meet the qualifications and requirements described in the official RSF for this position.';
    }
    $lines[] = 'I confirm that I have read and understood the RSF details (including the PDF, if provided) for this position.';
    $lines[] = 'I confirm that the information I provide in this application is true and complete to the best of my knowledge.';
    return $lines;
}

function myapp_required_documents_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, document_type, document_name, is_required, description FROM rsf_required_documents WHERE rsf_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $docs = [];
    foreach ($rows as $r) {
        if (empty($r['document_type']) || empty($r['document_name'])) continue;
        $docs[] = ['id' => (int)$r['id'], 'document_type' => (string)$r['document_type'], 'document_name' => (string)$r['document_name'], 'is_required' => (int)($r['is_required'] ?? 1), 'description' => (string)($r['description'] ?? '')];
    }
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport',    'document_name' => 'Passport (valid)',      'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume',       'document_name' => 'Resume / CV',           'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma',      'document_name' => 'Diploma / TOR',         'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate',  'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }
    return $docs;
}

function myapp_questions_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id = ? ORDER BY COALESCE(order_no, 999), id');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows === []) {
        $res  = $db->query('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NULL ORDER BY COALESCE(order_no, 999), id');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    return $rows;
}

function myapp_load_answers(mysqli $db, int $app_id): array
{
    $map  = [];
    $stmt = $db->prepare("SELECT question_id, answer FROM application_answers WHERE application_id = ?");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $r) $map[(int)$r['question_id']] = $r['answer'];
    return $map;
}

function myapp_load_docs(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_status_history(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("SELECT from_status, to_status, changed_by, reason, changed_at FROM status_history WHERE application_id = ? ORDER BY changed_at ASC");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_doc_comments(mysqli $db, int $app_id): array
{
    $stmt = $db->prepare("
        SELECT dc.comment, dc.commented_by, dc.created_at,
               ad.document_type, w.usertype AS commenter_type
        FROM document_comments dc
        JOIN application_documents ad ON dc.document_id = ad.id
        LEFT JOIN webuser w ON w.email = dc.commented_by
        WHERE ad.application_id = ?
        ORDER BY dc.created_at ASC
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function myapp_load_eval_comments(mysqli $db, int $app_id): string
{
    $stmt = $db->prepare("SELECT comments FROM evaluations WHERE application_id = ? ORDER BY evaluated_at DESC LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($r && trim((string)($r['comments'] ?? '')) !== '') ? trim($r['comments']) : '';
}

function myapp_is_assigned(mysqli $db, int $app_id): bool
{
    $stmt = $db->prepare("SELECT id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$r;
}

function myapp_relative_time(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) {
        $d = (int) floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 31536000) {
        $mo = (int) floor($diff / 2592000);
        return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
    }
    $y = (int) floor($diff / 31536000);
    return $y . ' year' . ($y === 1 ? '' : 's') . ' ago';
}

function myapp_status_info(string $status): array
{
    return match (true) {
        $status === 'approved' => ['pill' => 'approved', 'icon' => 'fa-circle-check', 'label' => 'Approved', 'color' => '#10b981'],
        $status === 'under_evaluation' => ['pill' => 'evaluating', 'icon' => 'fa-user-check', 'label' => 'Under Evaluation', 'color' => '#6366f1'],
        $status === 'for_compliance' => ['pill' => 'compliance', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance', 'color' => '#f59e0b'],
        $status === 'disapproved' => ['pill' => 'disapproved', 'icon' => 'fa-circle-xmark', 'label' => 'Disapproved', 'color' => '#ef4444'],
        $status === 'resubmitted' => ['pill' => 'resubmitted', 'icon' => 'fa-rotate-right', 'label' => 'Resubmitted', 'color' => '#0ea5e9'],
        $status === 'pending' => ['pill' => 'pending', 'icon' => 'fa-clock', 'label' => 'Pending Evaluator Review', 'color' => '#64748b'],
        default => ['pill' => 'pending', 'icon' => 'fa-file-lines', 'label' => ucwords(str_replace('_', ' ', $status)), 'color' => '#94a3b8'],
    };
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOAD CLIENT
══════════════════════════════════════════════════════════════════════════════ */

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT cid, profile_picture, CONCAT(first_name, ' ', last_name) AS full_name FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$client) {
    header('Location: ../login.php');
    exit;
}
$client_id = (int)$client['cid'];
$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;
$open_app_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

/* ══════════════════════════════════════════════════════════════════════════════
   WIZARD STATE
══════════════════════════════════════════════════════════════════════════════ */

$show_wizard       = false;
$wizard_job        = null;
$wizard_job_id     = (int)($_GET['job_id'] ?? 0);
$wizard_url_step   = max(1, (int)($_GET['step'] ?? 1));
$wizard_block_msg  = '';
$wizard_display_step = 1;

if ($wizard_job_id > 0) {
    $stmt = $database->prepare('SELECT * FROM rsf_job_orders WHERE id = ? AND slots_available > 0 AND (deadline IS NULL OR deadline >= CURDATE()) LIMIT 1');
    $stmt->bind_param('i', $wizard_job_id);
    $stmt->execute();
    $wizard_job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$wizard_job) {
        $wizard_block_msg = 'This job is no longer available (closed, deadline passed, or no slots left).';
    } else {
        $stmt = $database->prepare('SELECT id FROM applications WHERE client_id = ? AND rsf_id = ? LIMIT 1');
        $stmt->bind_param('ii', $client_id, $wizard_job_id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            $wizard_block_msg = 'You have already applied for this position.';
        } else {
            $show_wizard = true;
            if (!isset($_SESSION['job_id']) || (int)$_SESSION['job_id'] !== $wizard_job_id) {
                unset($_SESSION['app_step'], $_SESSION['app_answers'], $_SESSION['app_checklist_ok'], $_SESSION['app_rsf_seen'], $_SESSION['wizard_uploads'], $_SESSION['app_uploads_ok']);
                $_SESSION['job_id']   = $wizard_job_id;
                $_SESSION['app_step'] = 1;
            }
            $sess_step = max(1, (int)($_SESSION['app_step'] ?? 1));
            if ($wizard_url_step > $sess_step) {
                header("Location: my-applications.php?job_id={$wizard_job_id}&step={$sess_step}");
                exit;
            }
            $wizard_display_step = $wizard_url_step;
            if ($wizard_display_step > 4) {
                header("Location: my-applications.php?job_id={$wizard_job_id}&step=4");
                exit;
            }
            if ($wizard_display_step === 4 && ((int)($_SESSION['app_step'] ?? 0) < 4 || empty($_SESSION['app_uploads_ok']))) {
                $st = max(1, min(3, (int)($_SESSION['app_step'] ?? 1)));
                header("Location: my-applications.php?job_id={$wizard_job_id}&step={$st}");
                exit;
            }
        }
    }
}

$wizard_questions = [];
if ($show_wizard) $wizard_questions = myapp_questions_for_job($database, $wizard_job_id);

/* ══════════════════════════════════════════════════════════════════════════════
   LOAD ALL APPLICATIONS  (with extended detail for tabs)
══════════════════════════════════════════════════════════════════════════════ */

$applications = [];
$open_app_idx = null;

if (!$show_wizard) {
    $stmt = $database->prepare("
        SELECT a.id, a.rsf_id, a.status, a.submitted_at, a.updated_at, a.notes,
               r.rsf_number, r.position_title, r.employer_name, r.worksite_country,
               r.classification, r.qualifications, r.pdf_path, r.slots_available, r.deadline,
               (SELECT e.comments FROM evaluations e WHERE e.application_id = a.id ORDER BY e.evaluated_at DESC LIMIT 1) AS last_eval_comments
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.client_id = ?
        ORDER BY a.updated_at DESC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // For each application, pre-load everything needed for tabs
    foreach ($applications as &$app) {
        $aid           = (int)$app['id'];
        $rsf_id        = (int)$app['rsf_id'];
        $app['questions']       = myapp_questions_for_job($database, $rsf_id);
        $app['answers_map']     = myapp_load_answers($database, $aid);
        $app['required_docs']   = myapp_required_documents_for_job($database, $rsf_id);
        $app['uploaded_docs']   = myapp_load_docs($database, $aid);
        $app['status_history']  = myapp_load_status_history($database, $aid);
        $app['doc_comments'] = myapp_load_doc_comments($database, $aid);

        // Merge status changes + document comments into one chronological timeline
        $events = [];
        foreach ($app['status_history'] as $sh) {
            $events[] = ['type' => 'status', 'ts' => strtotime($sh['changed_at']), 'data' => $sh];
        }
        foreach ($app['doc_comments'] as $dc) {
            $events[] = ['type' => 'comment', 'ts' => strtotime($dc['created_at']), 'data' => $dc];
        }
        usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $app['timeline_events'] = $events;
        $app['eval_comments']   = myapp_load_eval_comments($database, $aid);
        $app['is_assigned']     = myapp_is_assigned($database, $aid);
        $app['req_lines']       = myapp_requirement_lines($app);
        $app['status_info']     = myapp_status_info($app['status']);
        // Build uploaded doc lookup
        $app['uploaded_by_req']  = [];
        $app['uploaded_by_type'] = [];
        foreach ($app['uploaded_docs'] as $d) {
            $rid = (int)($d['required_doc_id'] ?? 0);
            if ($rid > 0) $app['uploaded_by_req'][$rid]              = $d;
            else          $app['uploaded_by_type'][$d['document_type']] = $d;
        }
    }
    unset($app);
    $total     = count($applications);
    $app_count = $total;

    $open_app_idx = null;
    if ($open_app_id > 0) {
        foreach ($applications as $i => $a) {
            if ((int)$a['id'] === $open_app_id) {
                $open_app_idx = $i;
                break;
            }
        }
    }
} else {
    // Wizard is active — skip the heavy per-application loading entirely.
    // Just get a cheap count for the dock badge.
    $stmt = $database->prepare("SELECT COUNT(*) AS c FROM applications WHERE client_id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $app_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    $total = 0;
}


$submitted = isset($_GET['submitted']);

// Initials
$initials = '';
foreach (explode(' ', trim($client['full_name'] ?? 'Client')) as $w) {
    $initials .= strtoupper($w[0] ?? '');
    if (strlen($initials) >= 2) break;
}

// If only one application, auto-select it (skip the list picker)
$single_mode = ($total === 1 && !$show_wizard);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Applications – DMW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        /* ── Application Picker (multi-app list) ── */
        .app-picker-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .app-picker-item {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s, transform .15s;
            text-decoration: none;
        }

        .app-picker-item:hover {
            border-color: var(--blue-lt);
            box-shadow: 0 4px 16px rgba(59, 130, 246, .13);
            transform: translateY(-1px);
        }

        .app-picker-item.active {
            border-color: var(--blue-lt);
            background: #eff6ff;
        }

        .app-picker-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            color: #fff;
        }

        .app-picker-info {
            flex: 1;
            min-width: 0;
        }

        .app-picker-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-picker-meta {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-picker-date {
            font-size: 11px;
            color: var(--text3);
            margin-top: 3px;
        }

        .app-picker-arrow {
            font-size: 11px;
            color: var(--text3);
            flex-shrink: 0;
        }

        /* ── Status pill ── */
        .status-pill {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 4px 10px;
            border-radius: 99px;
            white-space: nowrap;
            flex-shrink: 0;
            text-transform: capitalize;
        }

        .status-pill.pending {
            background: #f1f5f9;
            color: var(--text2);
        }

        .status-pill.approved {
            background: #ecfdf5;
            color: var(--green);
        }

        .status-pill.evaluating {
            background: #eef2ff;
            color: var(--indigo);
        }

        .status-pill.compliance {
            background: #fffbeb;
            color: var(--amber);
        }

        .status-pill.disapproved {
            background: #fef2f2;
            color: var(--red);
        }

        .status-pill.resubmitted {
            background: #e0f2fe;
            color: #0369a1;
        }

        /* ── Application Detail Panel ── */
        .app-detail-panel {
            display: none;
        }

        .app-detail-panel.active {
            display: block;
            animation: fadeUp .25s ease;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Detail header breadcrumb ── */
        .detail-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px 0;
            margin-bottom: 14px;
        }

        .detail-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--blue-lt);
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            flex-shrink: 0;
        }

        .detail-back-btn:hover {
            background: #dbeafe;
        }

        .detail-job-name {
            font-size: 14px;
            font-weight: 800;
            color: var(--text1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .detail-job-meta {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 1px;
        }

        /* ── Two-part Tab System ── */
        .two-part-wrapper {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        /* Part labels */
        .part-label-bar {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: #f8fafc;
        }

        .part-segment {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px 0 6px;
        }

        .part-segment+.part-segment {
            border-left: 1px solid var(--border);
        }

        .part-segment-num {
            font-size: 8.5px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 1px;
        }

        .part-segment-name {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
        }

        .part-segment.part-active .part-segment-name {
            color: var(--blue-lt);
        }

        .part-segment.part-active .part-segment-num {
            color: var(--blue-lt);
        }

        /* Tab row */
        .app-tabs {
            display: flex;
            width: 100%;
            overflow-x: hidden;
            /* was auto — no longer needed once tabs stretch */
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .app-tabs::-webkit-scrollbar {
            display: none;
        }

        .atab {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            flex: 1 1 0;
            /* was flex-shrink:0 — now grows/shrinks equally, fills row */
            min-width: 0;
            /* allows text to actually shrink/ellipsis instead of overflowing */
            padding: 10px 8px;
            border-bottom: 2.5px solid transparent;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3);
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color .18s, border-color .18s, background .18s;
            font-family: 'DM Sans', sans-serif;
            background: none;
            border-left: none;
            border-right: none;
            border-top: none;
        }

        .atab i {
            font-size: 13px;
        }

        .atab:hover {
            color: var(--blue);
            background: #f0f4ff;
        }

        .atab.active {
            color: var(--blue);
            border-bottom-color: var(--blue-lt);
        }

        .atab.done {
            color: var(--green);
            border-bottom-color: #22c55e;
            background: #ecfdf5;
        }

        .atab.done:hover {
            color: var(--green);
            background: #dcfce7;
        }

        .atab:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        @media (max-width: 480px) {
            .atab {
                padding: 8px 4px;
                font-size: 8px;
                gap: 2px;
            }

            .atab i {
                font-size: 11px;
            }
        }

        @media (max-width: 360px) {

            .atab span,
            .atab {
                font-size: 0;
                /* hide text label */
            }

            .atab i {
                font-size: 14px;
            }
        }

        /* Part divider tab */
        .atab-divider {
            width: 1px;
            flex: 0 0 auto;
            background: var(--border);
            margin: 6px 0;
        }

        /* Tab content */
        .atab-content {
            display: none;
        }

        .atab-content.active {
            display: block;
            animation: fadeUp .22s ease;
        }

        /* ── Current Stage Indicator (Status tab) ── */
        .atab.current-stage {
            color: var(--green) !important;
            border-bottom-color: var(--green) !important;
            background: #ecfdf5;
            position: relative;
        }

        .atab.current-stage:hover {
            color: var(--green) !important;
            background: #dcfce7;
        }

        .atab.current-stage::after {
            content: '';
            position: absolute;
            top: 6px;
            right: 10px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            animation: dmwPulseDot 1.6s infinite;
        }

        @keyframes dmwPulseDot {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, .55);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        /* Checkmark badge for already-completed tabs */
        .atab.done {
            position: relative;
        }

        .atab.done::before {
            content: '\2713';
            position: absolute;
            top: 5px;
            right: 9px;
            font-size: 9px;
            font-weight: 900;
            color: var(--green);
        }

        .tab-body {
            padding: 18px 16px;
        }

        /* ── Content Blocks ── */
        .info-grid {
            display: grid;
            gap: 10px 18px;
            grid-template-columns: 130px 1fr;
            margin-bottom: 14px;
        }

        @media (max-width: 480px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-dt {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            padding-top: 2px;
        }

        .info-dd {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
            margin: 0;
            line-height: 1.5;
        }

        .qual-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            line-height: 1.6;
            color: var(--text2);
            white-space: pre-wrap;
        }

        .pdf-embed {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .pdf-embed iframe {
            width: 100%;
            height: 500px;
            border: 0;
            display: block;
        }

        /* Question answer row */
        .qa-row {
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .qa-row:last-child {
            border-bottom: none;
        }

        .qa-q {
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 4px;
        }

        .qa-a {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
        }

        /* Compliance checklist */
        .checklist-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 9px;
        }

        .checklist-icon {
            color: var(--green);
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* Document row */
        .doc-row {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
            background: var(--surface);
        }

        .doc-row.has-file {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }

        .doc-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .doc-info {
            flex: 1;
            min-width: 0;
        }

        .doc-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .doc-type {
            font-size: 11px;
            color: var(--text3);
            margin-top: 1px;
        }

        .doc-file {
            font-size: 11.5px;
            color: var(--green);
            font-weight: 600;
            margin-top: 3px;
        }

        .doc-missing {
            font-size: 11.5px;
            color: var(--text3);
            font-style: italic;
            margin-top: 3px;
        }

        /* Review summary blocks */
        .review-section {
            margin-bottom: 16px;
        }

        .review-section-title {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* ── Status Tab: timeline ── */
        .status-current-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .timeline {
            position: relative;
            padding-left: 28px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 9px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 16px;
        }

        .timeline-dot {
            position: absolute;
            left: -22px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2.5px solid #fff;
            box-shadow: 0 0 0 2px var(--border);
            background: #cbd5e1;
        }

        .timeline-dot.done {
            background: var(--green);
            box-shadow: 0 0 0 2px #a7f3d0;
        }

        .timeline-dot.current {
            background: var(--blue-lt);
            box-shadow: 0 0 0 2px #bfdbfe;
        }

        .timeline-dot.bad {
            background: var(--red);
            box-shadow: 0 0 0 2px #fecaca;
        }

        .timeline-dot.warn {
            background: var(--amber);
            box-shadow: 0 0 0 2px #fde68a;
        }

        .timeline-to {
            font-size: 13px;
            font-weight: 700;
            color: var(--text1);
        }

        .timeline-date {
            font-size: 11px;
            color: var(--text3);
            margin-top: 2px;
        }

        .timeline-reason {
            font-size: 12px;
            color: var(--text2);
            font-style: italic;
            margin-top: 3px;
        }

        .eval-note-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 14px;
        }

        .eval-note-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .eval-note-text {
            font-size: 13px;
            color: #78350f;
            font-style: italic;
            line-height: 1.55;
        }

        /* ── Wiz re-use styles ── */
        .wiz-block {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 14px;
            font-size: 13px;
            font-weight: 600;
            color: #92400e;
        }

        .wiz-block a {
            color: var(--blue-lt);
            font-weight: 700;
        }

        .wiz-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .wiz-card-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .wiz-step-pill {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--blue-lt);
            margin-bottom: 8px;
        }

        .wiz-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--text1);
            margin: 0 0 6px;
        }

        .wiz-sub {
            font-size: 12.5px;
            color: var(--text3);
            margin: 0;
            line-height: 1.45;
        }

        .wiz-body {
            padding: 18px;
        }

        .wiz-form {
            padding: 18px;
        }

        .wiz-muted {
            font-size: 12px;
            color: var(--text3);
            margin: 0 0 12px;
        }

        .wiz-field {
            margin-bottom: 14px;
        }

        .wiz-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .wiz-req {
            color: #dc2626;
        }

        .wiz-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            background: var(--surface);
            color: var(--text1);
            box-sizing: border-box;
        }

        .wiz-input:focus {
            outline: none;
            border-color: var(--blue-lt);
        }

        .wiz-dl {
            display: grid;
            gap: 10px 16px;
            grid-template-columns: 1fr;
            margin: 0 0 14px;
        }

        @media (min-width: 560px) {
            .wiz-dl {
                grid-template-columns: 140px 1fr;
            }
        }

        .wiz-dt {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
        }

        .wiz-dd {
            font-size: 13px;
            font-weight: 600;
            color: var(--text1);
            margin: 0;
            line-height: 1.5;
        }

        .wiz-qual {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            line-height: 1.55;
            color: var(--text2);
            white-space: pre-wrap;
        }

        .wiz-pdf {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: var(--surface);
        }

        .wiz-pdf iframe {
            width: 100%;
            height: 520px;
            border: 0;
            display: block;
            background: #fff;
        }

        .wiz-check-item {
            margin-bottom: 10px;
        }

        .wiz-check-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text1);
            cursor: pointer;
            line-height: 1.45;
        }

        .wiz-check {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: var(--blue);
        }

        .wiz-checklist {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .wiz-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            align-items: center;
        }

        .wiz-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .15s, transform .1s;
        }

        .wiz-btn:active {
            transform: scale(.98);
        }

        .wiz-btn.primary {
            background: var(--blue);
            color: #fff;
        }

        .wiz-btn.secondary {
            background: var(--surface);
            color: var(--text2);
            border: 1px solid var(--border);
        }

        .wiz-btn.amber {
            background: var(--amber);
            color: #fff;
        }

        .wiz-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .wiz-error {
            color: #b91c1c;
            font-size: 12.5px;
            font-weight: 600;
            margin: 10px 0 0;
        }

        .wiz-error.hidden {
            display: none;
        }

        .wiz-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
        }

        .wiz-success h3 {
            margin: 0 0 8px;
            color: #065f46;
            font-size: 16px;
        }

        .wiz-success p {
            margin: 0 0 14px;
            color: #047857;
            font-size: 13px;
        }

        .wiz-review-block {
            margin-bottom: 16px;
        }

        .wiz-review-block h4 {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text3);
            margin: 0 0 8px;
        }

        .wiz-upload-row {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: var(--surface);
        }

        .wiz-upload-row.has-file {
            border-color: #a7f3d0;
            background: #f0fdf4;
        }

        .wiz-upload-filename {
            font-size: 12px;
            color: var(--green);
            font-weight: 600;
            margin: 4px 0 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wiz-upload-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
        }

        .wiz-doc-upload-form {
            display: contents;
        }

        /* ── Misc ── */
        .success-banner {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: var(--green);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .empty-state {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 48px 24px;
            text-align: center;
        }

        .empty-state i {
            font-size: 40px;
            color: var(--border);
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 14px;
            font-weight: 600;
            color: var(--text2);
        }

        .empty-state span {
            font-size: 12px;
            color: var(--text3);
            display: block;
            margin-top: 4px;
        }

        .browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 18px;
            background: var(--blue);
            color: #fff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 20px;
            border-radius: 10px;
            text-decoration: none;
        }

        .sec-label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 10px;
            display: block;
        }

        .edit-app-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--blue);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            font-family: 'DM Sans', sans-serif;
        }

        .edit-app-btn:hover {
            opacity: .9;
        }

        /* Part 2 status overview card */
        .status-overview {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }

        /* Progress steps row for status */
        .progress-steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 18px;
            overflow-x: auto;
        }

        .ps-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            flex: 1;
            min-width: 52px;
        }

        .ps-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid var(--border);
            background: var(--surface);
            color: var(--text3);
            position: relative;
            z-index: 1;
        }

        .ps-circle.done {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }

        .ps-circle.current {
            background: var(--blue-lt);
            border-color: var(--blue-lt);
            color: #fff;
        }

        .ps-circle.bad {
            background: var(--red);
            border-color: var(--red);
            color: #fff;
        }

        .ps-circle.warn {
            background: var(--amber);
            border-color: var(--amber);
            color: #fff;
        }

        .ps-label {
            font-size: 9px;
            font-weight: 700;
            color: var(--text3);
            text-align: center;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .ps-connector {
            flex: 1;
            height: 2px;
            background: var(--border);
            min-width: 8px;
        }

        .ps-connector.done {
            background: var(--green);
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

<body class="min-h-screen">

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn"><i class="fas fa-bell"></i><span class="notif-dot"></span></div>
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <div class="topbar-avatar" <?= $has_picture ? "style=\"background-image:url('" . htmlspecialchars($picture_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                <?= $has_picture ? '' : htmlspecialchars($initials ?: 'CL') ?>
            </div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top flex items-center gap-2 flex-nowrap" style="width:100%;">
            <div class="subbar-greeting min-w-0">
                <h2>My Applications</h2>
                <p><?= $total ?> total application<?= $total !== 1 ? 's' : '' ?></p>
            </div>
            <div class="subbar-actions flex items-center gap-2 flex-shrink-0" style="margin-left:auto;">
                <a href="jobs.php" class="filter-pill"><i class="fas fa-briefcase"></i> <span class="hidden sm:inline">Browse Jobs</span></a>
                <a href="index.php" class="filter-pill"><i class="fas fa-house"></i> <span class="hidden sm:inline">Dashboard</span></a>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <?php if ($wizard_block_msg !== ''): ?>
            <div class="wiz-block fade-in d1">
                <?= htmlspecialchars($wizard_block_msg) ?>
                &nbsp;<a href="jobs.php">Back to job opportunities</a>
            </div>
        <?php endif; ?>

        <?php if ($submitted): ?>
            <div class="success-banner fade-in d1">
                <i class="fas fa-circle-check"></i>
                Application submitted successfully. It will be reviewed by an evaluator.
            </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════
             SECTION A: APPLICATION WIZARD  (only shown when applying)
        ══════════════════════════════════════════════════════════════ -->
        <?php if ($show_wizard && $wizard_job): ?>
            <?php
            $sess_step_ui = max(1, (int)($_SESSION['app_step'] ?? 1));
            $tab_step     = $wizard_display_step;
            if ($tab_step < 1 || $tab_step > 4) $tab_step = min(4, max(1, $sess_step_ui));
            $is_done_1 = $sess_step_ui > 1;
            $is_done_2 = $sess_step_ui > 2;
            $is_done_3 = $sess_step_ui > 3;
            $can_2 = $sess_step_ui >= 2;
            $can_3 = $sess_step_ui >= 3;
            $can_4 = $sess_step_ui >= 4 && !empty($_SESSION['app_uploads_ok']);
            ?>

            <p class="sec-label fade-in d5" style="margin-top:1.3rem">
                <i class="fas fa-file-lines"></i> &nbsp;New Application — <?= htmlspecialchars($wizard_job['position_title'] ?? '') ?>
            </p>

            <div class="two-part-wrapper fade-in d5" id="applicationWizardGuide">

                <!-- Part labels -->
                <div class="part-label-bar">
                    <div class="part-segment part-active">
                        <!--<div class="part-segment-num">Part 1</div>-->
                        <div class="part-segment-name"><i class="fas fa-pen-to-square" style="font-size:10px;margin-right:4px"></i>Application Phase</div>
                    </div>
                    <div class="part-segment">
                        <!--<div class="part-segment-num">Part 2</div>-->
                        <div class="part-segment-name"><i class="fas fa-chart-line" style="font-size:10px;margin-right:4px"></i>Status Tracking</div>
                    </div>
                </div>

                <!-- Tab bar -->
                <div class="app-tabs" id="processTabs">
                    <button id="ptab-rsf" class="atab <?= $tab_step === 1 ? 'active' : '' ?> <?= $is_done_1 ? 'done' : '' ?>" onclick="switchWizTab('tab1',this)"><i class="fas fa-file-lines"></i> RSF</button>
                    <button id="ptab-questions" class="atab <?= $tab_step === 2 ? 'active' : '' ?> <?= $is_done_2 ? 'done' : '' ?>" onclick="switchWizTab('tab2',this)" <?= $can_2 ? '' : 'disabled' ?>><i class="fas fa-edit"></i> Questions</button>
                    <button id="ptab-uploads" class="atab <?= $tab_step === 3 ? 'active' : '' ?> <?= $is_done_3 ? 'done' : '' ?>" onclick="switchWizTab('tab3',this)" <?= $can_3 ? '' : 'disabled' ?>><i class="fas fa-file-arrow-up"></i> Requirements</button>
                    <button id="ptab-review" class="atab <?= $tab_step === 4 ? 'active' : '' ?>" onclick="switchWizTab('tab4',this)" <?= $can_4 ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> Review & Submit</button>
                    <div class="atab-divider"></div>
                    <button class="atab" onclick="alert('Submit your application first to track status here.')" disabled><i class="fas fa-chart-line"></i> Status</button>
                </div>

                <!-- Tab Contents -->
                <div class="tab-body">

                    <!-- TAB 1: RSF -->
                    <div id="tab1" class="atab-content <?= $tab_step === 1 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 1): ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 1 of 4</span>
                                    <h3 class="wiz-title">Recruitment Specification (RSF)</h3>
                                    <p class="wiz-sub">Review the position details. When ready, proceed to the application questions.</p>
                                </div>
                                <div class="wiz-body">
                                    <dl class="wiz-dl">
                                        <dt class="wiz-dt">Job title / position</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['position_title'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">RSF number</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['rsf_number'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Employer</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['employer_name'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Worksite (country)</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['worksite_country'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Classification</dt>
                                        <dd class="wiz-dd"><?= htmlspecialchars($wizard_job['classification'] ?? '—') ?></dd>
                                        <dt class="wiz-dt">Slots available</dt>
                                        <dd class="wiz-dd"><?= (int)($wizard_job['slots_available'] ?? 0) ?></dd>
                                        <dt class="wiz-dt">Deadline</dt>
                                        <dd class="wiz-dd"><?= $wizard_job['deadline'] ? date('F d, Y', strtotime($wizard_job['deadline'])) : 'Open' ?></dd>
                                        <!--<dt class="wiz-dt">Qualifications &amp; requirements</dt>-->
                                        <!-- <dd class="wiz-dd">
                                            <div class="wiz-qual"><?= htmlspecialchars($wizard_job['qualifications'] ?? 'See RSF PDF for full details.') ?></div>
                                        </dd>-->
                                    </dl>
                                    <?php if (!empty($wizard_job['pdf_path'])): ?>
                                        <p class="wiz-muted"><a href="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" target="_blank" style="font-weight:700;color:var(--blue-lt);"><i class="fas fa-file-pdf"></i> Open full RSF PDF</a></p>
                                        <div class="wiz-pdf"><iframe src="../<?= htmlspecialchars($wizard_job['pdf_path']) ?>" title="RSF PDF Preview" loading="lazy"></iframe></div>
                                    <?php endif; ?>
                                    <div class="wiz-field" style="margin-top:14px;margin-bottom:0;">
                                        <label class="wiz-check-label"><input type="checkbox" id="rsf_ack" class="wiz-check" /><span>I have read and understood the <strong>Recruitment Specification Form (RSF)</strong> before proceeding.</span></label>
                                    </div>
                                    <p id="wizard-step1-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a href="jobs.php" class="wiz-btn secondary">Cancel</a>
                                        <button type="button" class="wiz-btn primary" id="wizard-step1-proceed">Proceed <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="wiz-muted" style="padding-top:4px;"><i class="fas fa-check-circle" style="color:var(--green)"></i> This step is completed.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 2: Questions -->
                    <div id="tab2" class="atab-content <?= $tab_step === 2 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 2): ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 2 of 4</span>
                                    <h3 class="wiz-title">Application Questions</h3>
                                    <p class="wiz-sub"><?= htmlspecialchars($wizard_job['position_title'] ?? 'Position') ?> — answer all required fields.</p>
                                </div>
                                <form id="wizard-step2-form" class="wiz-form">
                                    <input type="hidden" name="job_id" value="<?= (int)$wizard_job_id ?>">
                                    <?php if ($wizard_questions === []): ?>
                                        <p class="wiz-muted">No evaluation questions configured. You may continue.</p>
                                        <?php else: foreach ($wizard_questions as $q):
                                            $req = !empty($q['is_required']);
                                            $qid = (int)$q['id'];
                                            $label = trim((string)$q['question_text']);
                                            $useTA = strlen($label) > 160;
                                            $prev  = is_array($_SESSION['app_answers'] ?? null) ? (string)($_SESSION['app_answers'][$qid] ?? '') : ''; ?>
                                            <div class="wiz-field">
                                                <label class="wiz-label" for="q_<?= $qid ?>"><?= htmlspecialchars($label) ?><?php if ($req): ?><span class="wiz-req">*</span><?php endif; ?></label>
                                                <?php if ($useTA): ?>
                                                    <textarea class="wiz-input" id="q_<?= $qid ?>" name="q_<?= $qid ?>" rows="4" <?= $req ? 'required' : '' ?>><?= htmlspecialchars($prev) ?></textarea>
                                                <?php else: ?>
                                                    <input class="wiz-input" type="text" id="q_<?= $qid ?>" name="q_<?= $qid ?>" value="<?= htmlspecialchars($prev) ?>" <?= $req ? 'required' : '' ?>>
                                                <?php endif; ?>
                                            </div>
                                    <?php endforeach;
                                    endif; ?>
                                    <p id="wizard-step2-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int)$wizard_job_id ?>&step=1">Back</a>
                                        <button type="submit" class="wiz-btn primary" id="wizard-step2-submit">Continue <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous step to access questions.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 3: Requirements (Uploads) -->
                    <div id="tab3" class="atab-content <?= $tab_step === 3 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 3):
                            $wiz_uploads = is_array($_SESSION['wizard_uploads'] ?? null) ? $_SESSION['wizard_uploads'] : [];
                            $doc_types   = myapp_required_documents_for_job($database, (int)$wizard_job_id); ?>
                            <div class="wiz-card">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 3 of 4</span>
                                    <h3 class="wiz-title">Upload Requirements</h3>
                                    <p class="wiz-sub">Upload one file per required document (PDF, JPG, or PNG).</p>
                                </div>
                                <div class="wiz-form">
                                    <?php foreach ($doc_types as $doc):
                                        $docType = (string)($doc['document_type'] ?? '');
                                        $docName = (string)($doc['document_name'] ?? $docType);
                                        $reqId   = (int)($doc['id'] ?? 0);
                                        $isReq   = !empty($doc['is_required']);
                                        $uKey    = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType);
                                        $hasFile = !empty($wiz_uploads[$uKey]['file_name']); ?>
                                        <div class="wiz-upload-row <?= $hasFile ? 'has-file' : '' ?>">
                                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                                <i class="fas <?= $hasFile ? 'fa-file-circle-check' : 'fa-file-arrow-up' ?>" style="color:<?= $hasFile ? 'var(--green)' : 'var(--text3)' ?>;font-size:15px;"></i>
                                                <strong style="font-size:13px;color:var(--text1);"><?= htmlspecialchars($docName) ?></strong>
                                                <?php if ($isReq): ?><span class="wiz-req" style="font-size:11px;">*</span><?php endif; ?>
                                            </div>
                                            <?php if (!empty($doc['description'])): ?><p class="wiz-muted" style="margin:0 0 6px 23px;"><?= htmlspecialchars($doc['description']) ?></p><?php endif; ?>
                                            <?php if ($hasFile): ?>
                                                <div class="wiz-upload-filename" style="margin-left:23px;"><i class="fas fa-check"></i><?= htmlspecialchars($wiz_uploads[$uKey]['file_name']) ?></div>
                                            <?php endif; ?>
                                            <form class="wiz-doc-upload-form" data-action="upload_doc">
                                                <input type="hidden" name="action" value="upload_doc">
                                                <input type="hidden" name="job_id" value="<?= (int)$wizard_job_id ?>">
                                                <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">
                                                <input type="hidden" name="document_name" value="<?= htmlspecialchars($docName) ?>">
                                                <input type="hidden" name="required_doc_id" value="<?= $reqId ?>">
                                                <div class="wiz-upload-actions">
                                                    <input type="file" name="doc_file" id="file_<?= $uKey ?>" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="this.form.dispatchEvent(new Event('submit',{bubbles:true}))">
                                                    <button type="submit" class="wiz-btn secondary" onclick="document.getElementById('file_<?= $uKey ?>').click();return false;" style="padding:7px 14px;font-size:11.5px;">
                                                        <i class="fas fa-upload"></i> <?= $hasFile ? 'Replace' : 'Upload' ?>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                    <p id="wizard-step3-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int)$wizard_job_id ?>&step=2">Back</a>
                                        <button type="button" class="wiz-btn primary" id="wizard-step3-continue">Continue <i class="fas fa-arrow-right"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous step to upload documents.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 4: Review & Submit -->
                    <div id="tab4" class="atab-content <?= $tab_step === 4 ? 'active' : '' ?>">
                        <?php if ($wizard_display_step === 4):
                            $wiz_uploads_rev = is_array($_SESSION['wizard_uploads'] ?? null) ? $_SESSION['wizard_uploads'] : [];
                            $doc_types_rev   = myapp_required_documents_for_job($database, (int)$wizard_job_id); ?>
                            <div class="wiz-card" id="wizard-step4-review">
                                <div class="wiz-card-head">
                                    <span class="wiz-step-pill">Step 4 of 4</span>
                                    <h3 class="wiz-title">Review &amp; Submit</h3>
                                    <p class="wiz-sub">Review all your information, then submit your application.</p>
                                </div>
                                <div class="wiz-body">
                                    <!-- RSF summary -->
                                    <div class="wiz-review-block">
                                        <h4>Position</h4>
                                        <p style="font-size:13px;font-weight:700;color:var(--text1);margin:0;"><?= htmlspecialchars($wizard_job['position_title'] ?? '—') ?></p>
                                        <p style="font-size:12px;color:var(--text3);margin:3px 0 0;"><?= htmlspecialchars($wizard_job['employer_name'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($wizard_job['worksite_country'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($wizard_job['rsf_number'] ?? '—') ?></p>
                                    </div>
                                    <!-- Answers summary -->
                                    <?php if ($wizard_questions !== []): ?>
                                        <div class="wiz-review-block">
                                            <h4>Your Answers</h4>
                                            <?php foreach ($wizard_questions as $q):
                                                $qid = (int)$q['id'];
                                                $ans = is_array($_SESSION['app_answers'] ?? null) ? (string)($_SESSION['app_answers'][$qid] ?? '—') : '—'; ?>
                                                <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--border);">
                                                    <div style="font-size:12px;font-weight:700;color:var(--text2);"><?= htmlspecialchars((string)$q['question_text']) ?></div>
                                                    <div style="font-size:13px;color:var(--text1);margin-top:3px;"><?= htmlspecialchars($ans) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Documents summary -->
                                    <div class="wiz-review-block">
                                        <h4>Uploaded Documents</h4>
                                        <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.5;color:var(--text2);">
                                            <?php foreach ($doc_types_rev as $doc):
                                                $docType = (string)($doc['document_type'] ?? '');
                                                $docName = (string)($doc['document_name'] ?? $docType);
                                                $reqId   = (int)($doc['id'] ?? 0);
                                                $uKey    = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType); ?>
                                                <li><strong><?= htmlspecialchars($docName) ?>:</strong> <?= !empty($wiz_uploads_rev[$uKey]['file_name']) ? htmlspecialchars($wiz_uploads_rev[$uKey]['file_name']) : '—' ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <p id="wizard-step4-err" class="wiz-error hidden"></p>
                                    <div class="wiz-actions">
                                        <button type="button" class="wiz-btn primary" id="wizard-step4-submit" data-job-id="<?= (int)$wizard_job_id ?>"><i class="fas fa-paper-plane"></i> Submit Application</button>
                                    </div>
                                </div>
                            </div>
                            <div id="wizard-step4-success" class="wiz-success hidden">
                                <i class="fas fa-circle-check" style="font-size:32px;color:var(--green);margin-bottom:10px;"></i>
                                <h3>Application Submitted!</h3>
                                <p id="wizard-step4-success-msg">Your application has been submitted for review.</p>
                                <button class="wiz-btn primary" id="wizard-step4-reload">View My Applications</button>
                            </div>
                        <?php else: ?><p class="wiz-muted" style="padding-top:4px;">Complete the previous steps first.</p>
                        <?php endif; ?>
                    </div>

                </div><!-- /tab-body -->
            </div><!-- /two-part-wrapper -->
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════════════
             SECTION B: SUBMITTED APPLICATIONS  (tab-based, for-each)
        ══════════════════════════════════════════════════════════════ -->

        <?php if (!empty($applications)): ?>

            <?php if (!$show_wizard): ?>
                <p class="sec-label fade-in d2" style="margin-top:1.3rem"><i class="fas fa-folder-open"></i> &nbsp;My Applications</p>
            <?php endif; ?>

            <?php if ($total > 1): ?>
                <!-- ── MULTI-APPLICATION: responsive picker (Tailwind) ──
                     Desktop/tablet: table with an Action column.
                     Mobile: stacked cards.
                     Both use the exact same openAppDetail(idx) call the
                     old picker buttons used — no navigation change. ── -->
                <div id="app-picker-section" class="fade-in d2">

                    <!-- Search + status filter — filters the picker list
                         instantly, client-side, no page reload. All
                         application data for this client is already
                         rendered in the DOM (there is no large shared
                         table to page through like the evaluator queue),
                         so this filters the existing rows/cards directly
                         instead of round-tripping to the server. -->
                    <div class="bg-[var(--card)] border border-[var(--border)] rounded-[14px] p-3.5 mb-3.5 flex flex-col gap-2.5 sm:flex-row sm:items-center">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-xs pointer-events-none"></i>
                            <input
                                type="text"
                                id="myapp-search-input"
                                placeholder="Search by position, employer, RSF #, or country…"
                                autocomplete="off"
                                class="w-full py-[9px] pr-9 pl-[34px] border border-[var(--border)] rounded-[10px] text-[13px] font-medium text-[var(--text1)] bg-[var(--surface)] outline-none transition-colors focus:border-[var(--blue-lt)] focus:bg-white placeholder:text-[var(--text3)]">
                            <button type="button" id="myapp-search-clear"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-xs hidden hover:text-[var(--text1)]"
                                title="Clear search">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                        <select id="myapp-status-select"
                            class="py-[9px] px-3 border border-[var(--border)] rounded-[10px] text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] outline-none cursor-pointer transition-colors focus:border-[var(--blue-lt)] sm:w-56">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending Evaluator Review</option>
                            <option value="under_evaluation">Under Evaluation</option>
                            <option value="for_compliance">For Compliance</option>
                            <option value="resubmitted">Resubmitted</option>
                            <option value="approved">Approved</option>
                            <option value="disapproved">Disapproved</option>
                        </select>
                    </div>

                    <!-- Empty state — shown when the search/status filter matches nothing -->
                    <div id="myapp-picker-empty" class="rounded-2xl py-12 px-6 text-center" style="display:none;border:1.5px solid var(--border);background:var(--card);">
                        <i class="fas fa-folder-open text-3xl block mb-3" style="color:var(--border);"></i>
                        <p class="text-sm font-bold" style="color:var(--text2);">No applications match your search</p>
                        <span class="text-xs" id="myapp-picker-empty-text" style="color:var(--text3);">Try a different keyword or status.</span>
                    </div>

                    <!-- Desktop / tablet: table -->
                    <div id="myapp-picker-table-wrap" class="hidden md:block rounded-2xl overflow-hidden" style="border:1.5px solid var(--border);background:var(--card);">
                        <div class="overflow-x-auto no-scrollbar">
                            <table class="w-full min-w-[900px] border-collapse text-sm">
                                <thead>
                                    <tr class="text-[10px] font-bold uppercase tracking-wide" style="background:var(--surface);color:var(--text3);">
                                        <th class="text-left px-5 py-3">Position &amp; Employer</th>
                                        <th class="text-left px-5 py-3">RSF / Country</th>
                                        <th class="text-left px-5 py-3">Status</th>
                                        <th class="text-left px-5 py-3">Updated</th>
                                        <th class="px-5 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="myapp-picker-tbody">
                                    <?php foreach ($applications as $idx => $app):
                                        $si = $app['status_info']; ?>
                                        <tr class="transition myapp-picker-row" style="border-top:1px solid var(--border);" id="picker-row-<?= $idx ?>"
                                            data-position="<?= htmlspecialchars(strtolower($app['position_title'] ?? '')) ?>"
                                            data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? '')) ?>"
                                            data-rsf="<?= htmlspecialchars(strtolower($app['rsf_number'] ?? '')) ?>"
                                            data-country="<?= htmlspecialchars(strtolower($app['worksite_country'] ?? '')) ?>"
                                            data-status="<?= htmlspecialchars(strtolower($app['status'] ?? '')) ?>"
                                            onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-[12px]" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;">
                                                        <i class="fas <?= $si['icon'] ?>"></i>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="truncate text-[13px] font-bold" style="color:var(--text1);"><?= htmlspecialchars($app['position_title']) ?></div>
                                                        <div class="truncate text-[11px] font-medium" style="color:var(--text3);"><?= htmlspecialchars($app['employer_name']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="text-[12.5px] font-bold" style="color:var(--blue-lt);"><?= htmlspecialchars($app['rsf_number']) ?></div>
                                                <div class="text-[11px]" style="color:var(--text3);"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></div>
                                            </td>
                                            <td class="px-5 py-3">
                                                <span class="status-pill <?= $si['pill'] ?>"><?= htmlspecialchars($si['label']) ?></span>
                                            </td>
                                            <td class="px-5 py-3 text-[11.5px]" style="color:var(--text3);">
                                                <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?>
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <button type="button" onclick="openAppDetail(<?= $idx ?>)" id="picker-item-<?= $idx ?>"
                                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] font-bold text-white transition hover:opacity-90"
                                                    style="background:var(--blue-lt);">
                                                    View <i class="fas fa-chevron-right text-[9px]"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Mobile: stacked cards -->
                    <div id="myapp-picker-cards" class="md:hidden flex flex-col gap-2.5">
                        <?php foreach ($applications as $idx => $app):
                            $si = $app['status_info']; ?>
                            <div class="rounded-2xl p-3.5 myapp-picker-card" style="border:1.5px solid var(--border);background:var(--card);" id="picker-card-<?= $idx ?>"
                                data-position="<?= htmlspecialchars(strtolower($app['position_title'] ?? '')) ?>"
                                data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? '')) ?>"
                                data-rsf="<?= htmlspecialchars(strtolower($app['rsf_number'] ?? '')) ?>"
                                data-country="<?= htmlspecialchars(strtolower($app['worksite_country'] ?? '')) ?>"
                                data-status="<?= htmlspecialchars(strtolower($app['status'] ?? '')) ?>">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-[13px]" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;">
                                        <i class="fas <?= $si['icon'] ?>"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="truncate text-[13.5px] font-bold" style="color:var(--text1);"><?= htmlspecialchars($app['position_title']) ?></div>
                                                <div class="truncate text-[11px] font-medium" style="color:var(--text3);"><?= htmlspecialchars($app['employer_name']) ?></div>
                                            </div>
                                            <span class="status-pill <?= $si['pill'] ?> flex-shrink-0"><?= htmlspecialchars($si['label']) ?></span>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10.5px] font-medium" style="color:var(--text3);">
                                            <span class="font-bold" style="color:var(--blue-lt);"><i class="fas fa-hashtag text-[9px] mr-1"></i><?= htmlspecialchars($app['rsf_number']) ?></span>
                                            <span><i class="fas fa-earth-asia text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></span>
                                            <span><i class="fas fa-clock text-[9px] mr-1 opacity-70"></i>Updated <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" onclick="openAppDetail(<?= $idx ?>)"
                                    class="mt-3 w-full inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[12px] font-bold text-white transition"
                                    style="background:var(--blue-lt);">
                                    View <i class="fas fa-chevron-right text-[10px]"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            <?php endif; ?>

            <!-- ── APPLICATION DETAIL PANELS (one per application) ── -->
            <?php foreach ($applications as $idx => $app):
                $aid       = (int)$app['id'];
                $si        = $app['status_info'];
                $can_edit  = canEditApplication($app['status']);
                $is_unassigned = !$app['is_assigned'];
                $show_edit_btn = $is_unassigned && $can_edit;
                $eval_note = trim((string)($app['notes'] ?? '')) !== '' ? trim($app['notes']) : $app['eval_comments'];
                $show_note = in_array($app['status'], ['for_compliance', 'disapproved'], true) && $eval_note !== '';
                $history   = $app['status_history'];
            ?>
                <div class="app-detail-panel <?= ($single_mode && $idx === 0) ? 'active' : '' ?>" id="app-detail-<?= $idx ?>">

                    <?php if ($total > 1): ?>
                        <!-- Breadcrumb / back button for multi-app -->
                        <div class="detail-header">
                            <button class="detail-back-btn" onclick="closeAppDetail(<?= $idx ?>)">
                                <i class="fas fa-arrow-left"></i> All Applications
                            </button>
                            <div style="min-width:0;">
                                <div class="detail-job-name"><?= htmlspecialchars($app['position_title']) ?></div>
                                <div class="detail-job-meta"><?= htmlspecialchars($app['rsf_number']) ?> &middot; <?= htmlspecialchars($app['employer_name']) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tab panel wrapper -->
                    <div class="two-part-wrapper" style="margin-bottom:16px;">

                        <!-- Part labels -->
                        <div class="part-label-bar" id="part-label-<?= $idx ?>">
                            <div class="part-segment" id="seg-part1-<?= $idx ?>">
                                <!--<div class="part-segment-num">Part 1</div>-->
                                <div class="part-segment-name"><i class="fas fa-pen-to-square" style="font-size:10px;margin-right:4px"></i>Application Phase</div>
                            </div>
                            <div class="part-segment part-active" id="seg-part2-<?= $idx ?>">
                                <!--<div class="part-segment-num">Part 2</div>-->
                                <div class="part-segment-name"><i class="fas fa-chart-line" style="font-size:10px;margin-right:4px"></i>Status Tracking</div>
                            </div>
                        </div>

                        <!-- Tab row -->
                        <div class="app-tabs">
                            <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-rsf-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                <i class="fas fa-file-lines"></i> RSF
                            </button>
                            <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-q-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                <i class="fas fa-edit"></i> Questions
                            </button>
                            <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-req-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                <i class="fas fa-file-arrow-up"></i> Requirements
                            </button>
                            <button class="atab done" onclick="switchAppTab(<?= $idx ?>,'atcontent-rev-<?= $idx ?>',this,'seg-part1-<?= $idx ?>')">
                                <i class="fas fa-paper-plane"></i> Review &amp; Submit
                            </button>
                            <div class="atab-divider"></div>
                            <button class="atab active current-stage" onclick="switchAppTab(<?= $idx ?>,'atcontent-status-<?= $idx ?>',this,'seg-part2-<?= $idx ?>')">
                                <i class="fas fa-chart-line"></i> Status
                            </button>
                        </div>

                        <!-- ── TAB CONTENTS ── -->
                        <div class="tab-body">

                            <!-- TAB: RSF -->
                            <div id="atcontent-rsf-<?= $idx ?>" class="atab-content">
                                <?php if ($show_edit_btn): ?>
                                    <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                        <i class="fas fa-pen"></i> Edit Application
                                    </a>
                                <?php endif; ?>
                                <dl class="info-grid">
                                    <dt class="info-dt">Position</dt>
                                    <dd class="info-dd"><?= htmlspecialchars($app['position_title']) ?></dd>
                                    <dt class="info-dt">RSF Number</dt>
                                    <dd class="info-dd"><?= htmlspecialchars($app['rsf_number']) ?></dd>
                                    <dt class="info-dt">Employer</dt>
                                    <dd class="info-dd"><?= htmlspecialchars($app['employer_name']) ?></dd>
                                    <dt class="info-dt">Country</dt>
                                    <dd class="info-dd"><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                                    <dt class="info-dt">Classification</dt>
                                    <dd class="info-dd"><?= htmlspecialchars($app['classification'] ?? '—') ?></dd>
                                    <dt class="info-dt">Slots Available</dt>
                                    <dd class="info-dd"><?= (int)($app['slots_available'] ?? 0) ?></dd>
                                    <dt class="info-dt">Deadline</dt>
                                    <dd class="info-dd"><?= !empty($app['deadline']) ? date('F d, Y', strtotime($app['deadline'])) : 'Open' ?></dd>
                                    <dt class="info-dt">Applied On</dt>
                                    <dd class="info-dd"><?= date('F d, Y', strtotime($app['submitted_at'])) ?></dd>
                                </dl>
                                <?php if (!empty($app['qualifications'])): ?>
                                    <p style="font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);margin-bottom:6px;">Qualifications &amp; Requirements</p>
                                    <div class="qual-box"><?= htmlspecialchars($app['qualifications']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($app['pdf_path'])): ?>
                                    <p style="margin-top:12px;margin-bottom:4px;"><a href="../<?= htmlspecialchars($app['pdf_path']) ?>" target="_blank" style="font-size:12.5px;font-weight:700;color:var(--blue-lt);"><i class="fas fa-file-pdf"></i> Open RSF PDF</a></p>
                                    <div class="pdf-embed"><iframe src="../<?= htmlspecialchars($app['pdf_path']) ?>" title="RSF PDF" loading="lazy"></iframe></div>
                                <?php endif; ?>
                            </div>

                            <!-- TAB: Questions -->
                            <div id="atcontent-q-<?= $idx ?>" class="atab-content">
                                <?php if ($show_edit_btn): ?>
                                    <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                        <i class="fas fa-pen"></i> Edit Application
                                    </a>
                                <?php endif; ?>
                                <?php if (empty($app['questions'])): ?>
                                    <p class="wiz-muted">No application questions for this position.</p>
                                    <?php else: foreach ($app['questions'] as $q):
                                        $qid = (int)$q['id'];
                                        $ans = $app['answers_map'][$qid] ?? '—'; ?>
                                        <div class="qa-row">
                                            <div class="qa-q"><?= htmlspecialchars((string)$q['question_text']) ?><?php if (!empty($q['is_required'])): ?> <span style="color:var(--red);">*</span><?php endif; ?></div>
                                            <div class="qa-a"><?= htmlspecialchars((string)$ans) ?></div>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>

                            <!-- TAB: Requirements -->
                            <div id="atcontent-req-<?= $idx ?>" class="atab-content">
                                <?php if ($show_edit_btn): ?>
                                    <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                        <i class="fas fa-pen"></i> Edit Application
                                    </a>
                                <?php endif; ?>
                                <?php if (empty($app['required_docs'])): ?>
                                    <p class="wiz-muted">No required documents listed for this position.</p>
                                    <?php else: foreach ($app['required_docs'] as $rd):
                                        $rid     = (int)$rd['id'];
                                        $dtype   = (string)$rd['document_type'];
                                        $uploaded = $rid > 0 ? ($app['uploaded_by_req'][$rid] ?? null) : ($app['uploaded_by_type'][$dtype] ?? null);
                                        $hasFile = $uploaded !== null; ?>
                                        <div class="doc-row <?= $hasFile ? 'has-file' : '' ?>">
                                            <div class="doc-icon" style="color:<?= $hasFile ? 'var(--green)' : 'var(--text3)' ?>;">
                                                <i class="fas <?= $hasFile ? 'fa-file-circle-check' : 'fa-file-circle-xmark' ?>"></i>
                                            </div>
                                            <div class="doc-info">
                                                <div class="doc-name"><?= htmlspecialchars($rd['document_name']) ?><?php if (!empty($rd['is_required'])): ?> <span style="color:var(--red);font-size:11px;">*</span><?php endif; ?></div>
                                                <div class="doc-type"><?= htmlspecialchars($dtype) ?></div>
                                                <?php if (!empty($rd['description'])): ?><div class="doc-type" style="font-style:italic;"><?= htmlspecialchars($rd['description']) ?></div><?php endif; ?>
                                                <?php if ($hasFile): ?>
                                                    <div class="doc-file"><i class="fas fa-check"></i> <?= htmlspecialchars($uploaded['file_name']) ?></div>
                                                <?php else: ?>
                                                    <div class="doc-missing">Not uploaded</div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($hasFile && !empty($uploaded['file_path'])): ?>
                                                <a href="../<?= htmlspecialchars($uploaded['file_path']) ?>" target="_blank" style="font-size:12px;font-weight:700;color:var(--blue-lt);white-space:nowrap;"><i class="fas fa-external-link-alt"></i> View</a>
                                            <?php endif; ?>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>

                            <!-- TAB: Review & Submit -->
                            <div id="atcontent-rev-<?= $idx ?>" class="atab-content">
                                <?php if ($show_edit_btn): ?>
                                    <a href="edit-application.php?id=<?= $aid ?>" class="edit-app-btn" style="margin-bottom:14px;display:inline-flex;">
                                        <i class="fas fa-pen"></i> Edit Application
                                    </a>
                                <?php endif; ?>

                                <!-- Requirements / qualifications reference -->
                                <?php if (!empty($app['req_lines'])): ?>
                                    <div class="review-section">
                                        <div class="review-section-title">Requirements &amp; Qualifications</div>
                                        <?php foreach ($app['req_lines'] as $line): ?>
                                            <div class="checklist-item">
                                                <i class="fas fa-circle-check checklist-icon"></i>
                                                <span style="font-size:12.5px;color:var(--text1);"><?= htmlspecialchars($line) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Summary -->
                                <div class="review-section">
                                    <div class="review-section-title">Application Summary</div>
                                    <dl class="info-grid" style="margin-bottom:0;">
                                        <dt class="info-dt">Position</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['position_title']) ?></dd>
                                        <dt class="info-dt">RSF</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['rsf_number']) ?></dd>
                                        <dt class="info-dt">Employer</dt>
                                        <dd class="info-dd"><?= htmlspecialchars($app['employer_name']) ?></dd>
                                        <dt class="info-dt">Status</dt>
                                        <dd class="info-dd"><span class="status-pill <?= $si['pill'] ?>"><?= htmlspecialchars($si['label']) ?></span></dd>
                                        <dt class="info-dt">Submitted</dt>
                                        <dd class="info-dd"><?= date('F d, Y • g:i a', strtotime($app['submitted_at'])) ?></dd>
                                        <dt class="info-dt">Last Updated</dt>
                                        <dd class="info-dd"><?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?></dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- TAB: Status (Part 2) -->
                            <div id="atcontent-status-<?= $idx ?>" class="atab-content active">

                                <!-- Current status badge -->
                                <div class="status-current-badge" style="background:<?= $si['color'] ?>1a;color:<?= $si['color'] ?>;border:1.5px solid <?= $si['color'] ?>40;">
                                    <i class="fas <?= $si['icon'] ?>" style="font-size:18px;"></i>
                                    <div>
                                        <div style="font-size:13px;font-weight:800;"><?= htmlspecialchars($si['label']) ?></div>
                                        <div style="font-size:11px;font-weight:600;opacity:.75;">Last updated: <?= date('F d, Y • g:i a', strtotime($app['updated_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($app['updated_at'])) ?></div>
                                    </div>
                                </div>

                                <?php
                                // Build a visual progress indicator using known statuses
                                $cur = $app['status'];
                                $historyToStatuses = array_column($history, 'to_status');
                                $everForCompliance = $cur === 'for_compliance' || $cur === 'resubmitted' || in_array('for_compliance', $historyToStatuses, true);
                                $everResubmitted   = $cur === 'resubmitted' || in_array('resubmitted', $historyToStatuses, true);

                                $statusOrder  = ['pending', 'unassign', 'assign', 'under_evaluation'];
                                $statusLabels = ['Submitted', 'Pending Review', 'Assigned', 'Under Evaluation'];
                                $statusIcons  = ['fa-file-lines', 'fa-inbox', 'fa-user-check', 'fa-magnifying-glass'];
                                if ($everForCompliance) {
                                    $statusOrder[]  = 'for_compliance';
                                    $statusLabels[] = 'For Compliance';
                                    $statusIcons[]  = 'fa-triangle-exclamation';
                                }
                                if ($everResubmitted) {
                                    $statusOrder[]  = 'resubmitted';
                                    $statusLabels[] = 'Resubmitted';
                                    $statusIcons[]  = 'fa-rotate-right';
                                }
                                $statusOrder[]  = 'approved';
                                $statusLabels[] = 'Approved';
                                $statusIcons[]  = 'fa-circle-check';
                                $curIdx = array_search($cur, $statusOrder);
                                // If disapproved, show a special final state
                                if ($cur === 'disapproved'):
                                ?>
                                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:18px;">
                                        <div style="font-size:13px;font-weight:800;color:var(--red);margin-bottom:4px;"><i class="fas fa-circle-xmark"></i> Application Disapproved</div>
                                        <div style="font-size:12.5px;color:#7f1d1d;">Your application has been reviewed and disapproved. Please check the evaluator notes for details.</div>
                                    </div>
                                <?php else: ?>
                                    <!-- Progress steps -->
                                    <div class="progress-steps" style="margin-bottom:20px;">
                                        <?php foreach ($statusOrder as $si_key => $sval):
                                            $slabel = $statusLabels[$si_key];
                                            $sicon  = $statusIcons[$si_key];
                                            $isDone = $curIdx !== false && $si_key < $curIdx;
                                            $isCur  = $sval === $cur;
                                            $dotClass = $isDone ? 'done' : ($isCur ? 'current' : '');
                                            if ($isCur && in_array($cur, ['for_compliance'], true)) $dotClass = 'warn';
                                            if ($si_key > 0): ?><div class="ps-connector <?= $isDone ? 'done' : '' ?>"></div><?php endif; ?>
                                            <div class="ps-step">
                                                <div class="ps-circle <?= $dotClass ?>">
                                                    <i class="fas <?= $sicon ?>" style="font-size:10px;"></i>
                                                </div>
                                                <div class="ps-label" style="<?= $isCur ? 'color:var(--blue-lt);font-weight:800;' : '' ?>"><?= htmlspecialchars($slabel) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Evaluator notes (compliance/disapproved) -->
                                <?php if ($show_note): ?>
                                    <div class="eval-note-box">
                                        <div class="eval-note-label"><i class="fas fa-comment-dots"></i> Evaluator Notes</div>
                                        <div class="eval-note-text"><?= nl2br(htmlspecialchars($eval_note)) ?></div>
                                    </div>
                                <?php endif; ?>

                                <!-- Resubmit button -->
                                <?php if ($app['status'] === 'for_compliance' && $can_edit): ?>
                                    <a href="edit-application.php?id=<?= $aid ?>" class="wiz-btn primary" style="display:inline-flex;margin-bottom:14px;">
                                        <i class="fas fa-rotate-right"></i> Update &amp; Resubmit
                                    </a>
                                <?php endif; ?>

                                <!-- Timeline -->
                                <?php $timeline_events = $app['timeline_events'] ?? []; ?>
                                <?php if (!empty($timeline_events)): ?>
                                    <p style="font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);margin-bottom:10px;">Status History</p>
                                    <div class="timeline">
                                        <?php foreach ($timeline_events as $ev): ?>

                                            <?php if ($ev['type'] === 'status'):
                                                $sh = $ev['data'];
                                                $toSt = strtolower((string)($sh['to_status'] ?? ''));
                                                $dotC = match (true) {
                                                    $toSt === 'approved'       => 'done',
                                                    $toSt === 'disapproved'    => 'bad',
                                                    $toSt === 'for_compliance' => 'warn',
                                                    $toSt === 'resubmitted'    => 'current',
                                                    in_array($toSt, ['under_evaluation', 'assign', 'assigned'], true) => 'current',
                                                    $toSt === 'pending'        => '',
                                                    default => ''
                                                }; ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-dot <?= $dotC ?>"></div>
                                                    <div class="timeline-to"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?></div>
                                                    <?php if (!empty($sh['reason'])): ?><div class="timeline-reason"><?= htmlspecialchars($sh['reason']) ?></div><?php endif; ?>
                                                    <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($sh['changed_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($sh['changed_at'])) ?></div>
                                                </div>

                                            <?php else: /* comment event */
                                                $dc = $ev['data'];
                                                $role_label = match ($dc['commenter_type'] ?? '') {
                                                    'evaluator' => 'Evaluator',
                                                    'admin'     => 'Admin',
                                                    default     => 'Reviewer',
                                                };
                                                $doc_label = ucwords(str_replace('_', ' ', $dc['document_type'] ?? 'Document')); ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-dot" style="background:var(--amber);box-shadow:0 0 0 2px #fde68a;"></div>
                                                    <div class="timeline-to"><i class="fas fa-comment-dots" style="color:var(--amber);margin-right:5px;"></i>Comment on <?= htmlspecialchars($doc_label) ?></div>
                                                    <div class="timeline-reason">"<?= htmlspecialchars($dc['comment']) ?>" &mdash; <?= htmlspecialchars($role_label) ?></div>
                                                    <div class="timeline-date"><?= date('F d, Y • g:i a', strtotime($dc['created_at'])) ?> &middot; <?= htmlspecialchars(myapp_relative_time($dc['created_at'])) ?></div>
                                                </div>
                                            <?php endif; ?>

                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="wiz-muted">No status changes recorded yet.</p>
                                <?php endif; ?>

                            </div><!-- status tab -->

                        </div><!-- tab-body -->
                    </div><!-- two-part-wrapper -->

                </div><!-- app-detail-panel -->
            <?php endforeach; ?>

        <?php elseif (!$show_wizard): ?>
            <!-- ── EMPTY STATE ── -->
            <div class="empty-state fade-in d2" style="margin-top:1rem;">
                <i class="fas fa-inbox"></i>
                <p>No applications yet</p>
                <span>Browse job opportunities and apply for a position to get started.</span>
                <a href="jobs.php" class="browse-btn"><i class="fas fa-briefcase"></i> Browse Jobs</a>
            </div>
        <?php endif; ?>

    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk"><i class="fas fa-house"></i><span class="dk-lbl">Home</span></a>
            <a href="jobs.php" class="dk"><i class="fas fa-briefcase"></i><span class="dk-lbl">Jobs</span></a>
            <div class="dk-div"></div>
            <a href="my-applications.php" class="dk active">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?><span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span><?php endif; ?>
            </a>
            <a href="notifications.php" class="dk"><i class="fas fa-bell"></i><span class="dk-lbl">Alerts</span></a>
            <div class="dk-div"></div>
            <a href="profile.php" class="dk"><i class="fas fa-user-gear"></i><span class="dk-lbl">Profile</span></a>
            <div class="dk" onclick="logout('Applicant')"><i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i><span class="dk-lbl" style="color:#f87171">Logout</span></div>
        </nav>
    </div>

    <script>
        /* ── Live date + time  */
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

        /*  TAB SWITCHING  */

        /** Switch wizard tabs (new application flow) */
        function switchWizTab(tabId, btn) {
            if (btn && btn.disabled) return;
            document.querySelectorAll('#applicationWizardGuide .atab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('#applicationWizardGuide .atab').forEach(t => t.classList.remove('active'));
            const el = document.getElementById(tabId);
            if (el) el.classList.add('active');
            if (btn) btn.classList.add('active');
        }

        /** Switch tabs within a submitted application panel */
        function switchAppTab(appIdx, contentId, btn, partSegId) {
            const panel = document.getElementById('app-detail-' + appIdx);
            if (!panel) return;
            // Deactivate all content within this panel
            panel.querySelectorAll('.atab-content').forEach(c => c.classList.remove('active'));
            panel.querySelectorAll('.atab').forEach(t => t.classList.remove('active'));
            // Deactivate part segments
            panel.querySelectorAll('.part-segment').forEach(s => s.classList.remove('part-active'));
            // Activate selected
            const el = document.getElementById(contentId);
            if (el) el.classList.add('active');
            if (btn) btn.classList.add('active');
            // Activate part segment label
            const seg = document.getElementById(partSegId);
            if (seg) seg.classList.add('part-active');
        }

        /* ══ MULTI-APP PICKER ══════════════════════════════════════════════════ */

        function openAppDetail(idx) {
            const picker = document.getElementById('app-picker-section');
            const detail = document.getElementById('app-detail-' + idx);
            if (picker) picker.style.display = 'none';
            if (detail) {
                detail.classList.add('active');
                detail.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        function closeAppDetail(idx) {
            const picker = document.getElementById('app-picker-section');
            const detail = document.getElementById('app-detail-' + idx);
            if (detail) detail.classList.remove('active');
            if (picker) {
                picker.style.display = '';
                picker.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }

        /* PICKER SEARCH + STATUS FILTER 
           Unlike the evaluator queue (applications.php), every application
           row/card here already carries its own data-* attributes rendered
           server-side — there's no separate large table to query, so this
           filters the existing DOM nodes directly instead of fetching from
           the server. Still "no page reload," just done client-side since
           the full applicant dataset is already on the page. */
        (function() {
            const searchInput = document.getElementById('myapp-search-input');
            const statusSelect = document.getElementById('myapp-status-select');
            const clearBtn = document.getElementById('myapp-search-clear');
            const emptyState = document.getElementById('myapp-picker-empty');
            const emptyText = document.getElementById('myapp-picker-empty-text');
            const tableWrap = document.getElementById('myapp-picker-table-wrap');
            const cardsWrap = document.getElementById('myapp-picker-cards');
            if (!searchInput || !statusSelect) return;

            function matches(el, term, status) {
                if (status && el.dataset.status !== status) return false;
                if (!term) return true;
                return (
                    el.dataset.position.includes(term) ||
                    el.dataset.employer.includes(term) ||
                    el.dataset.rsf.includes(term) ||
                    el.dataset.country.includes(term)
                );
            }

            function applyFilter() {
                const term = searchInput.value.trim().toLowerCase();
                const status = statusSelect.value;
                clearBtn.classList.toggle('hidden', !term);

                const rows = document.querySelectorAll('.myapp-picker-row');
                const cards = document.querySelectorAll('.myapp-picker-card');
                let visibleCount = 0;

                rows.forEach(row => {
                    const ok = matches(row, term, status);
                    row.style.display = ok ? '' : 'none';
                    if (ok) visibleCount++;
                });
                cards.forEach(card => {
                    card.style.display = matches(card, term, status) ? '' : 'none';
                });

                if (visibleCount === 0) {
                    if (tableWrap) tableWrap.style.display = 'none';
                    if (cardsWrap) cardsWrap.style.display = 'none';
                    emptyState.style.display = '';
                    const parts = [];
                    if (term) parts.push(`"${term}"`);
                    if (status) parts.push(`status "${status.replace(/_/g, ' ')}"`);
                    emptyText.textContent = parts.length ?
                        `No applications match ${parts.join(' and ')}. Try a different keyword or status.` :
                        'No applications found.';
                } else {
                    emptyState.style.display = 'none';
                    if (tableWrap) tableWrap.style.display = '';
                    if (cardsWrap) cardsWrap.style.display = '';
                }
            }

            searchInput.addEventListener('input', applyFilter);
            statusSelect.addEventListener('change', applyFilter);
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                applyFilter();
                searchInput.focus();
            });
        })();

        /* ══ WIZARD LOGIC (new application flow) ═══════════════════════════════ */
        (function() {
            const guide = document.getElementById('applicationWizardGuide');
            if (!guide) return;
            const jobId = <?= $show_wizard && $wizard_job ? (int)$wizard_job_id : 0 ?>;
            if (!jobId) return;
            const isLiveServer = window.location.port === '5500';

            function showErr(elId, msg) {
                const el = document.getElementById(elId);
                if (!el) return;
                el.textContent = msg || '';
                el.classList.toggle('hidden', !msg);
            }

            function defaultNetworkHelp() {
                if (isLiveServer) return 'Live Server cannot run PHP. Open this page via Apache (e.g. http://localhost/capstone/client/my-applications.php).';
                return 'Network error. Please try again.';
            }

            async function postWizard(fd) {
                const r = await fetch('process_application.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const raw = await r.text();
                try {
                    return JSON.parse(raw);
                } catch (e) {
                    const snip = (raw || '').trim().slice(0, 180);
                    throw new Error(snip ? `Invalid server response: ${snip}` : defaultNetworkHelp());
                }
            }

            function markDoneAndEnable(from, to) {
                const map = {
                    1: {
                        tab: 'tab1',
                        btn: 'ptab-rsf'
                    },
                    2: {
                        tab: 'tab2',
                        btn: 'ptab-questions'
                    },
                    3: {
                        tab: 'tab3',
                        btn: 'ptab-uploads'
                    },
                    4: {
                        tab: 'tab4',
                        btn: 'ptab-review'
                    }
                };
                const fromBtn = document.getElementById(map[from]?.btn || '');
                if (fromBtn) fromBtn.classList.add('done');
                const toBtn = document.getElementById(map[to]?.btn || '');
                if (toBtn) toBtn.disabled = false;
                switchWizTab(map[to]?.tab || ('tab' + to), toBtn);
            }

            /* Step 1 */
            const p1 = document.getElementById('wizard-step1-proceed');
            if (p1) p1.addEventListener('click', function() {
                showErr('wizard-step1-err', '');
                const ack = document.getElementById('rsf_ack');
                if (ack && !ack.checked) {
                    showErr('wizard-step1-err', 'Please confirm you have read and understood the Recruitment Specification Form RSF before proceeding.');
                    return;
                }
                p1.disabled = true;
                const fd = new FormData();
                fd.append('action', 'step1_proceed');
                fd.append('job_id', String(jobId));
                fd.append('rsf_ack', '1');
                postWizard(fd).then(data => {
                    p1.disabled = false;
                    if (!data.ok) {
                        showErr('wizard-step1-err', data.error || 'Something went wrong.');
                        return;
                    }
                    const tab2 = document.getElementById('tab2');
                    if (tab2) tab2.innerHTML = data.html;
                    try {
                        history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=2');
                    } catch (e) {}
                    markDoneAndEnable(1, 2);
                    bindStep2();
                }).catch(err => {
                    p1.disabled = false;
                    showErr('wizard-step1-err', err?.message || defaultNetworkHelp());
                });
            });

            /* Step 2 */
            function bindStep2() {
                const form = document.getElementById('wizard-step2-form');
                if (!form || form.dataset.bound === '1') return;
                form.dataset.bound = '1';
                form.addEventListener('submit', function(ev) {
                    ev.preventDefault();
                    showErr('wizard-step2-err', '');
                    const btn = document.getElementById('wizard-step2-submit');
                    if (btn) btn.disabled = true;
                    const fd = new FormData(form);
                    fd.append('action', 'step2_save');
                    postWizard(fd).then(data => {
                        if (btn) btn.disabled = false;
                        if (!data.ok) {
                            showErr('wizard-step2-err', data.error || 'Something went wrong.');
                            return;
                        }
                        const tab3 = document.getElementById('tab3');
                        if (tab3) tab3.innerHTML = data.html;
                        try {
                            history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=3');
                        } catch (e) {}
                        markDoneAndEnable(2, 3);
                        bindStep3Uploads();
                        bindStep3Continue();
                    }).catch(err => {
                        if (btn) btn.disabled = false;
                        showErr('wizard-step2-err', err?.message || defaultNetworkHelp());
                    });
                });
            }

            /* Step 3 - uploads */
            function bindStep3Uploads() {
                guide.querySelectorAll('.wiz-doc-upload-form').forEach(function(form) {
                    if (form.dataset.bound === '1') return;
                    form.dataset.bound = '1';
                    form.addEventListener('submit', function(ev) {
                        ev.preventDefault();
                        const errEl = document.getElementById('wizard-step3-err');
                        if (errEl) {
                            errEl.textContent = '';
                            errEl.classList.add('hidden');
                        }
                        const fd = new FormData(form);
                        const sb = form.querySelector('button[type="submit"]');
                        if (sb) sb.disabled = true;
                        postWizard(fd).then(data => {
                            if (sb) sb.disabled = false;
                            if (!data.ok) {
                                if (errEl) {
                                    errEl.textContent = data.error || 'Upload failed.';
                                    errEl.classList.remove('hidden');
                                }
                                return;
                            }
                            const tab3 = document.getElementById('tab3');
                            if (tab3 && data.html) tab3.innerHTML = data.html;
                            bindStep3Uploads();
                            bindStep3Continue();
                        }).catch(err => {
                            if (sb) sb.disabled = false;
                            if (errEl) {
                                errEl.textContent = err?.message || defaultNetworkHelp();
                                errEl.classList.remove('hidden');
                            }
                        });
                    });
                });
            }

            /* Step 3 - continue */
            function bindStep3Continue() {
                const c = document.getElementById('wizard-step3-continue');
                if (!c || c.dataset.bound === '1') return;
                c.dataset.bound = '1';
                c.addEventListener('click', function() {
                    const errEl = document.getElementById('wizard-step3-err');
                    if (errEl) {
                        errEl.textContent = '';
                        errEl.classList.add('hidden');
                    }
                    c.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'step3_continue');
                    fd.append('job_id', String(jobId));
                    postWizard(fd).then(data => {
                        c.disabled = false;
                        if (!data.ok) {
                            if (errEl) {
                                errEl.textContent = data.error || 'Please complete all uploads.';
                                errEl.classList.remove('hidden');
                            }
                            return;
                        }
                        const tab4 = document.getElementById('tab4');
                        if (tab4 && data.html) tab4.innerHTML = data.html;
                        try {
                            history.replaceState(null, '', 'my-applications.php?job_id=' + jobId + '&step=4');
                        } catch (e) {}
                        markDoneAndEnable(3, 4);
                        bindStep4Submit();
                        bindStep4Reload();
                    }).catch(err => {
                        c.disabled = false;
                        if (errEl) {
                            errEl.textContent = err?.message || defaultNetworkHelp();
                            errEl.classList.remove('hidden');
                        }
                    });
                });
            }

            bindStep2();
            bindStep3Uploads();
            bindStep3Continue();

            /* Step 4 – submit */
            function bindStep4Submit() {
                const s4 = document.getElementById('wizard-step4-submit');
                if (!s4 || s4.dataset.bound === '1') return;
                s4.dataset.bound = '1';
                s4.addEventListener('click', function() {
                    showErr('wizard-step4-err', '');
                    s4.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'step4_submit');
                    fd.append('job_id', String(jobId));
                    postWizard(fd).then(data => {
                        s4.disabled = false;
                        if (!data.ok) {
                            showErr('wizard-step4-err', data.error || 'Submission failed.');
                            return;
                        }
                        const rev = document.getElementById('wizard-step4-review');
                        const okBox = document.getElementById('wizard-step4-success');
                        const okMsg = document.getElementById('wizard-step4-success-msg');
                        if (rev) rev.classList.add('hidden');
                        if (okMsg) okMsg.textContent = data.message || 'Submitted.';
                        if (okBox) okBox.classList.remove('hidden');
                        try {
                            history.replaceState(null, '', 'my-applications.php');
                        } catch (e) {}
                        bindStep4Reload();
                    }).catch(err => {
                        s4.disabled = false;
                        showErr('wizard-step4-err', err?.message || defaultNetworkHelp());
                    });
                });
            }

            function bindStep4Reload() {
                const r4 = document.getElementById('wizard-step4-reload');
                if (!r4 || r4.dataset.bound === '1') return;
                r4.dataset.bound = '1';
                r4.addEventListener('click', () => location.reload());
            }

            bindStep4Submit();
            bindStep4Reload();
        })();

        /* CUSTOM STATUS DROPDOWN — same floating-panel pattern as evaluator/index.php's
           enhancePillSelect(). Wraps the existing native #myapp-status-select so the
           search/filter script above (which reads .value and listens for 'change')
           keeps working completely unmodified. */
        (function() {
            function enhanceStatusSelect(select) {
                if (!select) return;

                const wrapper = document.createElement('div');
                wrapper.className = 'relative inline-flex items-center w-full sm:w-56';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.classList.add('hidden');
                select.tabIndex = -1;

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.className = 'flex items-center gap-2 w-full py-[9px] px-3 border border-[var(--border)] rounded-[10px] text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] cursor-pointer transition-colors hover:border-[var(--blue-lt)]';
                trigger.innerHTML = '<span class="flex-1 text-left truncate"></span>' +
                    '<i class="fas fa-chevron-down text-[10px] opacity-60 transition-transform flex-shrink-0"></i>';
                wrapper.appendChild(trigger);

                const panel = document.createElement('div');
                panel.setAttribute('role', 'listbox');
                panel.className = 'hidden fixed w-56 bg-white border border-slate-200 rounded-2xl shadow-[0_10px_30px_rgba(15,23,42,.14)] z-[999] overflow-hidden p-1.5 max-h-[260px] overflow-y-auto';
                document.body.appendChild(panel); // appended to body so it isn't clipped by the filter bar

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.className = 'flex items-center justify-between gap-2 py-2 px-3 mb-0.5 last:mb-0 rounded-xl text-[12.5px] font-semibold text-slate-600 cursor-pointer hover:bg-blue-50 hover:text-blue-600 transition-colors';
                    row.dataset.value = optionEl.value;
                    const label = document.createElement('span');
                    label.className = 'truncate';
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
                        row.classList.toggle('bg-blue-50', selected);
                        row.classList.toggle('text-blue-600', selected);
                        row.classList.toggle('font-bold', selected);
                        let check = row.querySelector('.fa-check');
                        if (selected && !check) {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-check text-[10px] text-blue-500 flex-shrink-0';
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
                    const panelWidth = 224; // matches w-56
                    let left = rect.left;
                    const maxLeft = window.innerWidth - panelWidth - 8;
                    if (left > maxLeft) left = Math.max(8, maxLeft);
                    panel.style.top = (rect.bottom + 6) + 'px';
                    panel.style.left = left + 'px';
                    panel.style.width = rect.width + 'px';
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

            enhanceStatusSelect(document.getElementById('myapp-status-select'));
        })();
    </script>

    <?php if ($open_app_idx !== null): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                openAppDetail(<?= (int) $open_app_idx ?>);
            });
        </script>
    <?php endif; ?>
    <script type="module" src="../js/main.js"></script>

</body>

</html>