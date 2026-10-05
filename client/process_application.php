<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/app-helpers.php';


function app_json_out(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    app_json_out(['ok' => false, 'error' => 'Please log in to continue.']);
}

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare('SELECT cid FROM clients WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $client_email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    app_json_out(['ok' => false, 'error' => 'Invalid account.']);
}
$client_id = (int) $row['cid'];

/**
 * @return array<string, mixed>|null
 */
function app_fetch_valid_job(mysqli $db, int $job_id): ?array
{
    if ($job_id <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM rsf_job_orders WHERE id = ? AND slots_available > 0 AND (deadline IS NULL OR deadline >= CURDATE()) LIMIT 1');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $j = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $j ?: null;
}

function app_already_applied(mysqli $db, int $client_id, int $job_id): bool
{
    $stmt = $db->prepare('SELECT id FROM applications WHERE client_id = ? AND rsf_id = ? LIMIT 1');
    $stmt->bind_param('ii', $client_id, $job_id);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $ok;
}

function app_try_reserve_slot(mysqli $db, int $job_id): bool
{
    $stmt = $db->prepare('UPDATE rsf_job_orders SET slots_available = slots_available - 1 WHERE id = ? AND slots_available > 0 AND (deadline IS NULL OR deadline >= CURDATE())');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

/**
 * @return list<array{id:int,document_type:string,document_name:string,is_required:int,description:string}>
 */
function app_required_documents_for_job(mysqli $db, int $job_id): array
{
    $stmt = $db->prepare('SELECT id, document_type, document_name, is_required, description FROM rsf_required_documents WHERE rsf_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $docs = [];
    foreach ($rows as $r) {
        if (empty($r['document_type']) || empty($r['document_name'])) {
            continue;
        }
        $docs[] = [
            'id' => (int) ($r['id'] ?? 0),
            'document_type' => (string) $r['document_type'],
            'document_name' => (string) $r['document_name'],
            'is_required' => (int) ($r['is_required'] ?? 1),
            'description' => (string) ($r['description'] ?? ''),
        ];
    }

    // Backward-compatible fallback if admin did not configure job-specific docs yet.
    if ($docs === []) {
        return [
            ['id' => 0, 'document_type' => 'passport', 'document_name' => 'Passport (valid)', 'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'resume', 'document_name' => 'Resume / CV', 'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'diploma', 'document_name' => 'Diploma / TOR', 'is_required' => 1, 'description' => ''],
            ['id' => 0, 'document_type' => 'certificate', 'document_name' => 'Certificate / license', 'is_required' => 1, 'description' => ''],
        ];
    }

    return $docs;
}

/**
 * @return array<string, array{file_name: string, file_path: string, required_doc_id:int, document_type:string, document_name:string}>
 */
function app_get_session_uploads(): array
{
    $u = $_SESSION['wizard_uploads'] ?? null;

    return is_array($u) ? $u : [];
}

/**
 * @param array<string, array{file_name: string, file_path: string, required_doc_id:int, document_type:string, document_name:string}> $uploads
 */
function app_has_all_required_uploads(array $uploads, array $requiredDocs): bool
{
    foreach ($requiredDocs as $doc) {
        if (empty($doc['is_required'])) {
            continue;
        }
        $key = ((int) ($doc['id'] ?? 0) > 0) ? 'doc_' . (int) $doc['id'] : 'type_' . strtolower((string) ($doc['document_type'] ?? ''));
        if (empty($uploads[$key]['file_path'])) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $job
 */
function app_render_step2_fields(mysqli $db, array $job, int $job_id): string
{
    $questions = app_fetch_questions_for_job($db, $job_id);

    ob_start(); ?>
    <div class="wiz-card" data-wizard-step="2">
        <div class="wiz-card-head">
            <span class="wiz-step-pill">Step 2 of 4</span>
            <h3 class="wiz-title">Application questions</h3>
            <p class="wiz-sub"><?= htmlspecialchars($job['position_title'] ?? 'Position') ?> — answer all required fields.</p>
        </div>
        <form id="wizard-step2-form" class="wiz-form">
            <input type="hidden" name="job_id" value="<?= (int) $job_id ?>">
            <?php if ($questions === []): ?>
                <p class="wiz-muted">No evaluation questions are configured. You may proceed when ready.</p>
            <?php else: ?>
                <?php foreach ($questions as $q): ?>
                    <?php
                    $req = !empty($q['is_required']);
                    $qid = (int) $q['id'];
                    $label = trim((string) $q['question_text']);
                    $useTextarea = strlen($label) > 160;
                    ?>
                    <div class="wiz-field">
                        <label class="wiz-label" for="q_<?= $qid ?>">
                            <?= htmlspecialchars($label) ?>
                            <?php if ($req): ?><span class="wiz-req">*</span><?php endif; ?>
                        </label>
                        <?php if ($useTextarea): ?>
                            <textarea class="wiz-input" id="q_<?= $qid ?>" name="q_<?= $qid ?>" rows="4" <?= $req ? 'required' : '' ?>></textarea>
                        <?php else: ?>
                            <input class="wiz-input" type="text" id="q_<?= $qid ?>" name="q_<?= $qid ?>" <?= $req ? 'required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <p id="wizard-step2-err" class="wiz-error hidden"></p>
            <div class="wiz-actions">
                <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int) $job_id ?>&step=1">Back</a>
                <button type="submit" class="wiz-btn primary" id="wizard-step2-submit">Continue</button>
            </div>
        </form>
    </div>
<?php
    return (string) ob_get_clean();
}

/**
 * @return list<array{id:int,question_text:string,is_required:int,order_no:int|null}>
 */
function app_fetch_questions_for_job(mysqli $db, int $job_id): array
{
    $stmt = $db->prepare('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id = ? ORDER BY COALESCE(order_no, 999), id');
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Backward compatibility for old/global questions.
    if ($questions === []) {
        $res = $db->query('SELECT id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NULL ORDER BY COALESCE(order_no, 999), id');
        $questions = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    return $questions;
}

/**
 * @param array<string, array{file_name: string, file_path: string, required_doc_id:int, document_type:string, document_name:string}> $uploads
 * @param array<string, mixed> $job
 */
function app_render_step3_upload(array $job, int $job_id, array $uploads, array $requiredDocs): string
{
    ob_start(); ?>
    <div class="wiz-card" data-wizard-step="3">
        <div class="wiz-card-head">
            <span class="wiz-step-pill">Step 3 of 4</span>
            <h3 class="wiz-title">Upload requirements</h3>
            <p class="wiz-sub">Upload one file for each required document type (PDF, JPG, or PNG).</p>
        </div>
        <div class="wiz-form">
            <?php foreach ($requiredDocs as $doc): ?>
                <?php
                $docType = (string) ($doc['document_type'] ?? '');
                $docName = (string) ($doc['document_name'] ?? $docType);
                $reqId = (int) ($doc['id'] ?? 0);
                $isRequired = !empty($doc['is_required']);
                $key = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType);
                $has = !empty($uploads[$key]['file_path']);
                ?>
                <div class="wiz-field wiz-upload-row" data-doc-type="<?= htmlspecialchars($docType) ?>">
                    <div class="wiz-label"><?= htmlspecialchars($docName) ?> <?= $has ? '<span style="color:var(--green);font-size:11px;">(uploaded)</span>' : ($isRequired ? '<span class="wiz-req">*</span>' : '<span style="font-size:11px;color:var(--text3);">(optional)</span>') ?></div>
                    <?php if (!empty($doc['description'])): ?>
                        <p class="wiz-muted" style="margin:0 0 6px;"><?= htmlspecialchars((string) $doc['description']) ?></p>
                    <?php endif; ?>
                    <?php if ($has): ?>
                        <p class="wiz-muted" style="margin:0 0 6px;">
                            <i class="fas fa-file"></i> <?= htmlspecialchars($uploads[$key]['file_name'] ?? '') ?>
                        </p>
                    <?php endif; ?>
                    <form class="wiz-doc-upload-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="step3_upload">
                        <input type="hidden" name="job_id" value="<?= (int) $job_id ?>">
                        <input type="hidden" name="required_doc_id" value="<?= (int) $reqId ?>">
                        <input type="hidden" name="document_type" value="<?= htmlspecialchars($docType) ?>">
                        <input type="hidden" name="document_name" value="<?= htmlspecialchars($docName) ?>">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png" class="wiz-input" style="flex:1;min-width:200px;padding:8px;" required>
                            <button type="submit" class="wiz-btn primary" style="flex-shrink:0;"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            <p id="wizard-step3-err" class="wiz-error hidden"></p>
            <div class="wiz-actions">
                <a class="wiz-btn secondary" href="my-applications.php?job_id=<?= (int) $job_id ?>&step=2">Back</a>
                <button type="button" class="wiz-btn primary" id="wizard-step3-continue">Continue to review</button>
            </div>
        </div>
    </div>
<?php
    return (string) ob_get_clean();
}

/**
 * @param array<string, mixed> $job
 */
function app_render_step4_review(mysqli $db, array $job, int $job_id): string
{
    $answers = is_array($_SESSION['app_answers'] ?? null) ? $_SESSION['app_answers'] : [];
    $questions = app_fetch_questions_for_job($db, $job_id);
    $uploads = app_get_session_uploads();
    $requiredDocs = app_required_documents_for_job($db, $job_id);

    ob_start(); ?>
    <div class="wiz-card" id="wizard-step4-review" data-wizard-step="4">
        <div class="wiz-card-head">
            <span class="wiz-step-pill">Step 4 of 4</span>
            <h3 class="wiz-title">Review &amp; Submit</h3>
            <p class="wiz-sub">Review all your information, then submit your application.</p>
        </div>
        <div class="wiz-body">
            <div class="wiz-review-block">
                <h4>Position</h4>
                <p style="font-size:13px;font-weight:700;color:var(--text1);margin:0;"><?= htmlspecialchars($job['position_title'] ?? '—') ?></p>
                <p style="font-size:12px;color:var(--text3);margin:3px 0 0;"><?= htmlspecialchars($job['employer_name'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($job['worksite_country'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($job['rsf_number'] ?? '—') ?></p>
            </div>
            <?php if ($questions !== []): ?>
                <div class="wiz-review-block">
                    <h4>Your Answers</h4>
                    <?php foreach ($questions as $q):
                        $qid = (int) $q['id'];
                        $ans = (string) ($answers[$qid] ?? '—'); ?>
                        <div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--border);">
                            <div style="font-size:12px;font-weight:700;color:var(--text2);"><?= htmlspecialchars((string) $q['question_text']) ?></div>
                            <div style="font-size:13px;color:var(--text1);margin-top:3px;"><?= htmlspecialchars($ans) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="wiz-review-block">
                <h4>Uploaded Documents</h4>
                <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.5;color:var(--text2);">
                    <?php foreach ($requiredDocs as $doc):
                        $docType = (string) ($doc['document_type'] ?? '');
                        $docName = (string) ($doc['document_name'] ?? $docType);
                        $reqId = (int) ($doc['id'] ?? 0);
                        $uKey = $reqId > 0 ? 'doc_' . $reqId : 'type_' . strtolower($docType); ?>
                        <li><strong><?= htmlspecialchars($docName) ?>:</strong> <?= !empty($uploads[$uKey]['file_name']) ? htmlspecialchars($uploads[$uKey]['file_name']) : '—' ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p id="wizard-step4-err" class="wiz-error hidden"></p>
            <div class="wiz-actions">
                <button type="button" class="wiz-btn primary" id="wizard-step4-submit" data-job-id="<?= (int) $job_id ?>"><i class="fas fa-paper-plane"></i> Submit Application</button>
            </div>
        </div>
    </div>
    <div id="wizard-step4-success" class="wiz-success hidden">
        <i class="fas fa-circle-check" style="font-size:32px;color:var(--green);margin-bottom:10px;"></i>
        <h3>Application Submitted!</h3>
        <p id="wizard-step4-success-msg">Your application has been submitted for review.</p>
        <button class="wiz-btn primary" id="wizard-step4-reload">View My Applications</button>
    </div>
<?php
    return (string) ob_get_clean();
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'step1_proceed') {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    $rsf_ack = (string) ($_POST['rsf_ack'] ?? '');
    if ($rsf_ack !== '1') {
        app_json_out(['ok' => false, 'error' => 'Please confirm that you have read and understood the RSF before proceeding.']);
    }
    $job = app_fetch_valid_job($database, $job_id);
    if (!$job) {
        app_json_out(['ok' => false, 'error' => 'This job is no longer available (closed, deadline passed, or no slots left).']);
    }
    if (app_already_applied($database, $client_id, $job_id)) {
        app_json_out(['ok' => false, 'error' => 'You have already applied for this position.']);
    }

    if (isset($_SESSION['job_id']) && (int) $_SESSION['job_id'] !== $job_id) {
        unset($_SESSION['app_step'], $_SESSION['app_answers'], $_SESSION['app_checklist_ok'], $_SESSION['app_rsf_seen'], $_SESSION['wizard_uploads'], $_SESSION['app_uploads_ok']);
    }

    $_SESSION['job_id'] = $job_id;
    $_SESSION['app_step'] = 2;
    $_SESSION['app_rsf_seen'] = true;
    unset($_SESSION['app_answers'], $_SESSION['app_checklist_ok'], $_SESSION['wizard_uploads'], $_SESSION['app_uploads_ok']);

    $html = app_render_step2_fields($database, $job, $job_id);
    app_json_out(['ok' => true, 'step' => 2, 'html' => $html]);
}

if ($action === 'step2_save') {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    if (!isset($_SESSION['job_id']) || (int) $_SESSION['job_id'] !== $job_id) {
        app_json_out(['ok' => false, 'error' => 'Session expired. Open the job again from Job Opportunities.']);
    }
    if ((int) ($_SESSION['app_step'] ?? 0) < 2) {
        app_json_out(['ok' => false, 'error' => 'Complete the previous step first.']);
    }

    $job = app_fetch_valid_job($database, $job_id);
    if (!$job) {
        app_json_out(['ok' => false, 'error' => 'This job is no longer available (closed, deadline passed, or no slots left).']);
    }
    if (app_already_applied($database, $client_id, $job_id)) {
        app_json_out(['ok' => false, 'error' => 'You have already applied for this position.']);
    }

    $questions = app_fetch_questions_for_job($database, $job_id);
    $answers = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $val = trim((string) ($_POST['q_' . $qid] ?? ''));
        if (!empty($q['is_required']) && $val === '') {
            app_json_out(['ok' => false, 'error' => 'Please answer all required questions.']);
        }
        $answers[$qid] = $val;
    }

    $_SESSION['app_answers'] = $answers;
    $_SESSION['app_step'] = 3;
    unset($_SESSION['wizard_uploads'], $_SESSION['app_uploads_ok']);

    $requiredDocs = app_required_documents_for_job($database, $job_id);
    $html = app_render_step3_upload($job, $job_id, [], $requiredDocs);
    app_json_out(['ok' => true, 'step' => 3, 'html' => $html]);
}

if ($action === 'step3_upload') {
    header('Content-Type: application/json; charset=utf-8');
    $job_id = (int) ($_POST['job_id'] ?? 0);
    $doc_type = trim((string) ($_POST['document_type'] ?? ''));
    $doc_name = trim((string) ($_POST['document_name'] ?? ''));
    $required_doc_id = (int) ($_POST['required_doc_id'] ?? 0);

    if (!isset($_SESSION['job_id']) || (int) $_SESSION['job_id'] !== $job_id) {
        app_json_out(['ok' => false, 'error' => 'Session expired.']);
    }
    if ((int) ($_SESSION['app_step'] ?? 0) < 3) {
        app_json_out(['ok' => false, 'error' => 'Complete the previous steps first.']);
    }
    $job = app_fetch_valid_job($database, $job_id);
    if (!$job) {
        app_json_out(['ok' => false, 'error' => 'This job is no longer available (closed, deadline passed, or no slots left).']);
    }
    $requiredDocs = app_required_documents_for_job($database, $job_id);
    $allowedByType = [];
    $allowedById = [];
    foreach ($requiredDocs as $d) {
        $t = strtolower((string) ($d['document_type'] ?? ''));
        if ($t !== '') {
            $allowedByType[$t] = $d;
        }
        if ((int) ($d['id'] ?? 0) > 0) {
            $allowedById[(int) $d['id']] = $d;
        }
    }
    $picked = null;
    if ($required_doc_id > 0 && isset($allowedById[$required_doc_id])) {
        $picked = $allowedById[$required_doc_id];
    } elseif ($doc_type !== '' && isset($allowedByType[strtolower($doc_type)])) {
        $picked = $allowedByType[strtolower($doc_type)];
    }
    if (!$picked) {
        app_json_out(['ok' => false, 'error' => 'Invalid document type for this job order.']);
    }
    $doc_type = (string) ($picked['document_type'] ?? $doc_type);
    if ($doc_name === '') {
        $doc_name = (string) ($picked['document_name'] ?? $doc_type);
    }
    $required_doc_id = (int) ($picked['id'] ?? 0);

    if (empty($_FILES['doc_file']) || (int) ($_FILES['doc_file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
        app_json_out(['ok' => false, 'error' => 'Please choose a valid file to upload.']);
    }

    $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExt, true)) {
        app_json_out(['ok' => false, 'error' => 'Invalid file type. Allowed: PDF, JPG, PNG.']);
    }

    $upload_dir = __DIR__ . '/../uploads/wizard_temp/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $fname = 'w_' . $client_id . '_' . $job_id . '_' . $doc_type . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs = $upload_dir . $fname;
    if (!move_uploaded_file($_FILES['doc_file']['tmp_name'], $abs)) {
        app_json_out(['ok' => false, 'error' => 'Upload failed. Please try again.']);
    }

    $rel = 'uploads/wizard_temp/' . $fname;
    $orig = (string) ($_FILES['doc_file']['name'] ?? 'file');

    if (!isset($_SESSION['wizard_uploads']) || !is_array($_SESSION['wizard_uploads'])) {
        $_SESSION['wizard_uploads'] = [];
    }
    $uploadKey = $required_doc_id > 0 ? 'doc_' . $required_doc_id : 'type_' . strtolower($doc_type);
    $_SESSION['wizard_uploads'][$uploadKey] = [
        'file_name' => $orig,
        'file_path' => $rel,
        'required_doc_id' => $required_doc_id,
        'document_type' => $doc_type,
        'document_name' => $doc_name,
    ];

    $uploads = app_get_session_uploads();
    $html = app_render_step3_upload($job, $job_id, $uploads, $requiredDocs);
    app_json_out(['ok' => true, 'html' => $html]);
}

if ($action === 'step3_continue') {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    if (!isset($_SESSION['job_id']) || (int) $_SESSION['job_id'] !== $job_id) {
        app_json_out(['ok' => false, 'error' => 'Session expired.']);
    }
    if ((int) ($_SESSION['app_step'] ?? 0) < 3) {
        app_json_out(['ok' => false, 'error' => 'Complete the previous steps first.']);
    }

    $job = app_fetch_valid_job($database, $job_id);
    if (!$job) {
        app_json_out(['ok' => false, 'error' => 'This job is no longer available (closed, deadline passed, or no slots left).']);
    }

    $uploads = app_get_session_uploads();
    $requiredDocs = app_required_documents_for_job($database, $job_id);
    if (!app_has_all_required_uploads($uploads, $requiredDocs)) {
        app_json_out(['ok' => false, 'error' => 'Please upload all required documents before continuing.']);
    }

    $_SESSION['app_uploads_ok'] = true;
    $_SESSION['app_step'] = 4;

    $html = app_render_step4_review($database, $job, $job_id);
    app_json_out(['ok' => true, 'step' => 4, 'html' => $html]);
}

if ($action === 'step4_submit') {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    if (!isset($_SESSION['job_id']) || (int) $_SESSION['job_id'] !== $job_id) {
        app_json_out(['ok' => false, 'error' => 'Session expired.']);
    }
    if ((int) ($_SESSION['app_step'] ?? 0) < 4) {
        app_json_out(['ok' => false, 'error' => 'Complete all steps before submitting.']);
    }

    $job = app_fetch_valid_job($database, $job_id);
    if (!$job) {
        app_json_out(['ok' => false, 'error' => 'This job is no longer available (closed, deadline passed, or no slots left).']);
    }
    if (app_already_applied($database, $client_id, $job_id)) {
        app_json_out(['ok' => false, 'error' => 'You have already applied for this position.']);
    }

    $answers = $_SESSION['app_answers'] ?? null;
    if (!is_array($answers)) {
        app_json_out(['ok' => false, 'error' => 'Application data is missing. Please start again from Job Opportunities.']);
    }
    if (empty($_SESSION['app_rsf_seen'])) {
        app_json_out(['ok' => false, 'error' => 'Invalid application state.']);
    }
    $requiredDocs = app_required_documents_for_job($database, $job_id);
    if (empty($_SESSION['app_uploads_ok']) || !app_has_all_required_uploads(app_get_session_uploads(), $requiredDocs)) {
        app_json_out(['ok' => false, 'error' => 'Required documents were not uploaded.']);
    }

    $uploads = app_get_session_uploads();
    $app_dir = __DIR__ . '/../uploads/app_documents/';
    if (!is_dir($app_dir)) {
        mkdir($app_dir, 0755, true);
    }

    $database->begin_transaction();
    try {
        $stmt = $database->prepare("INSERT INTO applications (client_id, rsf_id, status) VALUES (?, ?, 'pending')");
        $stmt->bind_param('ii', $client_id, $job_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('insert');
        }
        $app_id = (int) $database->insert_id;
        $stmt->close();

        $stmt = $database->prepare('INSERT INTO application_answers (application_id, question_id, answer) VALUES (?, ?, ?)');
        foreach ($answers as $qid => $ans) {
            $qid = (int) $qid;
            $ans = (string) $ans;
            $stmt->bind_param('iis', $app_id, $qid, $ans);
            $stmt->execute();
        }
        $stmt->close();

        $stmt = $database->prepare('INSERT INTO application_documents (application_id, document_type, required_doc_id, file_name, file_path) VALUES (?, ?, ?, ?, ?)');
        foreach ($uploads as $type => $info) {
            if (!is_array($info) || empty($info['file_path'])) {
                continue;
            }
            $src = __DIR__ . '/../' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $info['file_path']);
            if (!is_file($src)) {
                throw new RuntimeException('missing upload');
            }
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $destName = $app_id . '_' . time() . '_' . preg_replace('/[^a-z0-9_]/i', '', (string) $type) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $destAbs = $app_dir . $destName;
            if (!copy($src, $destAbs)) {
                throw new RuntimeException('copy');
            }
            $relPath = 'uploads/app_documents/' . $destName;
            $origName = (string) ($info['file_name'] ?? 'document');
            $docType = (string) ($info['document_type'] ?? $type);
            $docReqId = (int) ($info['required_doc_id'] ?? 0);
            $docReqNullable = $docReqId > 0 ? $docReqId : null;
            $stmt->bind_param('isiss', $app_id, $docType, $docReqNullable, $origName, $relPath);
            $stmt->execute();
        }
        $stmt->close();

        if (!application_status_change($database, $app_id, 'pending', $client_email, 'Application submitted')) {
            throw new RuntimeException('status');
        }

        $database->commit();
    } catch (Throwable $e) {
        $database->rollback();
        if ($e->getMessage() === 'slot') {
            app_json_out(['ok' => false, 'error' => 'This job has reached its application limit. No slots are available now.']);
        }
        app_json_out(['ok' => false, 'error' => 'Could not save your application. Please try again.']);
    }

    foreach ($uploads as $info) {
        if (!is_array($info) || empty($info['file_path'])) {
            continue;
        }
        $p = __DIR__ . '/../' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $info['file_path']);
        if (is_file($p) && strpos((string) $info['file_path'], 'wizard_temp') !== false) {
            @unlink($p);
        }
    }

    unset(
        $_SESSION['job_id'],
        $_SESSION['app_step'],
        $_SESSION['app_answers'],
        $_SESSION['app_checklist_ok'],
        $_SESSION['app_rsf_seen'],
        $_SESSION['wizard_uploads'],
        $_SESSION['app_uploads_ok']
    );

    // Notify admins about new application submission
    notify_admins(
        $database,
        'New Application Submitted',
        get_applicant_name($database, $app_id) . " has submitted a new application for {$job['position_title']} ({$job['rsf_number']}) at {$job['employer_name']}.",
        'new_application',
        $app_id
    );

    // Notify evaluators — case is unclaimed, no one is assigned yet
    notify_evaluators(
        $database,
        'New Application Available',
        get_applicant_name($database, $app_id) . " submitted a new application for {$job['position_title']} ({$job['rsf_number']}). It is available for evaluation.",
        'new_application',
        $app_id
    );
    app_json_out(['ok' => true, 'message' => 'Your application was submitted successfully. An evaluator will review it soon.']);
}

app_json_out(['ok' => false, 'error' => 'Unknown action.']);
