<?php

/**
 * Client: view / edit submitted application (answers + documents).
 * Editing allowed only when canEditApplication(status) is true.
 */
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("
    SELECT cid, CONCAT(first_name, ' ', last_name) AS full_name
    FROM clients WHERE email = ? LIMIT 1
");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$client) {
    header('Location: ../login.php');
    exit;
}
$client_id = (int) $client['cid'];

$app_id = (int) ($_GET['id'] ?? $_POST['application_id'] ?? 0);
if (!$app_id) {
    header('Location: my-applications.php');
    exit;
}

$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $database->prepare("
    SELECT a.id, a.client_id, a.rsf_id, a.status, a.notes, a.updated_at,
           r.position_title, r.employer_name, r.worksite_country, r.rsf_number
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    WHERE a.id = ? AND a.client_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $app_id, $client_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$app) {
    header('Location: my-applications.php');
    exit;
}

$rsf_id = (int) $app['rsf_id'];
$status  = (string) $app['status'];
$editable = canEditApplication($status);

// ── Helper: load RSF-specific required documents (mirrors my-applications.php) ──
function edit_required_documents_for_job(mysqli $db, int $job_id): array
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
        $docs[] = [
            'id'            => (int)    $r['id'],
            'document_type' => (string) $r['document_type'],
            'document_name' => (string) $r['document_name'],
            'is_required'   => (int)    ($r['is_required'] ?? 1),
            'description'   => (string) ($r['description'] ?? ''),
        ];
    }

    // Fallback if no per-job requirements configured yet
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport',    'document_name' => 'Passport (valid)',      'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume',      'document_name' => 'Resume / CV',           'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma',     'document_name' => 'Diploma / TOR',         'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate', 'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }
    // Fallback if no per-job requirements configured yet
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport',    'document_name' => 'Passport (valid)',      'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume',      'document_name' => 'Resume / CV',           'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma',     'document_name' => 'Diploma / TOR',         'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate', 'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }
    return $docs;
}

// ── Helper: load RSF-specific questions (mirrors my-applications.php) ──
function edit_questions_for_job(mysqli $db, int $job_id): array
{
    if ($job_id <= 0) return [];
    $stmt = $db->prepare('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id = ? ORDER BY COALESCE(order_no,999), id');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Backward compat: fall back to global questions
    if ($rows === []) {
        $res  = $db->query('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NULL ORDER BY COALESCE(order_no,999), id');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    return $rows;
}

// ── Evaluator note ──
$eval_note = '';
$stmt = $database->prepare("SELECT comments FROM evaluations WHERE application_id = ? ORDER BY evaluated_at DESC LIMIT 1");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$er = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($er && trim((string)($er['comments'] ?? '')) !== '') {
    $eval_note = trim($er['comments']);
}
$notes_display        = trim((string)($app['notes'] ?? '')) !== '' ? trim($app['notes']) : $eval_note;
$show_evaluator_notes = in_array($status, ['for_compliance', 'disapproved'], true);
$show_resubmit        = $status === 'for_compliance' && $editable;

$banner_message = '';
if (in_array($status, ['assign', 'assigned', 'under_evaluation'], true)) {
    $banner_message = 'Your application is assigned and cannot be edited.';
} elseif ($status === 'approved') {
    $banner_message = 'Your application has been approved and cannot be edited.';
} elseif ($status === 'disapproved') {
    $banner_message = 'This application is final and cannot be edited.';
} elseif ($status === 'for_compliance' && $editable) {
    $banner_message = "Please review the evaluator's notes and update your application.";
}

$error   = '';
$success = '';

// —— POST: resubmit ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit') {
    $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cur = $row['status'] ?? '';
    if ($cur !== 'for_compliance') {
        $error = 'Resubmit is only available when your application is for compliance.';
    } elseif (application_status_change($database, $app_id, 'resubmitted', $client_email, 'Client resubmitted application')) {
        header('Location: edit-application.php?id=' . $app_id . '&resubmitted=1');
        exit;
    } else {
        $error = 'Could not update status. Please try again.';
    }
}

// Reload status after resubmit redirect
if (isset($_GET['resubmitted'])) {
    $success = 'Your application was marked as resubmitted. An evaluator will review it again.';
    $stmt    = $database->prepare("SELECT status, notes FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $r2 = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r2) {
        $app['status']   = $r2['status'];
        $app['notes']    = $r2['notes'];
        $status          = (string) $app['status'];
        $editable        = canEditApplication($status);
        $show_resubmit   = $status === 'for_compliance' && $editable;
        $show_evaluator_notes = in_array($status, ['for_compliance', 'disapproved'], true);
        $banner_message  = match (true) {
            in_array($status, ['assign', 'assigned', 'under_evaluation'], true) => 'Your application is assigned and cannot be edited.',
            $status === 'for_compliance' && $editable => "Please review the evaluator's notes and update your application.",
            default => '',
        };
    }
}

// —— POST: save answers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'answers') {
    $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !canEditApplication($row['status'])) {
        $error = 'You cannot edit this application in its current status.';
    } else {
        $questions_post = edit_questions_for_job($database, $rsf_id);
        $all_ok = true;
        foreach ($questions_post as $q) {
            if ($q['is_required'] && empty(trim($_POST['q_' . $q['id']] ?? ''))) {
                $all_ok = false;
                $error  = 'Please answer all required questions.';
                break;
            }
        }
        if ($all_ok) {
            $database->query("DELETE FROM application_answers WHERE application_id = " . (int)$app_id);
            $stmt = $database->prepare("INSERT INTO application_answers (application_id, question_id, answer) VALUES (?, ?, ?)");
            foreach ($questions_post as $q) {
                $ans = trim($_POST['q_' . $q['id']] ?? '');
                $qid = (int) $q['id'];
                $stmt->bind_param("iis", $app_id, $qid, $ans);
                $stmt->execute();
            }
            $stmt->close();
            $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
            $success = 'Answers saved successfully.';
        }
    }
}

// —— POST: upload document (per-required-doc, mirrors wizard step 3) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $stmt = $database->prepare("SELECT status FROM applications WHERE id = ? AND client_id = ? LIMIT 1");
    $stmt->bind_param("ii", $app_id, $client_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !canEditApplication($row['status'])) {
        $error = 'You cannot upload documents for this application in its current status.';
    } elseif (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === 0) {
        $doc_type    = trim($_POST['document_type']  ?? '') ?: 'other';
        $doc_name    = trim($_POST['document_name']  ?? '') ?: $doc_type;
        $req_doc_id  = (int)($_POST['required_doc_id'] ?? 0);
        $upload_dir  = '../uploads/app_documents/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $ext     = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        if (in_array($ext, $allowed, true)) {
            $suffix = $req_doc_id > 0 ? 'doc_' . $req_doc_id : $doc_type;
            $fname  = $app_id . '_' . time() . '_' . $suffix . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $path   = $upload_dir . $fname;
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $path)) {
                $rel_path = 'uploads/app_documents/' . $fname;
                $orig     = $_FILES['doc_file']['name'];

                // Replace existing doc of same required_doc_id / document_type
                if ($req_doc_id > 0) {
                    // Delete the old file + record for this required_doc slot
                    $stmt = $database->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND required_doc_id = ? LIMIT 1");
                    $stmt->bind_param("ii", $app_id, $req_doc_id);
                    $stmt->execute();
                    $old = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($old && !empty($old['file_path'])) @unlink('../' . $old['file_path']);
                    $database->query("DELETE FROM application_documents WHERE application_id = " . (int)$app_id . " AND required_doc_id = " . (int)$req_doc_id);
                    $stmt = $database->prepare("INSERT INTO application_documents (application_id, document_type, required_doc_id, file_name, file_path) VALUES (?,?,?,?,?)");
                    $stmt->bind_param("isiss", $app_id, $doc_type, $req_doc_id, $orig, $rel_path);
                } else {
                    // Fallback: replace by document_type
                    $stmt2 = $database->prepare("SELECT file_path FROM application_documents WHERE application_id = ? AND document_type = ? AND (required_doc_id IS NULL OR required_doc_id = 0) LIMIT 1");
                    $stmt2->bind_param("is", $app_id, $doc_type);
                    $stmt2->execute();
                    $old2 = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                    if ($old2 && !empty($old2['file_path'])) @unlink('../' . $old2['file_path']);
                    $database->query("DELETE FROM application_documents WHERE application_id = " . (int)$app_id . " AND document_type = '" . $database->real_escape_string($doc_type) . "' AND (required_doc_id IS NULL OR required_doc_id = 0)");
                    $stmt = $database->prepare("INSERT INTO application_documents (application_id, document_type, file_name, file_path) VALUES (?,?,?,?)");
                    $stmt->bind_param("isss", $app_id, $doc_type, $orig, $rel_path);
                }
                $stmt->execute();
                $stmt->close();
                $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
                $success = 'Document uploaded successfully.';
            } else {
                $error = 'Upload failed. Please try again.';
            }
        } else {
            $error = 'Invalid file type. Allowed: PDF, JPG, PNG.';
        }
    } else {
        $error = 'Please select a file to upload.';
    }
}

// —— POST: delete document ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc') {
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if ($doc_id && $editable) {
        $stmt = $database->prepare("SELECT file_path FROM application_documents WHERE id = ? AND application_id = ? LIMIT 1");
        $stmt->bind_param("ii", $doc_id, $app_id);
        $stmt->execute();
        $drow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($drow) {
            if (!empty($drow['file_path'])) @unlink('../' . $drow['file_path']);
            $database->query("DELETE FROM application_documents WHERE id = " . $doc_id);
            $database->query("UPDATE applications SET updated_at = NOW() WHERE id = " . (int)$app_id);
            $success = 'Document removed.';
        }
    }
    header('Location: edit-application.php?id=' . $app_id);
    exit;
}

// ── Load questions + existing answers ────────────────────────────────────────
$questions   = edit_questions_for_job($database, $rsf_id);
$answers_map = [];
$stmt = $database->prepare("SELECT question_id, answer FROM application_answers WHERE application_id = ?");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$ar = $stmt->get_result();
while ($r = $ar->fetch_assoc()) {
    $answers_map[(int)$r['question_id']] = $r['answer'];
}
$stmt->close();

// ── Load existing uploaded documents ─────────────────────────────────────────
$docs = [];
$stmt = $database->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$docCommentsByDocumentId = [];
if (!empty($docs)) {
    $stmt = $database->prepare("
        SELECT dc.document_id, dc.comment, dc.created_at, dc.commented_by,
               COALESCE(ev.full_name, ad.full_name, dc.commented_by) AS commenter_name
        FROM document_comments dc
        LEFT JOIN evaluators ev ON dc.commented_by = ev.email
        LEFT JOIN admins ad ON dc.commented_by = ad.email
        WHERE dc.document_id IN (
            SELECT id FROM application_documents WHERE application_id = ?
        )
        ORDER BY dc.created_at DESC
    ");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $commentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($commentRows as $row) {
        $did = (int)($row['document_id'] ?? 0);
        if (!isset($docCommentsByDocumentId[$did])) {
            $docCommentsByDocumentId[$did] = [];
        }
        $docCommentsByDocumentId[$did][] = $row;
    }
}

// Build a lookup: required_doc_id → uploaded doc row
$uploaded_by_req_id   = [];  // keyed by required_doc_id (int)
$uploaded_by_doc_type = [];  // keyed by document_type (string) for fallback
foreach ($docs as $d) {
    $rid = (int)($d['required_doc_id'] ?? 0);
    if ($rid > 0) {
        $uploaded_by_req_id[$rid] = $d;
    } else {
        $uploaded_by_doc_type[$d['document_type']] = $d;
    }
}

// ── Load status history ───────────────────────────────────────────────────────
$status_history = [];
$stmt = $database->prepare("
    SELECT from_status, to_status, changed_by, reason, changed_at
    FROM status_history
    WHERE application_id = ?
    ORDER BY changed_at DESC
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$status_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// ── Load RSF-specific required documents ─────────────────────────────────────
$required_docs = edit_required_documents_for_job($database, $rsf_id);

$disabled_attr = $editable ? '' : ' disabled';
$readonly_attr = $editable ? '' : ' readonly';
$page_title    = 'Application – ' . ($app['position_title'] ?? 'Edit');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – DMW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            background: var(--surface);
        }

        .form-textarea {
            min-height: 88px;
            resize: vertical;
        }

        .form-textarea:disabled,
        .form-input:disabled,
        .form-select:disabled {
            opacity: .65;
            cursor: not-allowed;
            background: #f1f5f9;
        }

        /* ── Shared wizard-style classes ── */
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

        .wiz-muted {
            font-size: 12px;
            color: var(--text3);
            margin: 0 0 8px;
        }

        /* ── Upload row (mirrors Step 3) ── */
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

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--blue);
            color: #fff;
            width: 100%;
        }

        .btn-amber {
            background: var(--amber);
            color: #fff;
            width: 100%;
        }

        .btn-danger {
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 11.5px;
            width: auto;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--red);
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: var(--green);
        }

        .alert-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: var(--navy);
        }

        .job-strip {
            display: flex;
            gap: 12px;
            padding: 12px 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .app-notes {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
        }

        .app-notes-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            color: #94a3b8;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .doc-comments {
            margin-top: 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f8fafc;
            padding: 8px 10px;
        }

        .doc-comments-hd {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 6px;
        }

        .doc-comment-item {
            padding: 6px 0;
            border-top: 1px dashed var(--border);
        }

        .doc-comment-meta {
            font-size: 11px;
            color: var(--text3);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .doc-comment-text {
            font-size: 12px;
            color: var(--text2);
            line-height: 1.5;
        }

        .section-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .section-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-head h3 {
            font-size: 14px;
            font-weight: 800;
            color: var(--text1);
            margin: 0;
        }

        .section-body {
            padding: 18px;
        }

        .ctdot {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
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

    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>EDIT APPLICATION</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="topbar-avatar"><?= strtoupper(substr($client['full_name'] ?? 'U', 0, 2)) ?></div>
        </div>
    </header>

    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($app['position_title']) ?></h2>
                <p><?= htmlspecialchars($app['employer_name']) ?><?= $app['worksite_country'] ? ' · ' . htmlspecialchars($app['worksite_country']) : '' ?></p>
            </div>
        </div>
    </div>

    <main class="main" style="max-width:680px;">

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($banner_message): ?>
            <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($banner_message) ?></div>
        <?php endif; ?>

        <div class="job-strip">
            <div style="font-size:12px;">
                <strong><?= htmlspecialchars($app['rsf_number']) ?></strong>
                &nbsp;·&nbsp; Status: <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></strong>
            </div>
        </div>

        <?php if ($show_evaluator_notes && $notes_display !== ''): ?>
            <div class="app-notes">
                <div class="app-notes-label"><i class="fas fa-comment-dots"></i> Evaluator notes</div>
                <div style="font-size:13px; line-height:1.55; color:#475569;"><?= nl2br(htmlspecialchars($notes_display)) ?></div>
            </div>
        <?php endif; ?>

        <!-- ══ SECTION 1: Evaluation questions ══════════════════════════════ -->
        <div class="section-card fade-in">
            <div class="section-head">
                <span class="ctdot" style="background:var(--indigo);"></span>
                <h3>Evaluation questions</h3>
            </div>
            <div class="section-body">
                <?php if ($questions === []): ?>
                    <p class="wiz-muted">No evaluation questions are configured for this job order.</p>
                <?php else: ?>
                    <form method="POST" id="answers-form" class="js-app-edit-form" data-editable="<?= $editable ? '1' : '0' ?>">
                        <input type="hidden" name="action" value="answers">
                        <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                        <?php foreach ($questions as $idx => $q): ?>
                            <?php
                            $qid        = (int) $q['id'];
                            $label      = trim((string) $q['question_text']);
                            $req        = !empty($q['is_required']);
                            $useTextarea = strlen($label) > 160;
                            $prev       = htmlspecialchars($answers_map[$qid] ?? '');
                            ?>
                            <div class="wiz-field">
                                <label class="wiz-label" for="q_<?= $qid ?>">
                                    <?= ($idx + 1) ?>. <?= htmlspecialchars($label) ?>
                                    <?php if ($req): ?><span class="wiz-req">*</span><?php endif; ?>
                                </label>
                                <?php if ($useTextarea): ?>
                                    <textarea class="wiz-input" id="q_<?= $qid ?>" name="q_<?= $qid ?>" rows="4"
                                        <?= $req && $editable ? 'required' : '' ?>
                                        <?= $readonly_attr ?>><?= $prev ?></textarea>
                                <?php else: ?>
                                    <input class="wiz-input" type="text" id="q_<?= $qid ?>" name="q_<?= $qid ?>"
                                        value="<?= $prev ?>"
                                        <?= $req && $editable ? 'required' : '' ?>
                                        <?= $readonly_attr ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary" <?= $disabled_attr ?>>
                            <i class="fas fa-floppy-disk"></i> Save answers
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ SECTION 2: Documents (per-required-doc rows, mirrors Step 3) ═ -->
        <div class="section-card fade-in" style="margin-top:14px;">
            <div class="section-head">
                <span class="ctdot" style="background:var(--amber);"></span>
                <h3>Required documents</h3>
            </div>
            <div class="section-body">
                <p class="wiz-muted">Upload one file per required document (PDF, JPG, or PNG). Re-uploading a slot replaces the existing file.</p>

                <?php foreach ($required_docs as $doc): ?>
                    <?php
                    $docType  = (string) ($doc['document_type'] ?? '');
                    $docName  = (string) ($doc['document_name'] ?? $docType);
                    $reqId    = (int)    ($doc['id'] ?? 0);
                    $isReq    = !empty($doc['is_required']);
                    $desc     = (string) ($doc['description'] ?? '');

                    // Find the uploaded file for this slot
                    $uploaded = null;
                    if ($reqId > 0 && isset($uploaded_by_req_id[$reqId])) {
                        $uploaded = $uploaded_by_req_id[$reqId];
                    } elseif (isset($uploaded_by_doc_type[$docType])) {
                        $uploaded = $uploaded_by_doc_type[$docType];
                    }
                    $has = $uploaded !== null;
                    ?>
                    <div class="wiz-upload-row <?= $has ? 'has-file' : '' ?>">
                        <div class="wiz-label">
                            <?= htmlspecialchars($docName) ?>
                            <?php if ($has): ?>
                                <span style="color:var(--green);font-size:11px;font-weight:600;"> ✓ Uploaded</span>
                            <?php elseif ($isReq): ?>
                                <span class="wiz-req"> *</span>
                            <?php else: ?>
                                <span style="font-size:11px;color:var(--text3);"> (optional)</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($desc !== ''): ?>
                            <p class="wiz-muted"><?= htmlspecialchars($desc) ?></p>
                        <?php endif; ?>

                        <?php if ($has): ?>
                            <div class="wiz-upload-filename">
                                <i class="fas fa-file"></i>
                                <?= htmlspecialchars($uploaded['file_name'] ?? '') ?>
                            </div>
                            <?php $docComments = $docCommentsByDocumentId[(int)($uploaded['id'] ?? 0)] ?? []; ?>
                            <?php if (!empty($docComments)): ?>
                                <div class="doc-comments">
                                    <div class="doc-comments-hd"><i class="fas fa-comment-dots"></i> Document comments</div>
                                    <?php foreach ($docComments as $c): ?>
                                        <div class="doc-comment-item">
                                            <div class="doc-comment-meta">
                                                <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                                · <?= date('F d, Y g:i a', strtotime($c['created_at'])) ?>
                                            </div>
                                            <div class="doc-comment-text"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="wiz-upload-actions">
                                <?php if (!empty($uploaded['file_path'])): ?>
                                    <a href="../<?= htmlspecialchars($uploaded['file_path']) ?>" target="_blank"
                                        class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--blue-lt);">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                <?php endif; ?>
                                <?php if ($editable): ?>
                                    <form method="POST" style="display:inline;" class="delete-doc-form">
                                        <input type="hidden" name="action" value="delete_doc">
                                        <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                                        <input type="hidden" name="doc_id" value="<?= (int)$uploaded['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this document?">
                                            <i class="fas fa-trash-can"></i> Remove
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($editable): ?>
                            <form method="POST" enctype="multipart/form-data" class="js-doc-upload-form" style="margin-top:<?= $has ? '10px' : '4px' ?>;">
                                <input type="hidden" name="action" value="upload">
                                <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                                <input type="hidden" name="required_doc_id" value="<?= (int)$reqId ?>">
                                <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">
                                <input type="hidden" name="document_name" value="<?= htmlspecialchars($docName) ?>">
                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                                    <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png"
                                        class="wiz-input" style="flex:1;min-width:180px;padding:8px;" required>
                                    <button type="submit" class="btn btn-primary btn-sm" style="flex-shrink:0;">
                                        <i class="fas fa-upload"></i> <?= $has ? 'Replace' : 'Upload' ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php
                // Show any extra uploaded docs that don't match a required slot
                $extra_docs = [];
                foreach ($docs as $d) {
                    $rid = (int)($d['required_doc_id'] ?? 0);
                    $matched = false;
                    foreach ($required_docs as $rd) {
                        if ($rid > 0 && $rid === (int)$rd['id']) {
                            $matched = true;
                            break;
                        }
                        if ($rid === 0 && $d['document_type'] === $rd['document_type']) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) $extra_docs[] = $d;
                }
                ?>
                <?php if ($extra_docs !== []): ?>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
                        <p class="wiz-muted" style="font-weight:700;color:var(--text2);">Other uploaded files</p>
                        <?php foreach ($extra_docs as $d): ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($d['file_name']) ?></div>
                                    <div style="font-size:11px;color:var(--text3);"><?= htmlspecialchars($d['document_type']) ?></div>
                                </div>
                                <?php if (!empty($d['file_path'])): ?>
                                    <a href="../<?= htmlspecialchars($d['file_path']) ?>" target="_blank"
                                        class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--blue-lt);">View</a>
                                <?php endif; ?>
                                <?php if ($editable): ?>
                                    <form method="POST" class="delete-doc-form">
                                        <input type="hidden" name="action" value="delete_doc">
                                        <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                                        <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this document?">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- ══ SECTION: Status History ══════════════════════════════════════════ -->
        <?php if (!empty($status_history)): ?>
            <div class="section-card fade-in" style="margin-top:14px;">
                <div class="section-head">
                    <span class="ctdot" style="background:var(--green);"></span>
                    <h3>Application Status History</h3>
                </div>
                <div class="section-body">
                    <?php foreach ($status_history as $sh): ?>
                        <div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed var(--border);">
                            <div style="flex:1;">
                                <div style="font-size:13px;font-weight:700;color:var(--text1);">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['from_status'] ?? '—'))) ?>
                                    &nbsp;<i class="fas fa-arrow-right" style="font-size:10px;color:var(--text3);"></i>&nbsp;
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?>
                                </div>
                                <?php if (!empty($sh['reason'])): ?>
                                    <div style="font-size:12px;color:var(--text3);margin-top:2px;"><?= htmlspecialchars($sh['reason']) ?></div>
                                <?php endif; ?>
                                <div style="font-size:11px;color:var(--text3);margin-top:2px;">
                                    <?= date(' F d, Y • g:i a', strtotime($sh['changed_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ══ SECTION 3: Resubmit ════════════════════════════════════════════ -->
        <?php if ($show_resubmit): ?>
            <div class="section-card fade-in" style="margin-top:14px;">
                <div class="section-head">
                    <span class="ctdot" style="background:var(--green);"></span>
                    <h3>Resubmit</h3>
                </div>
                <div class="section-body">
                    <p class="wiz-muted">After updating your answers and documents, click below to mark this application as resubmitted for evaluator review.</p>
                    <form method="POST" id="resubmit-form">
                        <input type="hidden" name="action" value="resubmit">
                        <input type="hidden" name="application_id" value="<?= (int)$app_id ?>">
                        <button type="button" class="btn btn-amber" id="resubmit-btn">
                            <i class="fas fa-rotate-right"></i> Resubmit Application
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div style="text-align:center; margin-top:18px;">
            <a href="my-applications.php" style="font-size:12px; color:var(--text3); font-weight:600;">
                <i class="fas fa-arrow-left"></i> Back to My Applications
            </a>
        </div>
    </main>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <a href="jobs.php" class="dk">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>
            <div class="dk-div"></div>
            <a href="my-applications.php" class="dk active">
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
            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>
            <div class="dk" onclick="logout('Applicant')">
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

        // Disable all inputs in non-editable forms
        (function() {
            document.querySelectorAll('.js-app-edit-form[data-editable="0"]').forEach(function(form) {
                form.querySelectorAll('input, select, textarea, button').forEach(function(el) {
                    if (el.type === 'hidden') return;
                    el.disabled = true;
                });
            });
        })();

        // Confirm before deleting a document
        document.querySelectorAll('.delete-doc-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('button[data-confirm]');
                var msg = btn ? btn.getAttribute('data-confirm') : 'Remove this document?';
                Swal.fire({
                    title: msg,
                    text: 'This cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, remove',
                    cancelButtonText: 'Cancel'
                }).then(function(r) {
                    if (r.isConfirmed) form.submit();
                });
            });
        });

        // Resubmit confirmation
        var rsBtn = document.getElementById('resubmit-btn');
        if (rsBtn) {
            rsBtn.addEventListener('click', function() {
                Swal.fire({
                    title: 'Resubmit application?',
                    text: 'Your status will change to Resubmitted and an evaluator will review your updates.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, resubmit',
                    cancelButtonText: 'Cancel'
                }).then(function(r) {
                    if (r.isConfirmed) document.getElementById('resubmit-form').submit();
                });
            });
        }
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>