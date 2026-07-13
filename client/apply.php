<?php

session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_email = $_SESSION['user_email'];

// Get client cid
$stmt = $database->prepare("
    SELECT 
        cid, 
        CONCAT(first_name, ' ', last_name) AS full_name 
    FROM clients 
    WHERE email = ? 
    LIMIT 1
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
$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$rsf_id = (int) ($_GET['rsf_id'] ?? $_POST['rsf_id'] ?? 0);
$step = (int) ($_GET['step'] ?? $_POST['step'] ?? 1);

// Validate RSF
if (!$rsf_id) {
    header('Location: jobs.php');
    exit;
}
$stmt = $database->prepare("SELECT * FROM rsf_job_orders WHERE id = ? AND (deadline IS NULL OR deadline >= CURDATE()) LIMIT 1");
$stmt->bind_param("i", $rsf_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$job) {
    header('Location: jobs.php');
    exit;
}

$application_id = (int) ($_SESSION['apply_app_id'] ?? 0);
$error = '';
$success = '';

// Ensure we have an application for steps 2+
if ($step >= 2 && !$application_id) {
    $step = 1;
}

// ========== STEP 1: Create Application ==========
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $stmt = $database->prepare("INSERT INTO applications (client_id, rsf_id, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("ii", $client_id, $rsf_id);
    if ($stmt->execute()) {
        $application_id = (int) $database->insert_id;
        $stmt->close();
        application_status_change($database, $application_id, 'pending', $client_email, 'Application created');
        $_SESSION['apply_app_id'] = $application_id;
        $_SESSION['apply_rsf_id'] = $rsf_id;
        header("Location: apply.php?rsf_id=$rsf_id&step=2");
        exit;
    }
    $stmt->close();
    $error = 'Failed to create application.';
}

// ========== STEP 2: Answer Questions ==========
$questions = [];
$res = $database->query("SELECT id, question_text, is_required, order_no FROM questions ORDER BY COALESCE(order_no, 999), id");
if ($res) $questions = $res->fetch_all(MYSQLI_ASSOC);

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'answers') {
    $application_id = (int) $_SESSION['apply_app_id'];
    $all_ok = true;
    foreach ($questions as $q) {
        if ($q['is_required'] && empty(trim($_POST['q_' . $q['id']] ?? ''))) {
            $all_ok = false;
            $error = 'Please answer all required questions.';
            break;
        }
    }
    if ($all_ok) {
        $database->query("DELETE FROM application_answers WHERE application_id = $application_id");
        $stmt = $database->prepare("INSERT INTO application_answers (application_id, question_id, answer) VALUES (?, ?, ?)");
        foreach ($questions as $q) {
            $ans = trim($_POST['q_' . $q['id']] ?? '');
            $stmt->bind_param("iis", $application_id, $q['id'], $ans);
            $stmt->execute();
        }
        $stmt->close();
        header("Location: apply.php?rsf_id=$rsf_id&step=3");
        exit;
    }
}

// ========== STEP 3: Upload Documents ==========
$docs = [];
if ($application_id) {
    $stmt = $database->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        $doc_type = trim($_POST['document_type'] ?? '');
        if (empty($doc_type)) $doc_type = 'other';
        if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === 0) {
            $upload_dir = '../uploads/app_documents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext, $allowed)) {
                $fname = $application_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $path = $upload_dir . $fname;
                if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $path)) {
                    $rel_path = 'uploads/app_documents/' . $fname;
                    $orig = $_FILES['doc_file']['name'];
                    $stmt = $database->prepare("INSERT INTO application_documents (application_id, document_type, file_name, file_path) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isss", $application_id, $doc_type, $orig, $rel_path);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'Document uploaded successfully.';
                } else $error = 'Upload failed. Please try again.';
            } else $error = 'Invalid file type. Allowed: PDF, JPG, PNG.';
        } else $error = 'Please select a file to upload.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'next') {
        header("Location: apply.php?rsf_id=$rsf_id&step=4");
        exit;
    }
}

// ========== STEP 4: Submit ==========
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    $application_id = (int) $_SESSION['apply_app_id'];
    if (application_status_change($database, $application_id, 'pending', $client_email, 'Application submitted')) {
        unset($_SESSION['apply_app_id'], $_SESSION['apply_rsf_id']);
        header('Location: my-applications.php?submitted=1');
        exit;
    }
    $error = 'Failed to submit. Please try again.';
}

$page_title = 'Apply – ' . ($job['position_title'] ?? 'Position');

$step_labels = ['Confirm', 'Questions', 'Documents', 'Submit'];
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
        /* ── Step tracker ── */
        .stepper {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 24px;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 17px;
            left: calc(50% + 18px);
            right: calc(-50% + 18px);
            height: 2px;
            background: var(--border);
            z-index: 0;
        }

        .step-item.done:not(:last-child)::after,
        .step-item.active:not(:last-child)::after {
            background: var(--blue);
        }

        .step-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            border: 2px solid var(--border);
            background: var(--surface);
            color: var(--text3);
            position: relative;
            z-index: 1;
            transition: all .2s;
        }

        .step-item.done .step-circle {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        .step-item.active .step-circle {
            background: #fff;
            border-color: var(--blue);
            color: var(--blue);
            box-shadow: 0 0 0 4px rgba(29, 78, 216, .12);
        }

        .step-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--text3);
            margin-top: 6px;
        }

        .step-item.active .step-label {
            color: var(--blue);
        }

        .step-item.done .step-label {
            color: var(--navy);
        }

        /* ── Form elements ── */
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text2);
            margin-bottom: 6px;
            letter-spacing: .02em;
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
            color: var(--text1);
            background: var(--surface);
            outline: none;
            transition: border-color .2s, background .2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--blue-lt);
            background: #fff;
        }

        .form-textarea {
            resize: vertical;
            min-height: 88px;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 20px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all .2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--blue);
            color: #fff;
            width: 100%;
        }

        .btn-primary:hover {
            background: var(--navy2);
        }

        .btn-green {
            background: var(--green);
            color: #fff;
            width: 100%;
        }

        .btn-green:hover {
            background: #047857;
        }

        .btn-indigo {
            background: var(--indigo);
            color: #fff;
        }

        .btn-indigo:hover {
            background: #4338ca;
        }

        .btn-outline-sm {
            background: var(--surface);
            color: var(--blue-lt);
            border: 1px solid var(--border);
            font-size: 11px;
            padding: 6px 12px;
            border-radius: 8px;
        }

        /* ── Alert banners ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
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

        /* ── Doc list ── */
        .doc-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .doc-row:last-child {
            border-bottom: none;
        }

        .doc-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: #eff6ff;
            color: var(--blue-lt);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .doc-icon.pdf {
            background: #fef2f2;
            color: var(--red);
        }

        .doc-icon.img {
            background: #ecfdf5;
            color: var(--green);
        }

        .doc-name {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text1);
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .doc-type {
            font-size: 10.5px;
            color: var(--text3);
            font-weight: 500;
        }

        /* ── Upload zone ── */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 20px;
            background: var(--surface);
            transition: border-color .2s, background .2s;
        }

        .upload-zone:hover {
            border-color: var(--blue-lt);
            background: #eff6ff;
        }

        /* ── Job summary strip ── */
        .job-strip {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .job-strip-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--blue);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .job-strip-title {
            font-family: 'DM Serif Display', serif;
            font-size: 15px;
            color: var(--navy);
            line-height: 1.2;
        }

        .job-strip-sub {
            font-size: 11.5px;
            color: var(--text2);
            font-weight: 500;
            margin-top: 2px;
        }

        /* ── Confirm checklist ── */
        .check-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--text2);
        }

        .check-row:last-child {
            border-bottom: none;
        }

        .check-row i {
            color: var(--blue-lt);
            width: 16px;
            text-align: center;
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
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>JOB APPLICATION &nbsp;–&nbsp; STEP <?= $step ?> OF 4</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="topbar-avatar"><?= strtoupper(substr($client['full_name'] ?? 'U', 0, 2)) ?></div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($job['position_title']) ?></h2>
                <p><?= htmlspecialchars($job['employer_name']) ?><?= $job['worksite_country'] ? ' &nbsp;·&nbsp; ' . htmlspecialchars($job['worksite_country']) : '' ?></p>
            </div>
        </div>

        <!-- Step stepper in filter-scroll row -->
        <div class="filter-scroll" style="gap:0; padding-bottom:0;">
            <?php foreach ($step_labels as $i => $lbl):
                $n = $i + 1;
                $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
            ?>
                <div class="step-item <?= $cls ?>">
                    <div class="step-circle">
                        <?php if ($n < $step): ?>
                            <i class="fas fa-check" style="font-size:11px;"></i>
                        <?php else: ?>
                            <?= $n ?>
                        <?php endif; ?>
                    </div>
                    <span class="step-label"><?= $lbl ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- MAIN -->
    <main class="main" style="max-width:680px;">

        <?php if ($error): ?>
            <div class="alert alert-error fade-in">
                <i class="fas fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-circle-check"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- ====== STEP 1: Confirm ====== -->
        <?php if ($step === 1): ?>
            <div class="card fade-in">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--blue);"></span>
                        Confirm Application
                    </div>
                </div>

                <div class="job-strip">
                    <div class="job-strip-icon"><i class="fas fa-briefcase"></i></div>
                    <div>
                        <div class="job-strip-title"><?= htmlspecialchars($job['position_title']) ?></div>
                        <div class="job-strip-sub"><?= htmlspecialchars($job['employer_name']) ?><?= $job['worksite_country'] ? ' &nbsp;·&nbsp; ' . htmlspecialchars($job['worksite_country']) : '' ?></div>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <div class="check-row"><i class="fas fa-users"></i> <span><strong><?= number_format($job['slots_available']) ?></strong> slot<?= $job['slots_available'] != 1 ? 's' : '' ?> available</span></div>
                    <div class="check-row"><i class="fas fa-calendar-alt"></i> <span>Deadline: <?= $job['deadline'] ? date('M d, Y', strtotime($job['deadline'])) : 'Open until filled' ?></span></div>
                    <div class="check-row"><i class="fas fa-tag"></i> <span><?= htmlspecialchars($job['classification'] ?? 'N/A') ?></span></div>
                </div>

                <p style="font-size:12.5px; color:var(--text3); margin-bottom:18px; line-height:1.6;">
                    By proceeding, you confirm that you are applying as <strong><?= htmlspecialchars($client['full_name']) ?></strong>. You will answer evaluation questions and upload required documents before final submission.
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="rsf_id" value="<?= $rsf_id ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Continue to Questions
                    </button>
                </form>
            </div>

            <!-- ====== STEP 2: Questions ====== -->
        <?php elseif ($step === 2): ?>
            <div class="card fade-in">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--indigo);"></span>
                        Evaluation Questions
                    </div>
                    <?php if (!empty($questions)): ?>
                        <span style="font-size:11px; color:var(--text3); font-weight:600;"><?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="answers">
                    <input type="hidden" name="rsf_id" value="<?= $rsf_id ?>">

                    <?php if (empty($questions)): ?>
                        <div style="padding:24px 0; text-align:center; color:var(--text3);">
                            <i class="fas fa-circle-check" style="font-size:32px; margin-bottom:10px; display:block; color:var(--green);"></i>
                            <p style="font-size:13px; font-weight:600;">No questions configured.</p>
                            <p style="font-size:12px; margin-top:4px;">You may proceed to the next step.</p>
                        </div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:20px;">
                            <?php foreach ($questions as $idx => $q): ?>
                                <div>
                                    <label class="form-label">
                                        <?= $idx + 1 ?>. <?= htmlspecialchars($q['question_text']) ?>
                                        <?php if ($q['is_required']): ?>
                                            <span style="color:var(--red);">*</span>
                                        <?php endif; ?>
                                    </label>
                                    <textarea name="q_<?= $q['id'] ?>" class="form-textarea"
                                        placeholder="Type your answer here…"
                                        <?= $q['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($_POST['q_' . $q['id']] ?? '') ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Continue to Documents
                    </button>
                </form>
            </div>

            <!-- ====== STEP 3: Documents ====== -->
        <?php elseif ($step === 3): ?>
            <div class="card fade-in" style="margin-bottom:12px;">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--amber);"></span>
                        Upload Documents
                    </div>
                    <span style="font-size:11px; color:var(--text3); font-weight:600;"><?= count($docs) ?> uploaded</span>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="rsf_id" value="<?= $rsf_id ?>">

                    <div class="upload-zone" style="margin-bottom:14px;">
                        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                            <div style="flex:1; min-width:160px;">
                                <label class="form-label">Document Type</label>
                                <select name="document_type" class="form-select">
                                    <option value="passport">Passport</option>
                                    <option value="diploma">Diploma / TOR</option>
                                    <option value="certificate">Certificate</option>
                                    <option value="resume">Resume / CV</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div style="flex:2; min-width:200px;">
                                <label class="form-label">File <span style="color:var(--text3); font-weight:500;">(PDF, JPG, PNG)</span></label>
                                <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png" required class="form-input" style="padding:7px 12px;">
                            </div>
                            <button type="submit" class="btn btn-indigo" style="width:auto; flex-shrink:0;">
                                <i class="fas fa-upload"></i> Upload
                            </button>
                        </div>
                    </div>
                </form>

                <?php if (!empty($docs)): ?>
                    <div style="margin-bottom:4px;">
                        <?php foreach ($docs as $d):
                            $ext = strtolower(pathinfo($d['file_name'], PATHINFO_EXTENSION));
                            $iconClass = $ext === 'pdf' ? 'pdf' : (in_array($ext, ['jpg', 'jpeg', 'png']) ? 'img' : '');
                            $icon = $ext === 'pdf' ? 'fa-file-pdf' : (in_array($ext, ['jpg', 'jpeg', 'png']) ? 'fa-file-image' : 'fa-file');
                        ?>
                            <div class="doc-row">
                                <div class="doc-icon <?= $iconClass ?>">
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="doc-name"><?= htmlspecialchars($d['file_name']) ?></div>
                                    <div class="doc-type"><?= ucfirst(htmlspecialchars($d['document_type'])) ?></div>
                                </div>
                                <?php if (!empty($d['file_path'])): ?>
                                    <a href="../<?= htmlspecialchars($d['file_path']) ?>" target="_blank" class="btn-outline-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="font-size:12px; color:var(--text3); text-align:center; padding:16px 0;">No documents uploaded yet.</p>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="next">
                <input type="hidden" name="rsf_id" value="<?= $rsf_id ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i> Continue to Submit
                </button>
            </form>

            <!-- ====== STEP 4: Submit ====== -->
        <?php elseif ($step === 4): ?>
            <div class="card fade-in">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--green);"></span>
                        Submit Application
                    </div>
                </div>

                <div style="text-align:center; padding:16px 0 24px;">
                    <div style="width:64px; height:64px; border-radius:50%; background:#ecfdf5; border:2px solid #bbf7d0; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                        <i class="fas fa-paper-plane" style="font-size:24px; color:var(--green);"></i>
                    </div>
                    <p style="font-family:'DM Serif Display',serif; font-size:18px; color:var(--navy); margin-bottom:8px;">Ready to submit?</p>
                    <p style="font-size:12.5px; color:var(--text3); line-height:1.6; max-width:380px; margin:0 auto;">
                        Once submitted, your application will be queued for evaluator assignment. You can track its status in <strong>My Applications</strong>.
                    </p>
                </div>

                <div style="margin-bottom:20px;">
                    <div class="check-row"><i class="fas fa-briefcase"></i> <span><?= htmlspecialchars($job['position_title']) ?> – <?= htmlspecialchars($job['employer_name']) ?></span></div>
                    <div class="check-row"><i class="fas fa-user"></i> <span>Applicant: <?= htmlspecialchars($client['full_name']) ?></span></div>
                    <div class="check-row"><i class="fas fa-folder-open"></i> <span><?= count($docs) ?> document<?= count($docs) !== 1 ? 's' : '' ?> attached</span></div>
                </div>

                <form method="POST" onsubmit="return handleSubmit(event);">
                    <input type="hidden" name="action" value="submit">
                    <input type="hidden" name="rsf_id" value="<?= $rsf_id ?>">
                    <button type="submit" class="btn btn-green">
                        <i class="fas fa-check-circle"></i> Submit Application
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Back link -->
        <div style="text-align:center; margin-top:16px;">
            <a href="<?= $step > 1 ? "apply.php?rsf_id=$rsf_id&step=" . ($step - 1) : 'jobs.php' ?>"
                style="font-size:12px; color:var(--text3); font-weight:600; text-decoration:none;">
                <i class="fas fa-arrow-left" style="font-size:10px;"></i>
                <?= $step > 1 ? 'Back to previous step' : 'Back to Job Listings' ?>
            </a>
        </div>

    </main>

    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk ">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk active">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk">
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



        function handleSubmit(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Submit your application?',
                text: 'This action cannot be undone. Your application will be sent for review.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, submit',
                cancelButtonText: 'Cancel'
            }).then(r => {
                if (r.isConfirmed) e.target.submit();
            });
            return false;
        }
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>