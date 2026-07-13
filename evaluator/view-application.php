<?php
// evaluator/view-application.php – View application, submit evaluation
session_start();
require_once '../db.php';
require_once '../includes/app-helpers.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$app_id = (int)($_GET['id'] ?? 0);
if (!$app_id) {
    header('Location: applications.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

// Get evaluator eid and verify assignment
$evaluator_name = 'Evaluator';
$evaluator_id   = null;
$stmt_ev = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// Claim-or-verify ownership: opening this page is the "evaluator clicks to
// begin evaluation" moment. If the case is unclaimed, this evaluator claims
// it atomically (locking out everyone else). If it's already claimed by
// someone else — or currently with Admin for compliance — access is denied.
if (!$evaluator_id) {
    header('Location: ../login.php');
    exit;
}

$claim = claimApplication($database, $app_id, $evaluator_id, $evaluator_email);
if (!$claim['ok']) {
    if ($claim['reason'] === 'not_found') {
        header('Location: applications.php?error=not_found');
        exit;
    }
    // Either owned by another evaluator, or currently with Admin
    // (status = for_compliance, handling_party = admin).
    $holder = $claim['holder_name'] ?? null;
    $msg = $holder
        ? 'This application is already being evaluated by ' . $holder . '.'
        : 'This application is not currently available for evaluation.';
    header('Location: applications.php?locked=1&msg=' . urlencode($msg));
    exit;
}

// Handle evaluation submission (POST)
$eval_error   = '';
$eval_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_doc_comment' && $evaluator_id) {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $doc_comment = trim($_POST['doc_comment'] ?? '');

    if ($document_id <= 0 || $doc_comment === '') {
        $eval_error = 'Please enter a comment before submitting.';
    } else {
        $stmt = $database->prepare("SELECT 1 FROM application_documents WHERE id = ? AND application_id = ? LIMIT 1");
        $stmt->bind_param("ii", $document_id, $app_id);
        $stmt->execute();
        $doc_exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$doc_exists) {
            $eval_error = 'Selected document was not found for this application.';
        } else {
            $stmt = $database->prepare("INSERT INTO document_comments (document_id, commented_by, comment) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $document_id, $evaluator_email, $doc_comment);
            if ($stmt->execute()) {
                $stmt->close();
                notify_client_for_application(
                    $database,
                    $app_id,
                    'New Document Comment',
                    'An evaluator added a comment to one of your uploaded documents. Please review your application details.',
                    'document_comment'
                );
                header("Location: view-application.php?id=$app_id&doc_comment=1");
                exit;
            }
            $stmt->close();
            $eval_error = 'Failed to save document comment.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'evaluate' && $evaluator_id) {
    $decision = trim($_POST['decision'] ?? '');
    $comments = trim($_POST['comments'] ?? '');

    if (!evaluatorOwnsApplication($database, $app_id, $evaluator_id)) {
        // Ownership was lost (e.g. admin reassigned it) between page load and submit.
        $eval_error = 'You no longer have this application checked out — it may have been reassigned.';
    } elseif (in_array($decision, ['approved', 'disapproved', 'for_compliance'], true)) {
        $eval_type = $decision === 'for_compliance' ? 'compliance' : 'initial';
        $stmt = $database->prepare("INSERT INTO evaluations (application_id, evaluator_id, evaluation_type, decision, comments) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $app_id, $evaluator_id, $eval_type, $decision, $comments);
        $inserted = $stmt->execute();
        $stmt->close();

        if ($inserted) {
            // for_compliance hands the case to Admin (evaluator stays on record
            // as current_evaluator_id, but loses active control). approved /
            // disapproved are terminal and go through the plain status change.
            $changed = $decision === 'for_compliance'
                ? sendToCompliance($database, $app_id, $evaluator_id, $evaluator_email, $comments ?: null)
                : application_status_change($database, $app_id, $decision, $evaluator_email, "Evaluator decision: $decision" . ($comments ? " — $comments" : ''));

            if ($changed) {
                $stmt = $database->prepare("UPDATE applications SET notes = ? WHERE id = ?");
                $stmt->bind_param("si", $comments, $app_id);
                $stmt->execute();
                $stmt->close();

                $decisionLabel = match ($decision) {
                    'approved' => 'Approved',
                    'disapproved' => 'Disapproved',
                    'for_compliance' => 'For Compliance',
                    default => ucfirst($decision),
                };
                $notifyMsg = "Your application status is now {$decisionLabel}.";
                if ($comments !== '') {
                    $notifyMsg .= " Evaluator note: {$comments}";
                }
                notify_client_for_application(
                    $database,
                    $app_id,
                    "Application {$decisionLabel}",
                    $notifyMsg
                );

                // Notify admins — for_compliance specifically hands them the case
                notify_admins(
                    $database,
                    $decision === 'for_compliance' ? 'Application Needs Compliance Review' : "Application {$decisionLabel}",
                    "Application #$app_id has been {$decisionLabel} by evaluator. " . ($comments ? "Note: {$comments}" : ''),
                    $decision === 'for_compliance' ? 'compliance_handoff' : 'evaluation_complete',
                    $app_id
                );

                if (in_array($decision, ['approved', 'disapproved'], true)) {
                    // Case resolved — release the lock and close out the assignment.
                    $database->query("UPDATE applications SET handling_party = NULL WHERE id = $app_id");
                    $database->query("UPDATE assignments SET status = 'completed' WHERE application_id = $app_id AND evaluator_id = $evaluator_id AND status = 'active'");
                }
                header("Location: view-application.php?id=$app_id&success=1");
                exit;
            }
        }
        $eval_error = 'Failed to save evaluation.';
    } else {
        $eval_error = 'Please select a valid decision.';
    }
}

// Get application details
$stmt = $database->prepare("
    SELECT a.id, a.status, a.updated_at, a.submitted_at, a.notes,
           r.rsf_number, r.position_title, r.worksite_country, r.employer_name, r.qualifications, r.slots_available,
           CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
           c.email AS applicant_email, c.phone, c.address
    FROM applications a
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    LEFT JOIN clients   c ON a.client_id = c.cid
    WHERE a.id = ?
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    header('Location: applications.php');
    exit;
}

// Notify the applicant the first time this case is claimed (not on every
// subsequent visit by the same evaluator).
if (empty($claim['already_owned'])) {
    notify_client_for_application(
        $database,
        $app_id,
        'Application Under Evaluation',
        'An evaluator has started reviewing your application.'
    );
}

// Answers
$stmt = $database->prepare("
    SELECT aa.answer, aa.answered_at, q.question_text, q.is_required
    FROM application_answers aa
    JOIN questions q ON aa.question_id = q.id
    WHERE aa.application_id = ?
    ORDER BY q.order_no, q.id
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$answers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Documents
$stmt = $database->prepare("SELECT * FROM application_documents WHERE application_id = ? ORDER BY uploaded_at");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$docCommentsByDocumentId = [];
if (!empty($documents)) {
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

// Past evaluations
$stmt = $database->prepare("
    SELECT e.decision, e.comments, e.evaluated_at, ev.full_name
    FROM evaluations e
    JOIN evaluators ev ON e.evaluator_id = ev.eid
    WHERE e.application_id = ?
    ORDER BY e.evaluated_at DESC
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$evaluations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status history
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

$eval_success = isset($_GET['success']) ? 'Evaluation recorded successfully.' : $eval_success;
if (isset($_GET['doc_comment'])) {
    $eval_success = 'Document comment added successfully.';
}

// Status pill
$status      = strtolower($app['status']);
$statusText  = str_replace('_', ' ', ucwords($status));
$statusPill  = match ($status) {
    'approved'         => 'sp-approved',
    'under_evaluation' => 'sp-under',
    'for_compliance'   => 'sp-compliance',
    'disapproved'      => 'sp-disapproved',
    'assign'           => 'sp-assigned',
    'pending'          => 'sp-assigned',
    'assigned'         => 'sp-assigned',
    'resubmitted'      => 'sp-under',
    'unassign'         => 'sp-default',
    'unassigned'       => 'sp-default',
    default            => 'sp-default',
};

// Applicant avatar initials
$anp    = explode(' ', trim($app['applicant_name'] ?? ''));
$appAvi = strtoupper(count($anp) >= 2
    ? substr($anp[0], 0, 1) . substr(end($anp), 0, 1)
    : substr($app['applicant_name'] ?? 'NA', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Application #<?= $app_id ?> – Evaluator Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/view-application.css">
    <style>
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
                <h2>Application <!--<span style="color:var(--blue-lt)">#<?= $app_id ?></span>--></h2>
                <p>
                    <span class="status-pill <?= $statusPill ?>"><?= htmlspecialchars($statusText) ?></span>
                    &nbsp;·&nbsp; <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ -->
    <div class="main">

        <!-- Page header -->
        <div class="page-hd fade-in d1">
            <div class="page-hd-left">
                <h2>Application <!--#<?= $app_id ?>--></h2>
                <p><?= htmlspecialchars($app['position_title'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($app['employer_name'] ?? '—') ?></p>
            </div>
            <a href="applications.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <!-- Alerts -->
        <?php if ($eval_success): ?>
            <div class="alert alert-success fade-in d1">
                <i class="fas fa-circle-check"></i> <?= htmlspecialchars($eval_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($eval_error): ?>
            <div class="alert alert-error fade-in d1">
                <i class="fas fa-circle-xmark"></i> <?= htmlspecialchars($eval_error) ?>
            </div>
        <?php endif; ?>

        <!-- ── APPLICANT INFO ── -->
        <div class="sec-card fade-in d2">
            <div class="applicant-hero">
                <div class="applicant-avi"><?= htmlspecialchars($appAvi) ?></div>
                <div class="applicant-hero-info">
                    <div class="applicant-hero-name"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                    <div class="applicant-hero-sub"><?= htmlspecialchars($app['applicant_email'] ?? '—') ?></div>
                    <div class="applicant-hero-sub" style="margin-top:1px"><?= htmlspecialchars($app['phone'] ?? '—') ?></div>
                </div>
                <span class="status-pill <?= $statusPill ?>"><?= htmlspecialchars($statusText) ?></span>
            </div>
            <?php if (!empty($app['address'])): ?>
                <div style="padding: 0 16px 14px;">
                    <dt style="font-size:9.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text3);margin-bottom:3px;">Address</dt>
                    <dd style="font-size:12.5px;font-weight:500;color:var(--text2);"><?= htmlspecialchars($app['address']) ?></dd>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── JOB ORDER (RSF) ── -->
        <div class="sec-card fade-in d3">
            <div class="sec-card-head">
                <div class="sec-card-head-icon" style="background:#eff6ff;color:var(--blue);">
                    <i class="fas fa-briefcase"></i>
                </div>
                <span class="sec-card-title">Job Order (RSF)</span>
            </div>
            <div class="sec-card-body">
                <div class="dl-grid">
                    <div class="dl-item">
                        <dt>RSF Number</dt>
                        <dd style="color:var(--blue)"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></dd>
                    </div>
                    <div class="dl-item">
                        <dt>Position Title</dt>
                        <dd><?= htmlspecialchars($app['position_title'] ?? '—') ?></dd>
                    </div>
                    <div class="dl-item">
                        <dt>Employer</dt>
                        <dd><?= htmlspecialchars($app['employer_name'] ?? '—') ?></dd>
                    </div>
                    <div class="dl-item">
                        <dt>Country</dt>
                        <dd><?= htmlspecialchars($app['worksite_country'] ?? '—') ?></dd>
                    </div>
                    <?php if (!empty($app['slots_available'])): ?>
                        <div class="dl-item">
                            <dt>Slots Available</dt>
                            <dd><?= (int)$app['slots_available'] ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($app['qualifications'])): ?>
                        <div class="dl-item dl-full">
                            <dt>Qualifications</dt>
                            <dd style="white-space:pre-wrap;font-weight:500;font-size:12.5px;"><?= htmlspecialchars($app['qualifications']) ?></dd>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── QUESTION ANSWERS ── -->
        <?php if (!empty($answers)): ?>
            <div class="sec-card fade-in d4">
                <div class="sec-card-head">
                    <div class="sec-card-head-icon" style="background:#eef2ff;color:var(--indigo);">
                        <i class="fas fa-list-ul"></i>
                    </div>
                    <span class="sec-card-title">Question Answers</span>
                </div>
                <div class="sec-card-body">
                    <?php foreach ($answers as $a): ?>
                        <div class="qa-item">
                            <div class="qa-question"><?= htmlspecialchars($a['question_text']) ?></div>
                            <div class="qa-answer"><?= nl2br(htmlspecialchars($a['answer'] ?? '—')) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── DOCUMENTS ── -->
        <?php if (!empty($documents)): ?>
            <div class="sec-card fade-in d4">
                <div class="sec-card-head">
                    <div class="sec-card-head-icon" style="background:#eff6ff;color:var(--blue);">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <span class="sec-card-title">Uploaded Documents</span>
                    <span style="margin-left:auto;font-size:10px;font-weight:700;color:var(--text3);"><?= count($documents) ?> file<?= count($documents) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="sec-card-body">
                    <?php foreach ($documents as $d): ?>
                        <div class="doc-row">
                            <div class="doc-row-left">
                                <div class="doc-icon"><i class="fas fa-file-lines"></i></div>
                                <div>
                                    <div class="doc-name"><?= htmlspecialchars($d['file_name']) ?></div>
                                    <div class="doc-type"><?= htmlspecialchars($d['document_type']) ?></div>
                                </div>
                            </div>
                            <?php if (!empty($d['file_path'])):
                                $docExt = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION));
                            ?>
                                <button type="button" class="doc-view-btn"
                                    onclick="openDocPreview('../<?= htmlspecialchars($d['file_path']) ?>', '<?= htmlspecialchars(addslashes($d['file_name'])) ?>', '<?= htmlspecialchars($docExt) ?>')">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php $docComments = $docCommentsByDocumentId[(int)$d['id']] ?? []; ?>
                        <?php if (!empty($docComments)): ?>
                            <div style="margin:8px 0 10px 46px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);">
                                <div style="font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);margin-bottom:6px;">Document comments</div>
                                <?php foreach ($docComments as $c): ?>
                                    <div style="padding:6px 0;border-top:1px dashed var(--border);">
                                        <div style="font-size:11px;color:var(--text3);font-weight:600;">
                                            <?= htmlspecialchars($c['commenter_name'] ?? $c['commented_by']) ?>
                                            · <?= date('M d, Y • g:i a', strtotime($c['created_at'])) ?>
                                        </div>
                                        <div style="font-size:12px;color:var(--text2);line-height:1.5;"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (in_array($app['status'], ['assign', 'assigned', 'under_evaluation', 'resubmitted'], true)): ?>
                            <form method="POST" style="margin:0 0 14px 46px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
                                <input type="hidden" name="action" value="add_doc_comment">
                                <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                                <textarea name="doc_comment" required
                                    placeholder="Add document-specific comment for applicant..."
                                    style="flex:1;min-width:220px;min-height:42px;border:1px solid var(--border);border-radius:9px;padding:8px 10px;font-size:12px;font-family:'DM Sans',sans-serif;resize:vertical;"></textarea>
                                <button type="submit" class="doc-view-btn" style="border:none;cursor:pointer;">
                                    <i class="fas fa-comment-dots"></i> Add Comment
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── EVALUATION FORM ── -->
        <?php if (in_array($app['status'], ['assign', 'assigned', 'under_evaluation', 'resubmitted'], true)): ?>
            <div class="eval-panel fade-in d5">
                <div class="eval-panel-head">
                    <div class="eval-panel-head-icon"><i class="fas fa-gavel"></i></div>
                    <span class="eval-panel-title">Submit Evaluation</span>
                </div>
                <div class="eval-panel-body">
                    <form method="POST" id="evalForm">
                        <input type="hidden" name="action" value="evaluate">
                        <input type="hidden" name="decision" id="decisionInput" value="">

                        <label style="display:block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text2);margin-bottom:7px;">
                            Comments <span style="font-weight:500;color:var(--text3)"></span>
                        </label>
                        <textarea name="comments" class="eval-textarea" required
                            placeholder="Add notes for the applicant or admin..."><?= htmlspecialchars($app['notes'] ?? '') ?></textarea>

                        <div class="eval-actions">
                            <button type="button" class="eval-btn eval-btn-approve"
                                onclick="submitEval('approved')">
                                <i class="fas fa-circle-check"></i> Approve
                            </button>
                            <button type="button" class="eval-btn eval-btn-comply"
                                onclick="submitEval('for_compliance')">
                                <i class="fas fa-triangle-exclamation"></i> For Compliance
                            </button>
                            <button type="button" class="eval-btn eval-btn-disapprove"
                                onclick="submitEval('disapproved')">
                                <i class="fas fa-circle-xmark"></i> Disapprove
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── STATUS HISTORY ── -->
        <?php if (!empty($status_history)): ?>
            <div class="sec-card fade-in d6">
                <div class="sec-card-head">
                    <div class="sec-card-head-icon" style="background:#ecfdf5;color:var(--green);">
                        <i class="fas fa-arrows-rotate"></i>
                    </div>
                    <span class="sec-card-title">Status History</span>
                </div>
                <div class="sec-card-body">
                    <?php foreach ($status_history as $sh): ?>
                        <div class="tl-item">
                            <div class="tl-dot-wrap">
                                <div class="tl-dot"></div>
                                <div class="tl-line"></div>
                            </div>
                            <div class="tl-body">
                                <div class="tl-title">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['from_status'] ?? '—'))) ?>
                                    &nbsp;<i class="fas fa-arrow-right" style="font-size:9px;color:var(--text3)"></i>&nbsp;
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $sh['to_status'] ?? '—'))) ?>
                                </div>
                                <div class="tl-sub">
                                    <?= htmlspecialchars($sh['changed_by']) ?>
                                    <?= !empty($sh['reason']) ? ' — ' . htmlspecialchars($sh['reason']) : '' ?>
                                </div>
                                <div class="tl-date"><?= date('M d, Y • g:i a', strtotime($sh['changed_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── EVALUATION HISTORY ── -->
        <?php if (!empty($evaluations)): ?>
            <div class="sec-card fade-in d7">
                <div class="sec-card-head">
                    <div class="sec-card-head-icon" style="background:#eef2ff;color:var(--indigo);">
                        <i class="fas fa-clock-rotate-left"></i>
                    </div>
                    <span class="sec-card-title">Evaluation History</span>
                </div>
                <div class="sec-card-body">
                    <?php foreach ($evaluations as $ev): ?>
                        <?php
                        $evPill = match (strtolower($ev['decision'])) {
                            'approved'        => 'sp-approved',
                            'for_compliance'  => 'sp-compliance',
                            'disapproved'     => 'sp-disapproved',
                            default           => 'sp-default',
                        };
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot-wrap">
                                <div class="tl-dot" style="background:var(--indigo)"></div>
                                <div class="tl-line"></div>
                            </div>
                            <div class="tl-body">
                                <div class="tl-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <?= htmlspecialchars($ev['full_name']) ?>
                                    <span class="status-pill <?= $evPill ?>"><?= ucfirst(htmlspecialchars($ev['decision'])) ?></span>
                                </div>
                                <?php if (!empty($ev['comments'])): ?>
                                    <div class="tl-sub"><?= nl2br(htmlspecialchars($ev['comments'])) ?></div>
                                <?php endif; ?>
                                <div class="tl-date"><?= date('M d, Y • g:i a', strtotime($ev['evaluated_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Timestamps -->
        <div class="timestamps fade-in d8">
            Submitted: <?= date('F d, Y g:i a', strtotime($app['submitted_at'] ?? $app['updated_at'] ?? 'now')) ?>
            &nbsp;·&nbsp;
            Last updated: <?= date('F d, Y g:i a', strtotime($app['updated_at'] ?? 'now')) ?>
        </div>

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk active">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.html" class="dk">
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

    <!-- ── DOCUMENT PREVIEW MODAL ── -->
    <div id="docPreviewOverlay" class="doc-preview-overlay" onclick="if(event.target===this) closeDocPreview()">
        <div class="doc-preview-modal">
            <div class="doc-preview-head">
                <span id="docPreviewName" class="doc-preview-name"><i class="fas fa-file-lines"></i> </span>
                <div class="doc-preview-actions">
                    <a id="docPreviewOpenNew" href="#" target="_blank" class="doc-preview-open-btn" title="Open in new tab">
                        <i class="fas fa-up-right-from-square"></i>
                    </a>
                    <button type="button" class="doc-preview-close-btn" onclick="closeDocPreview()" title="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div id="docPreviewBody" class="doc-preview-body">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <style>
        .doc-preview-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .doc-preview-overlay.open {
            display: flex;
        }

        .doc-preview-modal {
            background: #fff;
            border-radius: 14px;
            width: 100%;
            max-width: 860px;
            height: 85vh;
            max-height: 900px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
        }

        .doc-preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border, #e5e7eb);
            gap: 12px;
        }

        .doc-preview-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text2, #374151);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-preview-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .doc-preview-open-btn,
        .doc-preview-close-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--border, #e5e7eb);
            background: #fff;
            color: var(--text2, #374151);
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
        }

        .doc-preview-open-btn:hover,
        .doc-preview-close-btn:hover {
            background: #f3f4f6;
        }

        .doc-preview-body {
            flex: 1;
            overflow: auto;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .doc-preview-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .doc-preview-body img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .doc-preview-unsupported {
            text-align: center;
            padding: 40px;
            color: var(--text3, #6b7280);
            font-size: 13px;
        }

        .doc-preview-unsupported a {
            color: var(--blue, #2563eb);
            font-weight: 700;
            text-decoration: none;
        }
    </style>

    <script>
        function openDocPreview(src, name, ext) {
            const overlay = document.getElementById('docPreviewOverlay');
            const body = document.getElementById('docPreviewBody');
            const nameEl = document.getElementById('docPreviewName');
            const openNew = document.getElementById('docPreviewOpenNew');

            nameEl.innerHTML = '<i class="fas fa-file-lines"></i> ' + name;
            openNew.href = src;

            const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            ext = (ext || '').toLowerCase();

            if (ext === 'pdf') {
                body.innerHTML = '<iframe src="' + src + '"></iframe>';
            } else if (imageExts.includes(ext)) {
                body.innerHTML = '<img src="' + src + '" alt="' + name + '">';
            } else {
                body.innerHTML = '<div class="doc-preview-unsupported">' +
                    'Preview is not available for this file type.<br>' +
                    '<a href="' + src + '" target="_blank">Open ' + name + ' in a new tab</a>' +
                    '</div>';
            }

            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeDocPreview() {
            const overlay = document.getElementById('docPreviewOverlay');
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            document.getElementById('docPreviewBody').innerHTML = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDocPreview();
        });
    </script>

    <script>
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        function submitEval(decision) {
            const labels = {
                approved: {
                    title: 'Approve Application?',
                    text: 'This will mark the application as Approved.',
                    icon: 'success',
                    confirmColor: '#059669'
                },
                for_compliance: {
                    title: 'Return for Compliance?',
                    text: 'This will flag the application for compliance.',
                    icon: 'warning',
                    confirmColor: '#d97706'
                },
                disapproved: {
                    title: 'Disapprove Application?',
                    text: 'This will mark the application as Disapproved.',
                    icon: 'error',
                    confirmColor: '#dc2626'
                },
            };
            const l = labels[decision];
            Swal.fire({
                title: l.title,
                text: l.text,
                icon: l.icon,
                showCancelButton: true,
                confirmButtonText: 'Yes, confirm',
                cancelButtonText: 'Cancel',
                confirmButtonColor: l.confirmColor,
                cancelButtonColor: '#6b7280',
                borderRadius: '14px',
            }).then(result => {
                if (result.isConfirmed) {
                    document.getElementById('decisionInput').value = decision;
                    document.getElementById('evalForm').submit();
                }
            });
        }
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>