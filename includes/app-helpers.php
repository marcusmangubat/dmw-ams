<?php

/**
 * Application workflow helpers
 * Requires: $database (mysqli), included after db.php
 */

/**
 * Whether the client may change answers, documents, or use resubmit (server-side gate).
 * Editable: unassign (submitted, not yet assigned), for_compliance, resubmitted, and in-progress pending.
 * Legacy DB values unassigned / assigned are accepted for compatibility.
 *
 * @param string|null $status applications.status
 */
function canEditApplication($status)
{
    $s = strtolower(trim((string) $status));
    if ($s === 'unassigned') {
        $s = 'unassign';
    }
    $editable = ['unassign', 'for_compliance', 'resubmitted', 'pending'];
    return in_array($s, $editable, true);
}

/**
 * Update application status and log to status_history
 * @param mysqli $db
 * @param int $application_id
 * @param string $new_status
 * @param string $changed_by (webuser.email)
 * @param string|null $reason
 * @return bool
 */
function application_status_change($db, $application_id, $new_status, $changed_by, $reason = null)
{
    $application_id = (int) $application_id;
    $new_status = trim($new_status);
    $changed_by = trim($changed_by);
    if (!$application_id || !$new_status || !$changed_by) return false;

    // Get current status
    $stmt = $db->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $from_status = $row['status'];
    if ($from_status === $new_status) return true; // No change

    // Update applications
    $stmt = $db->prepare("UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $new_status, $application_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    if (
        $new_status === 'approved'
        && $from_status !== 'approved'
    ) {
        $stmt = $db->prepare('SELECT rsf_id FROM applications WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $application_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['rsf_id'])) {
            $rsf_id = (int) $row['rsf_id'];
            $stmt = $db->prepare('UPDATE rsf_job_orders SET slots_available = slots_available - 1 WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $rsf_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // Insert status_history
    $stmt = $db->prepare("INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $application_id, $from_status, $new_status, $changed_by, $reason);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Statuses considered "unclaimed" — submitted but no evaluator has started
 * working the case yet. Includes legacy values already used elsewhere in
 * the codebase (canEditApplication(), applications.php stats) for compatibility.
 */
const APPLICATION_CLAIMABLE_STATUSES = ['pending', 'assign', 'assigned', 'resubmitted'];

/**
 * Whether $evaluator_id currently has exclusive control of this application.
 * This is the single source of truth for evaluator-side access control —
 * check this before allowing a view or a POST action, not just the
 * `assignments` table (which is now a historical/audit record, not the lock).
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @return bool
 */
function evaluatorOwnsApplication($db, $application_id, $evaluator_id)
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) return false;

    $stmt = $db->prepare("
        SELECT 1 FROM applications
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $owns = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $owns;
}

/**
 * Atomically claim an unclaimed application for one evaluator.
 * Locks the row with SELECT ... FOR UPDATE inside a transaction so two
 * evaluators opening the same application at the same instant cannot both
 * win the claim — the second one is told it was already taken.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @param string $evaluator_email
 * @return array{ok:bool, reason?:string, holder_name?:string|null, status?:string, already_owned?:bool}
 */
function claimApplication($db, $application_id, $evaluator_id, $evaluator_email)
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    $db->begin_transaction();

    $stmt = $db->prepare("
        SELECT a.status, a.handling_party, a.current_evaluator_id, e.full_name AS holder_name
        FROM applications a
        LEFT JOIN evaluators e ON a.current_evaluator_id = e.eid
        WHERE a.id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $db->rollback();
        return ['ok' => false, 'reason' => 'not_found'];
    }

    // Already owned by this same evaluator — nothing to do, just confirm.
    if ($row['handling_party'] === 'evaluator' && (int) $row['current_evaluator_id'] === $evaluator_id) {
        $db->commit();
        return ['ok' => true, 'already_owned' => true];
    }

    $isClaimable = $row['handling_party'] === null
        && in_array($row['status'], APPLICATION_CLAIMABLE_STATUSES, true);

    if (!$isClaimable) {
        $db->rollback();
        return [
            'ok' => false,
            'reason' => 'already_claimed',
            'holder_name' => $row['holder_name'],
            'status' => $row['status'],
        ];
    }

    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'under_evaluation',
            current_evaluator_id = ?,
            handling_party = 'evaluator',
            locked_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $evaluator_id, $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'under_evaluation', ?, 'Evaluator claimed application')
    ");
    $stmt->bind_param("iss", $application_id, $from_status, $evaluator_email);
    $stmt->execute();
    $stmt->close();

    // Close out any stale active assignment left by a different evaluator.
    $stmt = $db->prepare("
        UPDATE assignments SET status = 'reassigned'
        WHERE application_id = ? AND status = 'active' AND evaluator_id != ?
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("SELECT 1 FROM assignments WHERE application_id = ? AND evaluator_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $hasActive = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$hasActive) {
        $stmt = $db->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
        $stmt->bind_param("ii", $application_id, $evaluator_id);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();
    return ['ok' => true];
}

/**
 * Evaluator sends the case to Admin for compliance follow-up.
 * current_evaluator_id is deliberately left unchanged so Admin can route
 * the case back to this exact evaluator later.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $evaluator_id
 * @param string $evaluator_email
 * @param string|null $reason
 * @return bool
 */
function sendToCompliance($db, $application_id, $evaluator_id, $evaluator_email, $reason = null)
{
    $application_id = (int) $application_id;
    $evaluator_id = (int) $evaluator_id;
    if (!$application_id || !$evaluator_id) return false;

    $stmt = $db->prepare("
        SELECT status FROM applications
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'for_compliance', handling_party = 'admin', updated_at = NOW()
        WHERE id = ? AND current_evaluator_id = ? AND handling_party = 'evaluator'
    ");
    $stmt->bind_param("ii", $application_id, $evaluator_id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return false;

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'for_compliance', ?, ?)
    ");
    $stmt->bind_param("isss", $application_id, $from_status, $evaluator_email, $reason);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Statuses where the case is presented to Admin for the compliance-style
 * handoff panel (Reopen to Evaluator / Evaluate Directly / Reassign):
 * - for_compliance: evaluator explicitly routed the case to Admin
 *   (handling_party = 'admin', so it's exclusively Admin's to act on).
 * - resubmitted: applicant resubmitted after a compliance request and the
 *   case sits back in the evaluator claim pool (handling_party IS NULL).
 *   Admin may still act on it directly instead of waiting for a claim.
 */
const ADMIN_HANDOFF_STATUSES = ['for_compliance', 'resubmitted'];

/**
 * ADMIN ACTION 1: reopen a for_compliance/resubmitted case back to the SAME
 * evaluator who originally handled it.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param string $admin_email
 * @return bool
 */
function adminReopenToEvaluator($db, $application_id, $admin_email)
{
    $application_id = (int) $application_id;
    if (!$application_id) return false;

    $stmt = $db->prepare("
        SELECT status, current_evaluator_id FROM applications
        WHERE id = ?
          AND (
                (status = 'for_compliance' AND handling_party = 'admin')
             OR  status = 'resubmitted'
          )
        LIMIT 1
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['current_evaluator_id']) return false;

    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'under_evaluation', handling_party = 'evaluator', locked_at = NOW(), updated_at = NOW()
        WHERE id = ?
          AND (
                (status = 'for_compliance' AND handling_party = 'admin')
             OR  status = 'resubmitted'
          )
    ");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return false;

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'under_evaluation', ?, 'Admin reopened case to original evaluator')
    ");
    $stmt->bind_param("iss", $application_id, $from_status, $admin_email);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * ADMIN ACTION 2: Admin evaluates the case directly instead of sending it
 * back to the evaluator. Decision is recorded under the Admin's identity.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $admin_id
 * @param string $admin_email
 * @param string $decision 'approved' or 'disapproved'
 * @param string|null $comments
 * @return bool
 */
function adminEvaluateDirectly($db, $application_id, $admin_id, $admin_email, $decision, $comments = null)
{
    $application_id = (int) $application_id;
    $admin_id = (int) $admin_id;
    $decision = trim((string) $decision);
    if (!$application_id || !$admin_id || !in_array($decision, ['approved', 'disapproved'], true)) {
        return false;
    }

    $stmt = $db->prepare("SELECT status, handling_party FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $isForCompliance = $row && $row['status'] === 'for_compliance' && $row['handling_party'] === 'admin';
    $isResubmitted   = $row && $row['status'] === 'resubmitted';
    if (!$row || (!$isForCompliance && !$isResubmitted)) {
        return false;
    }

    $stmt = $db->prepare("
        INSERT INTO evaluations (application_id, admin_id, evaluation_type, decision, comments)
        VALUES (?, ?, 'admin', ?, ?)
    ");
    $stmt->bind_param("iiss", $application_id, $admin_id, $decision, $comments);
    $stmt->execute();
    $stmt->close();

    if (!application_status_change($db, $application_id, $decision, $admin_email, "Admin decision: $decision" . ($comments ? " — $comments" : ''))) {
        return false;
    }

    if ($comments !== null) {
        $stmt = $db->prepare("UPDATE applications SET notes = ? WHERE id = ?");
        $stmt->bind_param("si", $comments, $application_id);
        $stmt->execute();
        $stmt->close();
    }

    // Case is resolved — release the lock and close out the assignment record.
    $stmt = $db->prepare("UPDATE applications SET handling_party = NULL WHERE id = ?");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE assignments SET status = 'completed' WHERE application_id = ? AND status = 'active'");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * ADMIN ACTION (supporting): reassign a case to a different evaluator —
 * covers "...until the evaluation process is completed or reassigned."
 * Works from for_compliance or under_evaluation.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param int $new_evaluator_id
 * @param string $admin_email
 * @return bool
 */
function adminReassignApplication($db, $application_id, $new_evaluator_id, $admin_email)
{
    $application_id = (int) $application_id;
    $new_evaluator_id = (int) $new_evaluator_id;
    if (!$application_id || !$new_evaluator_id) return false;

    $stmt = $db->prepare("SELECT status FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    $from_status = $row['status'];

    $stmt = $db->prepare("
        UPDATE applications
        SET status = 'under_evaluation', current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ii", $new_evaluator_id, $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE assignments SET status = 'reassigned' WHERE application_id = ? AND status = 'active'");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
    $stmt->bind_param("ii", $application_id, $new_evaluator_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason)
        VALUES (?, ?, 'under_evaluation', ?, 'Reassigned by admin')
    ");
    $stmt->bind_param("iss", $application_id, $from_status, $admin_email);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Create a client notification for a specific application.
 * Works with notifications table schema:
 * (user_email, usertype, application_id, title, message, type, is_read, read_at, created_at)
 *
 * @param mysqli $db
 * @param int $application_id
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @return bool
 */
function notify_client_for_application($db, $application_id, $title, $message, $type = 'application_status')
{
    $application_id = (int) $application_id;
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if (!$application_id || $title === '' || $message === '') return false;

    $stmt = $db->prepare("
        SELECT c.email
        FROM applications a
        JOIN clients c ON a.client_id = c.cid
        WHERE a.id = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['email'])) return false;

    $user_email = (string) $row['email'];
    $usertype = 'client';
    $stmt = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Create an evaluator notification for a specific application.
 * Notifies the assigned evaluator about application updates.
 *
 * @param mysqli $db
 * @param int $application_id
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @return bool
 */
function notify_evaluator_for_application($db, $application_id, $title, $message, $type = 'application_update')
{
    $application_id = (int) $application_id;
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if (!$application_id || $title === '' || $message === '') return false;

    // Get the assigned evaluator's email
    $stmt = $db->prepare("
        SELECT e.email
        FROM assignments a
        JOIN evaluators e ON a.evaluator_id = e.eid
        WHERE a.application_id = ? AND a.status = 'active'
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['email'])) return false;

    $user_email = (string) $row['email'];
    $usertype = 'evaluator';
    $stmt = $db->prepare("
        INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Create an admin notification for system-wide events.
 * Notifies all admins about important system events.
 *
 * @param mysqli $db
 * @param string $title
 * @param string $message
 * @param string|null $type
 * @param int|null $application_id Optional application context
 * @return bool
 */
function notify_admins($db, $title, $message, $type = 'system', $application_id = null)
{
    $title = trim((string) $title);
    $message = trim((string) $message);
    $type = $type !== null ? trim((string) $type) : null;
    if ($title === '' || $message === '') return false;

    // Get all admin emails
    $stmt = $db->prepare("SELECT email FROM admins");
    if (!$stmt) return false;
    $stmt->execute();
    $result = $stmt->get_result();
    $admins = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($admins)) return false;

    $usertype = 'admin';
    $application_id = $application_id ? (int) $application_id : null;

    $success = true;
    foreach ($admins as $admin) {
        $user_email = (string) $admin['email'];
        $stmt = $db->prepare("
            INSERT INTO notifications (user_email, usertype, application_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        if (!$stmt) {
            $success = false;
            continue;
        }
        $stmt->bind_param("ssisss", $user_email, $usertype, $application_id, $title, $message, $type);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) $success = false;
    }

    return $success;
}
