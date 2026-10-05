<?php
// create-account.php

session_start();
require_once 'db.php';

require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');

// ────────────────────────────────────────────────
//                RECAPTCHA CONFIG
// ────────────────────────────────────────────────
// Get your keys at: https://www.google.com/recaptcha/admin
// Choose reCAPTCHA v2 -> "I'm not a robot" Checkbox
define('RECAPTCHA_SITE_KEY', '6LfxiS0tAAAAAEL-IIPF8rLK-tCFEzahwEZHYSIO');
define('RECAPTCHA_SECRET_KEY', '6LfxiS0tAAAAAPq10jN28tOqcAKIBGIxJmbb5aqS');

/**
 * Verifies a reCAPTCHA response token with Google's siteverify endpoint.
 * Returns true only if Google confirms the token is valid and unused.
 */
function verify_recaptcha(?string $token): bool
{
    if (empty($token)) {
        return false;
    }

    $payload = http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);

    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

    if ($result === false) {
        return false;
    }

    $response = json_decode($result, true);
    return isset($response['success']) && $response['success'] === true;
}

$error   = '';
$success = '';
$show_registration_form = true;
$show_verification_form = false;

// Rate limiting (3 attempts per 15 minutes)
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts']      = 0;
    $_SESSION['reg_attempts_time'] = time();
}

function generate_code($length = 6)
{
    return substr(str_shuffle('0123456789'), 0, $length);
}

// ────────────────────────────────────────────────
//                FORM SUBMISSIONS
// ────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Registration form submitted
    if (isset($_POST['create_account'])) {

        $email             = trim($_POST['email'] ?? '');
        $password          = $_POST['password'] ?? '';
        $password_confirm  = $_POST['password_confirm'] ?? '';
        $first_name        = trim($_POST['first_name'] ?? '');
        $middle_name       = trim($_POST['middle_name'] ?? '');
        $last_name         = trim($_POST['last_name'] ?? '');
        $suffix            = trim($_POST['suffix'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $barangay          = trim($_POST['barangay'] ?? '');
        $city_municipality = trim($_POST['city_municipality'] ?? '');
        $province          = trim($_POST['province'] ?? '');
        $dob               = $_POST['dob'] ?? null;
        $gender            = $_POST['gender'] ?? null;
        $recaptcha_token   = $_POST['g-recaptcha-response'] ?? '';

        // Basic server-side validation
        if (!verify_recaptcha($recaptcha_token)) {
            $error = "Please complete the reCAPTCHA verification.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($password !== $password_confirm) {
            $error = "Passwords do not match.";
        } elseif (empty($first_name)) {
            $error = "First name is required.";
        } elseif (empty($last_name)) {
            $error = "Last name is required.";
        } elseif (empty($phone)) {
            $error = "Phone number is required.";
        } elseif (empty($address)) {
            $error = "Full address is required.";
        } elseif (empty($barangay) || empty($city_municipality) || empty($province)) {
            $error = "Please select Province, City/Municipality, and Barangay.";
        } elseif (!isset($_POST['agree_policy'])) {
            $error = "You must agree to the Privacy Policy before creating an account.";
        } else {
            // Check if email already exists
            $stmt = $database->prepare("SELECT email FROM webuser WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "This email is already registered.";
            }
            $stmt->close();

            // Rate limit check
            $now = time();
            $window = 15 * 60;
            if ($now - $_SESSION['reg_attempts_time'] > $window) {
                $_SESSION['reg_attempts'] = 0;
                $_SESSION['reg_attempts_time'] = $now;
            }

            if (empty($error) && $_SESSION['reg_attempts'] >= 3) {
                $error = "Too many registration attempts. Please try again later.";
            }

            if (empty($error)) {
                $_SESSION['reg_attempts']++;

                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $code   = generate_code();

                $_SESSION['pending_signup'] = [
                    'email'             => $email,
                    'password'          => $hashed,
                    'first_name'        => $first_name,
                    'middle_name'       => $middle_name ?: null,
                    'last_name'         => $last_name,
                    'suffix'            => $suffix ?: null,
                    'phone'             => $phone,
                    'address'           => $address,
                    'barangay'          => $barangay,
                    'city_municipality' => $city_municipality,
                    'province'          => $province,
                    'dob'               => $dob ?: null,
                    'gender'            => $gender ?: null,
                ];

                $_SESSION['verification_code'] = $code;
                $_SESSION['code_time']         = $now;

                // Send verification email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'mgmangubat@ccc.edu.ph';
                    $mail->Password   = 'oenv xscw mokz rohw';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('no-reply@dmw.gov.ph', 'DMW AMS');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'DMW AMS - Email Verification Code';
                    $mail->Body    = "
                        <h2 style='color:#1e40af;'>Verify Your Email</h2>
                        <p>Hello,</p>
                        <p>Use this 6-digit code to complete your registration:</p>
                        <h1 style='font-size:2.5rem; letter-spacing:0.4em; color:#1e40af;'><b>$code</b></h1>
                        <p>This code expires in <strong>10 minutes</strong>.</p>
                        <p>If you didn't request this, please ignore this email.</p>
                        <br><p>Department of Migrant Workers – Regional Office IV-A</p>
                    ";
                    $mail->AltBody = "Your verification code is: $code\nExpires in 10 minutes.";

                    $mail->send();

                    $show_registration_form = false;
                    $show_verification_form = true;
                    $success = "A verification code has been sent to <strong>$email</strong>.<br>Please check your inbox (and spam/junk folder).";
                } catch (Exception $e) {
                    $error = "Could not send verification email.<br>Error: " . htmlspecialchars($mail->ErrorInfo);
                    unset($_SESSION['pending_signup'], $_SESSION['verification_code']);
                }
            }
        }
    }

    // 2. Verification code submitted
    elseif (isset($_POST['verify_code'])) {
        $entered_code = trim($_POST['verification_code'] ?? '');

        if (!isset($_SESSION['pending_signup']) || !isset($_SESSION['verification_code'])) {
            $error = "Session expired. Please start registration again.";
            $show_registration_form = true;
        } else {
            $now = time();
            $expire = 10 * 60;

            if ($now - $_SESSION['code_time'] > $expire) {
                $error = "Verification code has expired. Please register again.";
                unset($_SESSION['pending_signup'], $_SESSION['verification_code']);
                $show_registration_form = true;
            } elseif ($entered_code !== $_SESSION['verification_code']) {
                $error = "Incorrect verification code.";
                $show_verification_form = true;
            } else {
                $data = $_SESSION['pending_signup'];

                $database->begin_transaction();

                try {
                    $stmt = $database->prepare("INSERT INTO webuser (email, usertype, password) VALUES (?, 'client', ?)");
                    $stmt->bind_param("ss", $data['email'], $data['password']);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $database->prepare("
                        INSERT INTO clients 
                        (email, first_name, middle_name, last_name, suffix, phone, address, 
                         barangay, city_municipality, province, dob, gender)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "ssssssssssss",
                        $data['email'],
                        $data['first_name'],
                        $data['middle_name'],
                        $data['last_name'],
                        $data['suffix'],
                        $data['phone'],
                        $data['address'],
                        $data['barangay'],
                        $data['city_municipality'],
                        $data['province'],
                        $data['dob'],
                        $data['gender']
                    );
                    $stmt->execute();
                    $stmt->close();

                    $database->commit();

                    unset(
                        $_SESSION['pending_signup'],
                        $_SESSION['verification_code'],
                        $_SESSION['reg_attempts'],
                        $_SESSION['reg_attempts_time']
                    );

                    $_SESSION['user_email'] = $data['email'];
                    $_SESSION['user_type']  = 'client';
                    $_SESSION['full_name']  = trim($data['first_name'] . ' ' . $data['last_name']);

                    header("Location: client/index.php?registered=success");
                    exit();
                } catch (Exception $e) {
                    $database->rollback();
                    $error = "Failed to create account: " . $e->getMessage();
                    $show_registration_form = true;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMW RO IV-A – Create Account</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <!-- Google reCAPTCHA -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

    <style>
        :root {
            --navy: #0b1f4b;
            --blue: #1d4ed8;
            --blue-lt: #3b82f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        .serif {
            font-family: 'DM Serif Display', serif;
        }

        /* ─── BACKGROUND ─── */
        .bg-image {
            background-image: url('pictures/dmw.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .overlay {
            background: linear-gradient(135deg, rgba(11, 31, 75, 0.90), rgba(22, 45, 106, 0.85));
            position: fixed;
            inset: 0;
            z-index: 10;
        }

        /* ─── INPUTS & BUTTONS ─── */
        .input-field {
            transition: all 0.25s ease;
        }

        .input-field:focus {
            border-color: var(--blue-lt);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--navy), var(--blue));
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px -8px rgb(11 31 75 / 0.5);
        }

        /* ══════════════════════════════════════════════
           MOBILE / PORTRAIT  (default, < lg)
           Single-column card — original look
        ══════════════════════════════════════════════ */
        .page-wrapper {
            position: relative;
            z-index: 20;
            width: 100%;
            max-width: 32rem;
            /* 512 px */
            padding: 1rem;
        }

        .layout-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 25px 70px -15px rgb(11 31 75 / 0.45);
            border-radius: 1.5rem;
            overflow: hidden;
        }

        /* left brand panel — hidden on mobile */
        .brand-panel {
            display: none;
        }

        /* right form panel */
        .form-panel {
            padding: 2rem 2rem 2rem;
            max-height: 92vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(11, 31, 75, 0.2) transparent;
        }

        .form-panel::-webkit-scrollbar {
            width: 5px;
        }

        .form-panel::-webkit-scrollbar-track {
            background: transparent;
        }

        .form-panel::-webkit-scrollbar-thumb {
            background: rgba(11, 31, 75, 0.2);
            border-radius: 99px;
        }

        /* mobile top bar (logo strip) */
        .mobile-top-bar {
            display: flex;
            background: var(--navy);
            padding: 1.1rem 1.5rem;
            align-items: center;
            gap: 0.9rem;
        }

        /* ══════════════════════════════════════════════
           DESKTOP / LANDSCAPE  (≥ 1024 px)
           Two-column: brand panel left, form right
        ══════════════════════════════════════════════ */
        @media (min-width: 1024px) {
            .bg-image {
                padding: 1.5rem;
            }

            .page-wrapper {
                max-width: 72rem;
                /* 1152 px */
                padding: 0;
            }

            .layout-card {
                display: flex;
                flex-direction: row;
                border-radius: 2rem;
                min-height: 90vh;
                max-height: 95vh;
            }

            /* ── Left brand panel ── */
            .brand-panel {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 38%;
                flex-shrink: 0;
                background: linear-gradient(160deg, var(--navy) 0%, #1a3a7c 60%, #1d4ed8 100%);
                padding: 3rem 2.5rem;
                position: relative;
                overflow: hidden;
            }

            /* decorative circles */
            .brand-panel::before,
            .brand-panel::after {
                content: '';
                position: absolute;
                border-radius: 50%;
                opacity: 0.08;
                background: white;
            }

            .brand-panel::before {
                width: 340px;
                height: 340px;
                bottom: -80px;
                right: -100px;
            }

            .brand-panel::after {
                width: 200px;
                height: 200px;
                top: -50px;
                left: -60px;
            }

            .brand-panel .brand-dots {
                position: absolute;
                inset: 0;
                background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
                background-size: 28px 28px;
            }

            /* ── Right form panel ── */
            .mobile-top-bar {
                display: none;
                /* hidden on desktop */
            }

            .form-panel {
                flex: 1;
                padding: 2.5rem 3rem;
                max-height: 95vh;
                border-radius: 0;
            }
        }

        /* ══════════════════════════════════════════════
           EXTRA-LARGE screens (≥ 1280 px) — more padding
        ══════════════════════════════════════════════ */
        @media (min-width: 1280px) {
            .brand-panel {
                padding: 3.5rem 3rem;
            }

            .form-panel {
                padding: 3rem 3.5rem;
            }
        }
    </style>
</head>

<body class="bg-image">

    <div class="overlay"></div>

    <div class="page-wrapper">
        <div class="layout-card">

            <!-- ══════════════ LEFT BRAND PANEL (desktop only) ══════════════ -->
            <div class="brand-panel">
                <div class="brand-dots"></div>

                <!-- Logo + name -->
                <div class="relative z-10">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-16 w-auto mb-6">
                    <h1 class="serif text-white text-3xl leading-tight mb-2">
                        Department of<br>Migrant Workers
                    </h1>
                    <p class="text-blue-200 text-sm tracking-widest uppercase mb-8">
                        Regional Office IV-A<br>CALABARZON
                    </p>

                    <div class="border-t border-white/20 pt-6 space-y-5">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-shield-halved text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Secure Registration</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Your data is encrypted and protected at all times.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-envelope-circle-check text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Email Verification</p>
                                <p class="text-blue-200 text-xs leading-relaxed">We verify your email to keep your account safe.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-file-lines text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Easy Applications</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Track and manage your DMW applications online.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer note -->
                <div class="relative z-10 mt-8">
                    <p class="text-blue-300 text-xs">© <?= date("Y") ?> Department of Migrant Workers</p>
                </div>
            </div>

            <!--  RIGHT FORM PANEL  -->
            <div class="form-panel">

                <!-- Mobile top bar (hidden on desktop) -->
                <div class="mobile-top-bar -mx-8 -mt-8 mb-6 px-6 py-4">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-9 w-auto">
                    <div>
                        <h1 class="serif text-white text-base font-semibold leading-tight">Department of Migrant Workers</h1>
                        <p class="text-blue-200 text-xs tracking-widest">Regional Office IV-A CALABARZON</p>
                    </div>
                </div>

                <h2 class="serif text-2xl font-semibold text-[var(--navy)] mb-1">Create Your Account</h2>
                <p class="text-slate-500 text-sm mb-6">Register for the DMW Client Portal</p>

                <?php if ($error): ?>
                    <div class="mb-5 rounded-2xl bg-red-50 border border-red-200 px-5 py-3 text-red-700 text-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-5 rounded-2xl bg-green-50 border border-green-200 px-5 py-3 text-green-700 text-sm">
                        <?= $success ?>
                    </div>
                <?php endif; ?>

                <?php if ($show_registration_form): ?>

                    <form method="POST" class="space-y-7">

                        <!--  PERSONAL INFORMATION  -->
                        <div>
                            <h3 class="text-xs font-semibold tracking-widest text-slate-400 mb-4">PERSONAL INFORMATION</h3>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">FIRST NAME <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" required
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Juan">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">MIDDLE NAME</label>
                                    <input type="text" name="middle_name"
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Santos (optional)">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">LAST NAME <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" required
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Dela Cruz">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">SUFFIX</label>
                                    <input list="suffixList" name="suffix"
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Jr., Sr., III">
                                    <datalist id="suffixList">
                                        <option value="Not Applicable">
                                        <option value="Jr">
                                        <option value="Sr">
                                        <option value="Jr.">
                                        <option value="Sr.">
                                        <option value="I">
                                        <option value="II">
                                        <option value="III">
                                        <option value="IV">
                                        <option value="V">
                                    </datalist>
                                </div>
                            </div>
                        </div>

                        <!--  EMAIL  -->
                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">ACTIVE EMAIL ADDRESS <span class="text-red-500">*</span></label>
                            <input type="email" name="email" id="email" required
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                placeholder="juandelacruz@example.com">
                        </div>

                        <!--  ACCOUNT SECURITY  -->
                        <div class="pt-4 border-t border-slate-200">
                            <h3 class="text-xs font-semibold tracking-widest text-slate-400 mb-4">ACCOUNT SECURITY</h3>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">PASSWORD <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" name="password" id="password" required minlength="8"
                                            class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-5 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                            placeholder="••••••••">
                                        <button type="button" id="togglePassword"
                                            class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                            <i class="fas fa-eye text-sm" id="eyeIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">CONFIRM PASSWORD <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" name="password_confirm" id="password_confirm" required
                                            class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-5 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                            placeholder="••••••••">
                                        <button type="button" id="toggleConfirm"
                                            class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                            <i class="fas fa-eye text-sm" id="eyeConfirm"></i>
                                        </button>
                                    </div>
                                    <div id="password-match" class="mt-2 text-sm font-medium min-h-[1.25rem]"></div>
                                </div>
                            </div>

                            <!-- Password Requirements -->
                            <div id="password-requirements" class="mt-4 text-xs space-y-1.5 hidden">
                                <div id="req-length" class="req-item flex items-center gap-2 text-slate-500">• At least 8 characters</div>
                                <div id="req-upper" class="req-item flex items-center gap-2 text-slate-500">• At least one uppercase letter</div>
                                <div id="req-lower" class="req-item flex items-center gap-2 text-slate-500">• At least one lowercase letter</div>
                                <div id="req-number" class="req-item flex items-center gap-2 text-slate-500">• At least one number</div>
                                <div id="req-special" class="req-item flex items-center gap-2 text-slate-500">• At least one special character</div>
                            </div>
                        </div>

                        <!--  CONTACT & ADDRESS  -->
                        <div class="pt-4 border-t border-slate-200">
                            <h3 class="text-xs font-semibold tracking-widest text-slate-400 mb-4">CONTACT & ADDRESS</h3>

                            <div class="mb-4">
                                <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">PHONE NUMBER <span class="text-red-500">*</span></label>
                                <input type="tel" name="phone" required
                                    class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                    placeholder="+63 912 345 6789">
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">PROVINCE <span class="text-red-500">*</span></label>
                                    <input list="provinceList" id="province" name="province" required
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Laguna">
                                    <datalist id="provinceList"></datalist>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">MUNICIPALITY <span class="text-red-500">*</span></label>
                                    <input list="cityList" id="city_municipality" name="city_municipality" required
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Santa Rosa City">
                                    <datalist id="cityList"></datalist>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">BARANGAY <span class="text-red-500">*</span></label>
                                    <input list="barangayList" id="barangay" name="barangay" required
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                        placeholder="Tagapo">
                                    <datalist id="barangayList"></datalist>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">COMPLETE ADDRESS <span class="text-red-500">*</span></label>
                                <textarea name="address" rows="3" required
                                    class="input-field w-full bg-white border border-slate-300 rounded-3xl py-3.5 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none resize-y text-sm"
                                    placeholder="House No., Street Name, Subdivision, etc."></textarea>
                                <p class="mt-1.5 text-xs text-slate-400">Include house number, street, and any landmark if applicable.</p>
                            </div>
                        </div>

                        <!--  ADDITIONAL INFO  -->
                        <div class="pt-4 border-t border-slate-200">
                            <h3 class="text-xs font-semibold tracking-widest text-slate-400 mb-4">ADDITIONAL INFORMATION</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">DATE OF BIRTH</label>
                                    <input type="date" name="dob"
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] focus:outline-none text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">GENDER</label>
                                    <select name="gender"
                                        class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 px-5 text-[var(--navy)] focus:outline-none text-sm">
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <!--  PRIVACY POLICY AGREEMENT  -->
                        <div class="pt-2">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="agree_policy" id="agree_policy" required
                                    class="mt-1 h-4 w-4 rounded border-slate-300 text-[var(--blue)] focus:ring-[var(--blue-lt)]">
                                <span class="text-sm text-slate-600">
                                    I have read and agree to the
                                    <a href="https://dmw.gov.ph/data-privacy-notice" target="_blank" rel="noopener noreferrer"
                                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] underline">
                                        DMW Data Privacy Notice
                                    </a>
                                    and Terms of Service. <span class="text-red-500">*</span>
                                </span>
                            </label>
                        </div>

                        <!--  RECAPTCHA  -->
                        <div class="pt-2 flex justify-center">
                            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>"></div>
                        </div>

                        <button type="submit" name="create_account"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider mt-2">
                            CREATE ACCOUNT
                        </button>

                    </form>

                <?php endif; ?>

                <?php if ($show_verification_form): ?>
                    <form method="POST" class="space-y-6">
                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">VERIFICATION CODE <span class="text-red-500">*</span></label>
                            <input type="text" name="verification_code" required maxlength="6" pattern="\d{6}"
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-6 px-5 text-center text-3xl tracking-[0.5em] text-[var(--navy)] focus:outline-none"
                                placeholder="000000">
                        </div>

                        <button type="submit" name="verify_code"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider">
                            VERIFY & COMPLETE REGISTRATION
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-6 text-sm text-slate-600">
                    Already have an account?
                    <a href="login.php" class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)]">Sign in</a>
                </div>

                <!-- Mobile copyright -->
                <p class="lg:hidden text-center mt-6 text-xs text-slate-400">
                    © <?= date("Y") ?> Department of Migrant Workers • Regional Office IV-A
                </p>

            </div><!-- /form-panel -->
        </div><!-- /layout-card -->
    </div><!-- /page-wrapper -->


    <!--  Password script  -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const pwdInput = document.getElementById('password');
            const confirmInput = document.getElementById('password_confirm');
            const requirementsBox = document.getElementById('password-requirements');
            const matchMsg = document.getElementById('password-match');

            if (!pwdInput || !confirmInput) return;

            const reqElements = {
                length: document.getElementById('req-length'),
                upper: document.getElementById('req-upper'),
                lower: document.getElementById('req-lower'),
                number: document.getElementById('req-number'),
                special: document.getElementById('req-special')
            };

            function updateRequirements(value) {
                const checks = {
                    length: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(value)
                };
                for (const [key, ok] of Object.entries(checks)) {
                    reqElements[key].className = ok ?
                        'req-item flex items-center gap-2 text-green-600' :
                        'req-item flex items-center gap-2 text-slate-500';
                }
                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    return;
                }
                matchMsg.innerHTML = pwdInput.value === confirmInput.value ?
                    '<span class="text-green-600">✓ Passwords match</span>' :
                    '<span class="text-red-600">✗ Passwords do not match</span>';
            }

            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
            confirmInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));

            [pwdInput, confirmInput].forEach(input => {
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (document.activeElement !== pwdInput && document.activeElement !== confirmInput) {
                            requirementsBox.classList.add('hidden');
                        }
                    }, 100);
                });
            });

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });
            confirmInput.addEventListener('input', checkMatch);
        });
    </script>

    <!-- ── Password toggle ── -->
    <script>
        function makeToggle(btnId, iconId, inputId) {
            const btn = document.getElementById(btnId);
            if (!btn) return;
            btn.addEventListener('click', function() {
                const inp = document.getElementById(inputId);
                const icon = document.getElementById(iconId);
                if (inp.type === 'password') {
                    inp.type = 'text';
                    icon.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    inp.type = 'password';
                    icon.classList.replace('fa-eye-slash', 'fa-eye');
                }
            });
        }

        makeToggle('togglePassword', 'eyeIcon', 'password');
        makeToggle('toggleConfirm', 'eyeConfirm', 'password_confirm');
    </script>

    <!--  Philippine address dropdowns  -->
    <script>
        const provinceInput = document.getElementById("province");
        const cityInput = document.getElementById("city_municipality");
        const barangayInput = document.getElementById("barangay");
        const provinceList = document.getElementById("provinceList");
        const cityList = document.getElementById("cityList");
        const barangayList = document.getElementById("barangayList");

        let provinceData = [];
        let cityData = [];

        fetch('https://psgc.cloud/api/provinces/')
            .then(res => res.json())
            .then(data => {
                provinceData = data;
                data.sort((a, b) => a.name.localeCompare(b.name));
                data.forEach(p => {
                    let opt = document.createElement("option");
                    opt.value = p.name;
                    provinceList.appendChild(opt);
                });
            });

        provinceInput.addEventListener("change", function() {
            const selected = provinceData.find(p => p.name === this.value);
            if (!selected) return;
            fetch(`https://psgc.cloud/api/provinces/${selected.code}/cities-municipalities`)
                .then(res => res.json())
                .then(data => {
                    data.sort((a, b) => a.name.localeCompare(b.name));
                    cityData = data;
                    cityList.innerHTML = '';
                    barangayList.innerHTML = '';
                    cityInput.value = '';
                    barangayInput.value = '';
                    data.forEach(c => {
                        let opt = document.createElement("option");
                        opt.value = c.name;
                        cityList.appendChild(opt);
                    });
                });
        });

        cityInput.addEventListener("change", function() {
            const selected = cityData.find(c => c.name === this.value);
            if (!selected) return;
            fetch(`https://psgc.cloud/api/cities-municipalities/${selected.code}/barangays`)
                .then(res => res.json())
                .then(data => {
                    data.sort((a, b) => a.name.localeCompare(b.name));
                    barangayList.innerHTML = '';
                    barangayInput.value = '';
                    data.forEach(b => {
                        let opt = document.createElement("option");
                        opt.value = b.name;
                        barangayList.appendChild(opt);
                    });
                });
        });
    </script>

</body>

</html>