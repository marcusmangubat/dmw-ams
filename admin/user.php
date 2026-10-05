<?php
// user.php
session_start();
require_once '../db.php';

require '../phpmailer/src/PHPMailer.php';
require '../phpmailer/src/SMTP.php';
require '../phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$error = '';
$success = '';

//  SEND EVALUATOR INVITATION 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invite') {
    $email     = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (empty($full_name)) {
        $error = "Full name is required.";
    } elseif (empty($phone) || !preg_match('/^(09|\+639)\d{9}$/', $phone)) {
        $error = "Please enter a valid Philippine cellphone number (e.g. 09171234567 or +639171234567)";
    } else {
        $stmt = $database->prepare("SELECT email FROM webuser WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "This email is already registered.";
        } else {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $database->prepare("INSERT INTO invitations 
                (email, usertype, full_name, phone, token, expires_at, created_by) 
                VALUES (?, 'evaluator', ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $email, $full_name, $phone, $token, $expires, $_SESSION['user_email']);

            if ($stmt->execute()) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'mgmangubat@ccc.edu.ph';
                    $mail->Password   = 'oenv xscw mokz rohw';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('no-reply@dmw.gov.ph', 'DMW AMS Admin');
                    $mail->addAddress($email, $full_name);

                    $mail->isHTML(true);
                    $mail->Subject = 'Invitation to DMW Application Management System - Evaluator';

                    $invite_link = "http://localhost/capstone/set-password.php?token=" . $token; //papalitan pag may domain na

                    $mail->Body = "
                    <div style='font-family: Inter, system-ui, sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; background:#f8fafc; border-radius:16px;'>
                        <h2 style='color: #1e40af;'>Hello {$full_name},</h2>
                        <p>You have been invited to join the <strong>DMW Application Management System</strong> as an <strong>Evaluator</strong>.</p>
                        <p>Please click the button below to set your password and activate your account:</p>
                        <a href='{$invite_link}' style='display:inline-block; background:#1e40af; color:white; padding:14px 32px; text-decoration:none; border-radius:12px; font-weight:600; margin:25px 0;'>
                            Set My Password
                        </a>
                        <p style='color:#666; font-size:14px;'>This invitation link will expire in 24 hours.</p>
                        <hr style='margin:30px 0; border-color:#e5e7eb;'>
                        <p style='font-size:12px; color:#64748b;'>Department of Migrant Workers - Regional Office IV-A</p>
                    </div>";

                    $mail->send();
                    $success = "Invitation email successfully sent to <strong>" . htmlspecialchars($email) . "</strong>!";

                    /*notify_admins(
                        $database,
                        'Evaluator Invitation Sent',
                        "An invitation has been sent to {$full_name} ({$email}) to join as an evaluator.",
                        'evaluator_invited'
                    );*/
                } catch (Exception $e) {
                    $error = "Invitation saved, but email failed to send. Error: " . $mail->ErrorInfo;
                }
            } else {
                $error = "Failed to save invitation record.";
            }
        }
    }
}

// Fetch Evaluators
$stmt = $database->prepare("SELECT e.full_name, e.email, e.phone, e.created_at 
                            FROM evaluators e 
                            JOIN webuser w ON e.email = w.email 
                            ORDER BY e.created_at DESC");
$stmt->execute();
$evaluators = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// Fetch pending invitations
$stmt = $database->prepare("
    SELECT i.email, i.full_name, i.phone, i.expires_at, i.created_at 
    FROM invitations i
    WHERE i.used = 0 
      AND i.expires_at >= NOW() 
      AND i.usertype = 'evaluator'
      AND NOT EXISTS (
          SELECT 1 FROM webuser w WHERE w.email = i.email
      )
    ORDER BY i.created_at DESC
");
$stmt->execute();
$pending_invitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch expired invitations
$stmt = $database->prepare("
    SELECT i.email, i.full_name, i.phone, i.expires_at, i.created_at 
    FROM invitations i
    WHERE i.used = 0 
      AND i.expires_at < NOW() 
      AND i.usertype = 'evaluator'
      AND NOT EXISTS (
          SELECT 1 FROM webuser w WHERE w.email = i.email
      )
    ORDER BY i.expires_at DESC
");
$stmt->execute();
$expired_invitations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$expired_count = count($expired_invitations);

// Stats
$total_evaluators = count($evaluators);
$pending_count    = count($pending_invitations);
$newest_joined    = !empty($evaluators) ? date('M d', strtotime($evaluators[0]['created_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluators – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        :root {
            --navy: #0b1f4b;
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
            --indigo: #4f46e5;
            --green: #059669;
            --amber: #d97706;
            --red: #dc2626;
            --card: #ffffff;
            --surface: #f8fafc;
            --border: #e2e8f0;
            --text1: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--surface);
            color: var(--text1);
        }

        /* ── Stats strip ── */
        .stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
            animation: fadeUp .4s both;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .stat-card.blue::before {
            background: linear-gradient(90deg, var(--blue), var(--blue-lt));
        }

        .stat-card.green::before {
            background: linear-gradient(90deg, var(--green), #34d399);
        }

        .stat-card.amber::before {
            background: linear-gradient(90deg, var(--amber), #fbbf24);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon.blue {
            background: #eff6ff;
            color: var(--blue);
        }

        .stat-icon.green {
            background: #ecfdf5;
            color: var(--green);
        }

        .stat-icon.amber {
            background: #fffbeb;
            color: var(--amber);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text1);
            line-height: 1;
        }

        .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text3);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: 4px;
        }

        /* ── Section tabs ── */
        .tab-bar {
            display: flex;
            gap: 4px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 5px;
            margin-bottom: 18px;
            width: fit-content;
        }

        .tab-btn {
            padding: 8px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Sora', sans-serif;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text3);
            background: transparent;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .tab-btn.active {
            background: var(--card);
            color: var(--navy);
            box-shadow: 0 1px 6px rgba(0, 0, 0, .08);
        }

        .tab-badge {
            background: var(--amber);
            color: #fff;
            border-radius: 20px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
        }

        /* ── Main card ── */
        .main-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            animation: fadeUp .5s .1s both;
        }

        .main-card-head {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        }

        .main-card-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--navy);
        }

        .main-card-sub {
            font-size: 11.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 1px;
        }

        /* ── Search bar ── */
        .search-wrap {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .search-input {
            width: 100%;
            max-width: 340px;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Sora', sans-serif;
            font-size: 12.5px;
            background: var(--card);
            color: var(--text1);
            position: relative;
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            left: 34px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            font-size: 12px;
            pointer-events: none;
        }

        /* ── Evaluator table ── */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            padding: 12px 20px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text3);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }

        tbody tr:last-child {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #f8faff;
        }

        tbody td {
            padding: 14px 20px;
            font-size: 13px;
            color: var(--text2);
            vertical-align: middle;
        }

        /* Evaluator avatar */
        .ev-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
        }

        .ev-name {
            font-weight: 700;
            font-size: 13.5px;
            color: var(--text1);
        }

        .ev-email {
            font-size: 11.5px;
            color: var(--text3);
            margin-top: 1px;
        }

        /* Status badge */
        .badge-active {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecfdf5;
            color: var(--green);
            border: 1px solid #a7f3d0;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-active::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--green);
            animation: pulse-dot 2s infinite;
        }

        .badge-pending {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fffbeb;
            color: var(--amber);
            border: 1px solid #fde68a;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .badge-expired {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fecaca;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 10.5px;
            font-weight: 700;
        }

        /* Empty state */
        .empty-state {
            padding: 60px 24px;
            text-align: center;
        }

        .empty-icon {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #eff6ff, #eef2ff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--blue-lt);
            margin: 0 auto 18px;
        }

        .empty-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--text1);
            margin-bottom: 6px;
        }

        .empty-sub {
            font-size: 13px;
            color: var(--text3);
            margin-bottom: 22px;
        }

        /* ── Invite button ── */
        .invite-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
            color: #fff;
            padding: 10px 22px;
            border-radius: 12px;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 4px 14px rgba(29, 78, 216, .3);
            position: relative;
            overflow: hidden;
        }

        .invite-btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .15), transparent);
            transform: skewX(-20deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            to {
                left: 160%;
            }
        }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 75, .55);
            backdrop-filter: blur(4px);
            z-index: 300;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--card);
            border-radius: 22px;
            width: 100%;
            max-width: 460px;
            overflow: hidden;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .18);
            animation: modalPop .25s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes modalPop {
            from {
                opacity: 0;
                transform: scale(.92) translateY(20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            padding: 22px 26px 18px;
            background: linear-gradient(135deg, var(--navy) 0%, #1e3a8a 100%);
            position: relative;
        }

        .modal-header h3 {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
        }

        .modal-header p {
            font-size: 12px;
            color: rgba(255, 255, 255, .6);
            margin-top: 3px;
        }

        .modal-close {
            position: absolute;
            top: 18px;
            right: 20px;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .15);
            border: none;
            cursor: pointer;
            color: #fff;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, .25);
        }

        .modal-body {
            padding: 24px 26px;
        }

        .modal-field {
            margin-bottom: 18px;
        }

        .modal-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text3);
            margin-bottom: 7px;
        }

        .modal-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            color: var(--text1);
            background: var(--surface);
            transition: border-color .2s;
        }

        .modal-input:focus {
            outline: none;
            border-color: var(--blue-lt);
            background: #fff;
        }

        .modal-hint {
            font-size: 11px;
            color: var(--text3);
            margin-top: 5px;
        }

        .modal-footer {
            padding: 16px 26px 22px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-cancel {
            padding: 10px 20px;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text2);
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
        }

        .btn-cancel:hover {
            background: var(--border);
        }

        .btn-send {
            padding: 10px 24px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
            color: #fff;
            border: none;
            font-family: 'Sora', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity .2s;
        }

        .btn-send:hover {
            opacity: .9;
        }

        /* Alerts */
        .alert {
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            animation: fadeUp .3s both;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--red);
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #065f46;
        }

        /* Pending invitation rows */
        .inv-row {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .inv-row:last-child {
            border-bottom: none;
        }

        .inv-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--amber), #f59e0b);
        }

        /* Animations */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse-dot {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .4;
            }
        }

        /* Tab content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 640px) {
            .stats-strip {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 22px;
            }
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

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn"><i class="fas fa-bell"></i></div>
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <div class="topbar-avatar">AU</div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Evaluators</h2>
                <p>Manage evaluator accounts and send invitations</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= $success ?></div>
        <?php endif; ?>

        <!-- Stats Strip -->
        <div class="stats-strip">
            <div class="stat-card blue" style="animation-delay:.05s">
                <div class="stat-icon blue"><i class="fas fa-users-gear"></i></div>
                <div>
                    <div class="stat-value"><?= $total_evaluators ?></div>
                    <div class="stat-label">Active Evaluators</div>
                </div>
            </div>
            <div class="stat-card amber" style="animation-delay:.1s">
                <div class="stat-icon amber"><i class="fas fa-envelope-open-text"></i></div>
                <div>
                    <div class="stat-value"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Invitations</div>
                </div>
            </div>
            <div class="stat-card green" style="animation-delay:.15s">
                <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="stat-value"><?= $newest_joined ?></div>
                    <div class="stat-label">Latest Joined</div>
                </div>
            </div>
        </div>

        <!-- Tabs + Invite Button Row -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
            <div class="tab-bar">
                <button class="tab-btn active" onclick="switchTab('registered', this)">
                    <i class="fas fa-users"></i> Registered
                    <span style="background:#e0e7ff;color:var(--indigo);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $total_evaluators ?></span>
                </button>
                <button class="tab-btn" onclick="switchTab('pending', this)">
                    <i class="fas fa-clock"></i> Pending Invites
                    <?php if ($pending_count > 0): ?>
                        <span class="tab-badge"><?= $pending_count ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" onclick="switchTab('expired', this)">
                    <i class="fas fa-ban"></i> Expired
                    <?php if ($expired_count > 0): ?>
                        <span style="background:#fee2e2;color:var(--red);border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $expired_count ?></span>
                    <?php endif; ?>
                </button>
            </div>
            <button class="invite-btn" onclick="showInviteModal()">
                <i class="fas fa-paper-plane"></i> Send Invitation
            </button>
        </div>

        <!-- TAB: Registered Evaluators -->
        <div id="tab-registered" class="tab-content active">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Registered Evaluators</div>
                        <div class="main-card-sub"><?= $total_evaluators ?> account<?= $total_evaluators !== 1 ? 's' : '' ?> · All active</div>
                    </div>
                </div>

                <!-- Search -->
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" id="evalSearch" placeholder="Search by name or email..." oninput="filterTable()">
                </div>

                <?php if (empty($evaluators)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
                        <div class="empty-title">No evaluators yet</div>
                        <div class="empty-sub">Send your first invitation to get started.</div>
                        <button class="invite-btn" onclick="showInviteModal()" style="margin:0 auto;">
                            <i class="fas fa-paper-plane"></i> Send First Invitation
                        </button>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table id="evalTable">
                            <thead>
                                <tr>
                                    <th>Evaluator</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluators as $e): ?>
                                    <?php
                                    $parts = explode(' ', trim($e['full_name'] ?? 'EV'));
                                    $initials = strtoupper(count($parts) >= 2
                                        ? substr($parts[0], 0, 1) . substr(end($parts), 0, 1)
                                        : substr($e['full_name'], 0, 2));
                                    ?>
                                    <tr class="ev-row">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:12px;">
                                                <div class="ev-avatar"><?= $initials ?></div>
                                                <div>
                                                    <div class="ev-name"><?= htmlspecialchars($e['full_name'] ?? '—') ?></div>
                                                    <div class="ev-email"><?= htmlspecialchars($e['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($e['phone'] ?? '—') ?></td>
                                        <td><span class="badge-active">Active</span></td>
                                        <td style="font-size:12px;color:var(--text3);">
                                            <div><?= date('M d, Y', strtotime($e['created_at'])) ?></div>
                                            <div style="font-size:10.5px;"><?= date('H:i', strtotime($e['created_at'])) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="no-results" style="display:none;padding:40px;text-align:center;color:var(--text3);font-size:13px;">
                            <i class="fas fa-search" style="font-size:24px;margin-bottom:10px;display:block;"></i>
                            No evaluators match your search.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Pending Invitations -->
        <div id="tab-pending" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Pending Invitations</div>
                        <div class="main-card-sub">Invites awaiting acceptance · expires after 24 hours</div>
                    </div>
                </div>

                <?php if (empty($pending_invitations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-envelope-circle-check"></i></div>
                        <div class="empty-title">All caught up!</div>
                        <div class="empty-sub">No pending invitations at the moment.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_invitations as $inv): ?>
                        <?php
                        $iparts = explode(' ', trim($inv['full_name'] ?? 'IV'));
                        $iinit  = strtoupper(count($iparts) >= 2
                            ? substr($iparts[0], 0, 1) . substr(end($iparts), 0, 1)
                            : substr($inv['full_name'], 0, 2));
                        $expired = strtotime($inv['expires_at']) < time();
                        ?>
                        <div class="inv-row">
                            <div class="inv-avatar"><?= $iinit ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:13.5px;color:var(--text1);"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div style="font-size:12px;color:var(--text3);"><?= htmlspecialchars($inv['email']) ?></div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <?php if ($expired): ?>
                                    <span class="badge-expired"><i class="fas fa-clock"></i> Expired</span>
                                <?php else: ?>
                                    <span class="badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <?php endif; ?>
                                <div style="font-size:10.5px;color:var(--text3);margin-top:4px;">
                                    Sent <?= date('M d, Y', strtotime($inv['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Expired Invitations -->
        <div id="tab-expired" class="tab-content">
            <div class="main-card">
                <div class="main-card-head">
                    <div>
                        <div class="main-card-title">Expired Invitations</div>
                        <div class="main-card-sub">These invites were not accepted within 24 hours</div>
                    </div>
                </div>

                <?php if (empty($expired_invitations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-ban"></i></div>
                        <div class="empty-title">No expired invitations</div>
                        <div class="empty-sub">All invitations are still active or have been accepted.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($expired_invitations as $inv): ?>
                        <?php
                        $iparts = explode(' ', trim($inv['full_name'] ?? 'IV'));
                        $iinit  = strtoupper(count($iparts) >= 2
                            ? substr($iparts[0], 0, 1) . substr(end($iparts), 0, 1)
                            : substr($inv['full_name'], 0, 2));
                        ?>
                        <div class="inv-row" style="opacity:0.75;">
                            <div class="inv-avatar" style="background:#fee2e2;color:var(--red);"><?= $iinit ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:13.5px;color:var(--text1);"><?= htmlspecialchars($inv['full_name']) ?></div>
                                <div style="font-size:12px;color:var(--text3);"><?= htmlspecialchars($inv['email']) ?></div>
                                <div style="font-size:10.5px;color:var(--red);margin-top:4px;">
                                    Expired <?= date('M d, Y H:i', strtotime($inv['expires_at'])) ?>
                                </div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <span class="badge-expired"><i class="fas fa-ban"></i> Expired</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /main -->

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
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
                <div class="flyout" id="appsMenu">
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
            <!--<a href="assignments.html" class="dk" data-tip="Assignments">
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
            <a href="user.php" class="dk active" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
            </a>
            <div class="dk-div"></div>
            <a href="settings.php" class="dk" data-tip="Settings">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>
            <div class="dk" data-tip="Logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>

    <!-- ── INVITE MODAL ── -->
    <div id="inviteModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane" style="margin-right:8px;opacity:.8;"></i>Send Evaluator Invitation</h3>
                <p>An email will be sent with a secure setup link.</p>
                <button class="modal-close" onclick="hideInviteModal()">×</button>
            </div>
            <form method="POST" id="inviteForm">
                <input type="hidden" name="action" value="invite">
                <div class="modal-body">
                    <div class="modal-field">
                        <label class="modal-label">Email Address</label>
                        <input type="email" name="email" required class="modal-input" placeholder="evaluator@dmw.gov.ph">
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Full Name</label>
                        <input type="text" name="full_name" required class="modal-input" placeholder="Juan Dela Cruz">
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Cellphone Number</label>
                        <input type="tel" name="phone" required class="modal-input" placeholder="09171234567"
                            pattern="^(09|\+639)\d{9}$"
                            oninput="this.value = this.value.replace(/[^0-9+]/g, '')">
                        <div class="modal-hint"><i class="fas fa-info-circle"></i> Format: 09171234567 or +639171234567</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="hideInviteModal()">Cancel</button>
                    <button type="submit" class="btn-send">
                        <i class="fas fa-paper-plane"></i> Send Invitation
                    </button>
                </div>
            </form>
        </div>
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

        // Tab switching
        function switchTab(name, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + name).classList.add('active');
            btn.classList.add('active');
        }

        // Live search filter
        function filterTable() {
            const q = document.getElementById('evalSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.ev-row');
            let visible = 0;
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const show = text.includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('no-results').style.display = visible === 0 ? 'block' : 'none';
        }

        // Dock flyout
        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        });

        // Modal
        function showInviteModal() {
            document.getElementById('inviteModal').classList.add('open');
        }

        function hideInviteModal() {
            document.getElementById('inviteModal').classList.remove('open');
        }
        document.getElementById('inviteModal').addEventListener('click', function(e) {
            if (e.target === this) hideInviteModal();
        });

        // SweetAlert feedback
        <?php if ($success || $error): ?>
            Swal.fire({
                title: <?= $success ? "'Success!'" : "'Error'" ?>,
                html: <?= json_encode($success ?: $error) ?>,
                icon: <?= $success ? "'success'" : "'error'" ?>,
                confirmButtonColor: "#1d4ed8",
                timer: 5000,
                timerProgressBar: true
            });
        <?php endif; ?>
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>