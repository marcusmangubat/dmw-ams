<?php
require_once '../db.php';

$app_id = (int) ($_GET['app_id'] ?? 0);
if ($app_id <= 0) {
    echo '<p class="text-red-500 text-center py-8">Invalid Application ID</p>';
    exit;
}

// Fetch basic application info
$stmt = $database->prepare("
    SELECT a.id, a.status, a.submitted_at, a.notes,
           CONCAT(c.first_name, ' ', COALESCE(c.middle_name, ''), ' ', c.last_name) as applicant_name,
           r.rsf_number, r.position_title
    FROM applications a
    JOIN clients c ON a.client_id = c.cid
    JOIN rsf_job_orders r ON a.rsf_id = r.id
    WHERE a.id = ?
");
$stmt->bind_param("i", $app_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    echo '<p class="text-red-500">Application not found.</p>';
    exit;
}
?>

<div class="mb-6">
    <div class="flex justify-between items-start">
        <div>
            <div class="font-semibold text-lg"><?= htmlspecialchars($app['applicant_name']) ?></div>
            <div class="text-sm text-gray-500"><?= htmlspecialchars($app['rsf_number']) ?> — <?= htmlspecialchars($app['position_title']) ?></div>
        </div>
        <div class="text-right">
            <span class="inline-block px-4 py-1 rounded-2xl text-xs font-medium bg-indigo-100 text-indigo-700">
                <?= str_replace('_', ' ', ucfirst($app['status'])) ?>
            </span>
        </div>
    </div>
</div>

<h4 class="font-semibold text-gray-700 mb-4 border-b pb-2">Full Timeline & Details</h4>

<div class="space-y-6">

    <?php
    // 1. Status History
    $res = $database->query("
    SELECT from_status, to_status, changed_by, changed_at, reason 
    FROM status_history 
    WHERE application_id = $app_id 
    ORDER BY changed_at DESC
");
    $has_content = false;

    while ($row = $res->fetch_assoc()) {
        $has_content = true;
    ?>
        <div class="pl-5 border-l-4 border-blue-500 bg-blue-50 rounded-2xl p-5">
            <div class="text-xs text-gray-500">
                <?= date('M d, Y • g:i A', strtotime($row['changed_at'])) ?>
            </div>
            <div class="mt-1 font-medium">
                Status changed:
                <span class="line-through text-gray-400"><?= htmlspecialchars($row['from_status'] ?? '—') ?></span>
                →
                <span class="text-emerald-600 font-semibold"><?= htmlspecialchars($row['to_status']) ?></span>
            </div>
            <?php if ($row['reason']): ?>
                <div class="text-sm text-gray-600 mt-2"><?= htmlspecialchars($row['reason']) ?></div>
            <?php endif; ?>
            <div class="text-xs text-gray-500 mt-3">by <?= htmlspecialchars($row['changed_by']) ?></div>
        </div>
    <?php
    }

    // 2. Evaluations
    $res = $database->query("
    SELECT evaluation_type, decision, comments, evaluated_at 
    FROM evaluations 
    WHERE application_id = $app_id 
    ORDER BY evaluated_at DESC
");
    while ($row = $res->fetch_assoc()) {
        $has_content = true;
    ?>
        <div class="pl-5 border-l-4 border-purple-500 bg-purple-50 rounded-2xl p-5">
            <div class="text-xs text-gray-500">
                <?= date('M d, Y • g:i A', strtotime($row['evaluated_at'])) ?>
            </div>
            <div class="font-medium text-purple-700">
                <?= ucfirst($row['evaluation_type'] ?? 'Evaluation') ?> —
                <span class="font-semibold"><?= str_replace('_', ' ', ucfirst($row['decision'])) ?></span>
            </div>
            <?php if (!empty($row['comments'])): ?>
                <div class="mt-3 text-gray-700 italic">"<?= htmlspecialchars($row['comments']) ?>"</div>
            <?php endif; ?>
        </div>
    <?php
    }

    // 3. Document Comments
    $res = $database->query("
    SELECT dc.comment, dc.created_at, dc.commented_by, ad.document_type, ad.file_name
    FROM document_comments dc
    JOIN application_documents ad ON dc.document_id = ad.id
    WHERE ad.application_id = $app_id 
    ORDER BY dc.created_at DESC
");
    while ($row = $res->fetch_assoc()) {
        $has_content = true;
    ?>
        <div class="pl-5 border-l-4 border-rose-500 bg-rose-50 rounded-2xl p-5">
            <div class="text-xs text-gray-500">
                <?= date('M d, Y • g:i A', strtotime($row['created_at'])) ?>
            </div>
            <div class="font-medium text-rose-600">
                Comment on <?= htmlspecialchars($row['document_type']) ?>
            </div>
            <div class="mt-2 text-gray-700">"<?= htmlspecialchars($row['comment']) ?>"</div>
            <div class="text-xs text-gray-500 mt-3">by <?= htmlspecialchars($row['commented_by']) ?></div>
        </div>
    <?php
    }

    if (!$has_content) {
        echo '<p class="text-gray-500 text-center py-10">No history or comments recorded yet for this application.</p>';
    }
    ?>

</div>

<!-- Extra: Show Application Answers if any -->
<h4 class="font-semibold text-gray-700 mt-10 mb-4 border-b pb-2">Application Answers</h4>
<?php
$res = $database->query("
    SELECT q.question_text, aa.answer 
    FROM application_answers aa
    JOIN questions q ON aa.question_id = q.id
    WHERE aa.application_id = $app_id
    ORDER BY q.order_no
");
if ($res->num_rows > 0):
?>
    <div class="space-y-4">
        <?php while ($row = $res->fetch_assoc()): ?>
            <div>
                <div class="text-xs uppercase tracking-widest text-gray-500"><?= htmlspecialchars($row['question_text']) ?></div>
                <div class="font-medium"><?= htmlspecialchars($row['answer'] ?? '—') ?></div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <p class="text-gray-500">No additional answers provided.</p>
<?php endif; ?>