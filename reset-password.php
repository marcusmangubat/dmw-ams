<?php
// reset-password.php

session_start();
include 'db.php';

date_default_timezone_set('Asia/Manila');

require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = '';
$success = '';
$step    = 'email'; // email → code → password → done

function generate_code()
{
    return substr(str_shuffle('0123456789'), 0, 6);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['send_code'])) {

        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } else {
            $stmt = $database->prepare("SELECT usertype FROM webuser WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $usertype = $result->fetch_assoc()['usertype'];
                $code = generate_code();

                $_SESSION['reset'] = [
                    'email'    => $email,
                    'usertype' => $usertype,
                    'code'     => $code,
                    'time'     => time(),
                    'attempts' => 0
                ];

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'mgmangubat@ccc.edu.ph';
                    $mail->Password   = 'oenv xscw mokz rohw';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('no-reply@dmw-ams.local', 'DMW AMS');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'DMW AMS – Password Reset Code';
                    $mail->Body    = "
                        <h2 style='color:#1e40af;'>Password Reset Request</h2>
                        <p>Use this code to reset your password:</p>
                        <h1 style='font-size:2.5rem; letter-spacing:0.4em; color:#1e40af;'><b>$code</b></h1>
                        <p>This code expires in <strong>10 minutes</strong>.</p>
                        <p>If you did not request this, please ignore this email.</p>
                        <br><p>Department of Migrant Workers – Regional Office IV-A</p>
                    ";

                    $mail->send();
                    $step    = 'code';
                    $success = "A verification code has been sent to <strong>$email</strong>.<br>Please check your inbox (and spam/junk folder).";
                } catch (Exception $e) {
                    $error = "Failed to send email: " . htmlspecialchars($mail->ErrorInfo);
                }
            } else {
                $error = "No account found with this email.";
            }
            $stmt->close();
        }
    } elseif (isset($_POST['verify_code'])) {

        $entered = trim($_POST['code'] ?? '');

        if (!isset($_SESSION['reset'])) {
            $error = "Session expired. Please try again.";
            $step  = 'email';
        } else {
            $reset = &$_SESSION['reset'];

            if ((time() - $reset['time']) > 600) {
                $error = "Code expired. Please request a new one.";
                $step  = 'email';
                unset($_SESSION['reset']);
            } elseif ($entered === $reset['code']) {
                $step = 'password';
            } else {
                $reset['attempts']++;
                if ($reset['attempts'] >= 5) {
                    $error = "Too many incorrect attempts. Please request a new code.";
                    $step  = 'email';
                    unset($_SESSION['reset']);
                } else {
                    $error = "Incorrect code.";
                    $step  = 'code';
                }
            }
        }
    } elseif (isset($_POST['reset_password'])) {

        if (!isset($_SESSION['reset'])) {
            $error = "Session expired.";
            $step  = 'email';
        } else {
            $reset        = $_SESSION['reset'];
            $new_pass     = $_POST['new_password']     ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';

            if (strlen($new_pass) < 8) {
                $error = "Password must be at least 8 characters.";
                $step  = 'password';
            } elseif ($new_pass !== $confirm_pass) {
                $error = "Passwords do not match.";
                $step  = 'password';
            } else {
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt   = $database->prepare("UPDATE webuser SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed, $reset['email']);

                if ($stmt->execute()) {
                    unset($_SESSION['reset']);
                    $success = "Password reset successful! You can now <a href='login.php' class='font-semibold underline'>sign in</a>.";
                    $step    = 'done';
                } else {
                    $error = "Failed to update password.";
                    $step  = 'password';
                }
                $stmt->close();
            }
        }
    }
}

// Step labels for the progress indicator
$steps = ['email' => 1, 'code' => 2, 'password' => 3, 'done' => 4];
$current_step_num = $steps[$step] ?? 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMW RO IV-A – Reset Password</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

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

        /* ─── STEP PROGRESS ─── */
        .step-dot {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            transition: all 0.3s;
        }

        .step-line {
            flex: 1;
            height: 2px;
            transition: background 0.3s;
        }

        /* ══════════════════════════════════════════
           MOBILE / PORTRAIT (default, < lg)
        ══════════════════════════════════════════ */
        .page-wrapper {
            position: relative;
            z-index: 20;
            width: 100%;
            max-width: 32rem;
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

        .brand-panel {
            display: none;
        }

        .mobile-top-bar {
            display: flex;
            background: var(--navy);
            padding: 1.1rem 1.5rem;
            align-items: center;
            gap: 0.9rem;
        }

        .form-panel {
            padding: 2rem;
        }

        /* ══════════════════════════════════════════
           DESKTOP / LANDSCAPE (≥ 1024 px)
        ══════════════════════════════════════════ */
        @media (min-width: 1024px) {
            .bg-image {
                padding: 1.5rem;
            }

            .page-wrapper {
                max-width: 64rem;
                padding: 0;
            }

            .layout-card {
                display: flex;
                flex-direction: row;
                border-radius: 2rem;
                min-height: 70vh;
            }

            .brand-panel {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 42%;
                flex-shrink: 0;
                background: linear-gradient(160deg, var(--navy) 0%, #1a3a7c 60%, #1d4ed8 100%);
                padding: 3rem 2.5rem;
                position: relative;
                overflow: hidden;
            }

            .brand-panel::before,
            .brand-panel::after {
                content: '';
                position: absolute;
                border-radius: 50%;
                opacity: 0.08;
                background: white;
            }

            .brand-panel::before {
                width: 320px;
                height: 320px;
                bottom: -70px;
                right: -90px;
            }

            .brand-panel::after {
                width: 180px;
                height: 180px;
                top: -45px;
                left: -55px;
            }

            .brand-panel .brand-dots {
                position: absolute;
                inset: 0;
                background-image: radial-gradient(circle, rgba(255, 255, 255, 0.07) 1px, transparent 1px);
                background-size: 28px 28px;
            }

            .mobile-top-bar {
                display: none;
            }

            .form-panel {
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 3rem 3.5rem;
            }
        }

        @media (min-width: 1280px) {
            .brand-panel {
                padding: 3.5rem 3rem;
            }

            .form-panel {
                padding: 3.5rem 4rem;
            }
        }
    </style>
</head>

<body class="bg-image">

    <div class="overlay"></div>

    <div class="page-wrapper">
        <div class="layout-card">

            <!-- ══════════ LEFT BRAND PANEL (desktop only) ══════════ -->
            <div class="brand-panel">
                <div class="brand-dots"></div>

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
                            <div class="mt-0.5 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
                                <?= $current_step_num >= 1 ? 'bg-white/20' : 'bg-white/10' ?>">
                                <i class="fa-solid fa-envelope text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Step 1 — Verify Email</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Enter the email linked to your account.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
                                <?= $current_step_num >= 2 ? 'bg-white/20' : 'bg-white/10' ?>">
                                <i class="fa-solid fa-key text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Step 2 — Enter Code</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Check your inbox for the 6-digit code.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
                                <?= $current_step_num >= 3 ? 'bg-white/20' : 'bg-white/10' ?>">
                                <i class="fa-solid fa-lock text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Step 3 — New Password</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Choose a strong new password for your account.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative z-10 mt-8">
                    <p class="text-blue-300 text-xs">© <?= date("Y") ?> Department of Migrant Workers</p>
                </div>
            </div>

            <!-- ══════════ RIGHT FORM PANEL ══════════ -->
            <div class="form-panel">

                <!-- Mobile top bar -->
                <div class="mobile-top-bar -mx-8 -mt-8 mb-6 px-6 py-4">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-9 w-auto">
                    <div>
                        <h1 class="serif text-white text-base font-semibold leading-tight">Department of Migrant Workers</h1>
                        <p class="text-blue-200 text-xs tracking-widest">Regional Office IV-A CALABARZON</p>
                    </div>
                </div>

                <!-- Step progress indicator (mobile + desktop) -->
                <?php if ($step !== 'done'): ?>
                    <div class="flex items-center mb-8">
                        <?php
                        $step_labels = ['Email', 'Code', 'Password'];
                        for ($i = 1; $i <= 3; $i++):
                            $active   = $current_step_num === $i;
                            $complete = $current_step_num > $i;
                        ?>
                            <div class="step-dot <?= $complete ? 'bg-[var(--blue)] text-white' : ($active ? 'bg-[var(--navy)] text-white' : 'bg-slate-200 text-slate-400') ?>">
                                <?php if ($complete): ?>
                                    <i class="fas fa-check text-[10px]"></i>
                                <?php else: ?>
                                    <?= $i ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($i < 3): ?>
                                <div class="step-line <?= $current_step_num > $i ? 'bg-[var(--blue)]' : 'bg-slate-200' ?>"></div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                <?php
                $titles = [
                    'email'    => ['Reset Password',       'Enter the email address linked to your account.'],
                    'code'     => ['Check Your Email',     'Enter the 6-digit code we sent to your inbox.'],
                    'password' => ['Create New Password',  'Choose a strong password for your account.'],
                    'done'     => ['All Done!',             'Your password has been successfully updated.'],
                ];
                [$title, $subtitle] = $titles[$step] ?? ['Reset Password', ''];
                ?>

                <h2 class="serif text-2xl font-semibold text-[var(--navy)] mb-1"><?= $title ?></h2>
                <p class="text-slate-500 text-sm mb-7"><?= $subtitle ?></p>

                <?php if ($error): ?>
                    <div class="mb-5 rounded-2xl bg-red-50 border border-red-200 px-5 py-3 text-red-700 text-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success && $step !== 'done'): ?>
                    <div class="mb-5 rounded-2xl bg-green-50 border border-green-200 px-5 py-3 text-green-700 text-sm">
                        <?= $success ?>
                    </div>
                <?php endif; ?>

                <!-- ── STEP 1: Email ── -->
                <?php if ($step === 'email'): ?>
                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">EMAIL ADDRESS</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-envelope text-sm"></i>
                                </span>
                                <input type="email" name="email" required
                                    class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                    placeholder="yourname@example.com">
                            </div>
                        </div>
                        <button type="submit" name="send_code"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider">
                            SEND VERIFICATION CODE
                        </button>
                    </form>
                <?php endif; ?>

                <!-- ── STEP 2: Code ── -->
                <?php if ($step === 'code'): ?>
                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">VERIFICATION CODE</label>
                            <input type="text" name="code" required maxlength="6" pattern="\d{6}"
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-5 px-5 text-center text-3xl tracking-[0.5em] text-[var(--navy)] focus:outline-none"
                                placeholder="000000" autofocus>
                            <p class="text-center text-xs text-slate-400 mt-3">
                                <i class="fas fa-clock mr-1"></i> Code expires in 10 minutes
                            </p>
                        </div>
                        <button type="submit" name="verify_code"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider">
                            VERIFY CODE
                        </button>
                    </form>
                <?php endif; ?>

                <!-- ── STEP 3: New Password ── -->
                <?php if ($step === 'password'): ?>
                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">NEW PASSWORD</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-lock text-sm"></i>
                                </span>
                                <input type="password" name="new_password" id="new_password" required minlength="8"
                                    class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                    placeholder="••••••••">
                                <button type="button" id="toggleNew"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                    <i class="fas fa-eye text-sm" id="eyeNew"></i>
                                </button>
                            </div>

                            <div id="password-requirements" class="mt-3 text-xs space-y-1.5">
                                <div id="req-length" class="flex items-center gap-2 text-slate-500"><span class="w-4">•</span> At least 8 characters</div>
                                <div id="req-upper" class="flex items-center gap-2 text-slate-500"><span class="w-4">•</span> At least one uppercase letter</div>
                                <div id="req-lower" class="flex items-center gap-2 text-slate-500"><span class="w-4">•</span> At least one lowercase letter</div>
                                <div id="req-number" class="flex items-center gap-2 text-slate-500"><span class="w-4">•</span> At least one number</div>
                                <div id="req-special" class="flex items-center gap-2 text-slate-500"><span class="w-4">•</span> At least one special character</div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">CONFIRM PASSWORD</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                    <i class="fas fa-lock text-sm"></i>
                                </span>
                                <input type="password" name="confirm_password" id="confirm_password" required
                                    class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                    placeholder="••••••••">
                                <button type="button" id="toggleConfirm"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                    <i class="fas fa-eye text-sm" id="eyeConfirm"></i>
                                </button>
                            </div>
                            <div id="password-match" class="mt-2 text-sm font-medium min-h-[1.25rem]"></div>
                        </div>

                        <button type="submit" name="reset_password"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider">
                            RESET PASSWORD
                        </button>
                    </form>
                <?php endif; ?>

                <!-- ── DONE ── -->
                <?php if ($step === 'done'): ?>
                    <div class="text-center py-4">
                        <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-check text-green-600 text-2xl"></i>
                        </div>
                        <div class="rounded-2xl bg-green-50 border border-green-200 px-5 py-4 text-green-700 text-sm mb-6">
                            <?= $success ?>
                        </div>
                        <a href="login.php"
                            class="btn-primary inline-block px-8 py-4 rounded-2xl text-white font-semibold text-sm tracking-wider">
                            GO TO SIGN IN
                        </a>
                    </div>
                <?php endif; ?>

                <div class="text-center mt-7 text-sm text-slate-600">
                    <a href="login.php" class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors">
                        <i class="fas fa-arrow-left text-xs mr-1"></i> Back to Sign In
                    </a>
                </div>

                <!-- Mobile copyright -->
                <p class="lg:hidden text-center mt-6 text-xs text-slate-400">
                    © <?= date("Y") ?> Department of Migrant Workers • Regional Office IV-A
                </p>

            </div><!-- /form-panel -->
        </div><!-- /layout-card -->
    </div><!-- /page-wrapper -->

    <?php if ($step === 'password'): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const pwdInput = document.getElementById('new_password');
                const confirmInput = document.getElementById('confirm_password');
                const matchMsg = document.getElementById('password-match');

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
                            'flex items-center gap-2 text-green-600' :
                            'flex items-center gap-2 text-slate-500';
                    }
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

                pwdInput.addEventListener('input', () => {
                    updateRequirements(pwdInput.value);
                    checkMatch();
                });
                confirmInput.addEventListener('input', checkMatch);

                // Toggle show/hide
                function makeToggle(btnId, iconId, inputId) {
                    document.getElementById(btnId).addEventListener('click', () => {
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

                makeToggle('toggleNew', 'eyeNew', 'new_password');
                makeToggle('toggleConfirm', 'eyeConfirm', 'confirm_password');
            });
        </script>
    <?php endif; ?>

</body>

</html>