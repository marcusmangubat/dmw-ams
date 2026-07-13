<?php
// login.php
// Department of Migrant Workers – Application Management System
// Regional Office IV-A CALABARZON

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

require_once 'db.php';

date_default_timezone_set('Asia/Manila');

$error = '';
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $email_value = $email;

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $database->prepare("SELECT email, usertype, password FROM webuser WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_type']  = $user['usertype'];

                $stmt_name = $database->prepare("
                    SELECT CONCAT(first_name, ' ', last_name) AS full_name 
                    FROM clients WHERE email = ? LIMIT 1
                ");
                $stmt_name->bind_param("s", $email);
                $stmt_name->execute();
                $name_result = $stmt_name->get_result();
                if ($name_result->num_rows === 1) {
                    $_SESSION['full_name'] = $name_result->fetch_assoc()['full_name'];
                }
                $stmt_name->close();

                if (isset($_POST['remember']) && $_POST['remember'] === 'on') {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
                    $stmt_token = $database->prepare("UPDATE webuser SET remember_token = ?, remember_expires = ? WHERE email = ?");
                    $stmt_token->bind_param("sss", $token, $expires, $email);
                    $stmt_token->execute();
                    $stmt_token->close();
                    setcookie('remember_token', $token, [
                        'expires'  => time() + (30 * 24 * 60 * 60),
                        'path'     => '/',
                        'secure'   => false,
                        'httponly' => true,
                        'samesite' => 'Strict'
                    ]);
                }

                $redirect_map = [
                    'client'    => 'client/index.php',
                    'admin'     => 'admin/dashboard.php',
                    'evaluator' => 'evaluator/index.php',
                ];

                if (isset($redirect_map[$user['usertype']])) {
                    header("Location: " . $redirect_map[$user['usertype']]);
                    exit;
                } else {
                    $error = "Account type not supported.";
                }
            } else {
                $error = "Invalid email or password."; // Password is incorrect, but we show the same message as an invalid email to avoid giving hints about which one is wrong
            }
        } else {
            $error = "Invalid email or password."; // Avoid revealing whether email or password was incorrect, the email is invalid
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMW RO IV-A – Sign In</title>

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

        /* ══════════════════════════════════════════
           MOBILE / PORTRAIT (default, < lg)
           Single-column card
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

        /* left brand panel — hidden on mobile */
        .brand-panel {
            display: none;
        }

        /* mobile top bar */
        .mobile-top-bar {
            display: flex;
            background: var(--navy);
            padding: 1.1rem 1.5rem;
            align-items: center;
            gap: 0.9rem;
        }

        /* form panel */
        .form-panel {
            padding: 2rem;
        }

        /* ══════════════════════════════════════════
           DESKTOP / LANDSCAPE (≥ 1024 px)
           Two-column: brand left, form right
        ══════════════════════════════════════════ */
        @media (min-width: 1024px) {
            .bg-image {
                padding: 1.5rem;
            }

            .page-wrapper {
                max-width: 64rem;
                /* narrower than register since login is simpler */
                padding: 0;
            }

            .layout-card {
                display: flex;
                flex-direction: row;
                border-radius: 2rem;
                min-height: 70vh;
            }

            /* ── Left brand panel ── */
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

            /* ── Right form panel ── */
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
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-user-shield text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Secure Access</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Your session is protected and encrypted.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-clock-rotate-left text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Track Applications</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Monitor the status of your DMW applications in real time.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 w-8 h-8 rounded-full bg-white/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-bell text-blue-300 text-xs"></i>
                            </div>
                            <div>
                                <p class="text-white font-semibold text-sm">Get Notified</p>
                                <p class="text-blue-200 text-xs leading-relaxed">Receive updates and alerts about your account activity.</p>
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

                <!-- Mobile top bar (hidden on desktop) -->
                <div class="mobile-top-bar -mx-8 -mt-8 mb-6 px-6 py-4">
                    <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                        alt="DMW Logo" class="h-9 w-auto">
                    <div>
                        <h1 class="serif text-white text-base font-semibold leading-tight">Department of Migrant Workers</h1>
                        <p class="text-blue-200 text-xs tracking-widest">Regional Office IV-A CALABARZON</p>
                    </div>
                </div>

                <h2 class="serif text-2xl font-semibold text-[var(--navy)] mb-1">Welcome Back</h2>
                <p class="text-slate-500 text-sm mb-8">Sign in to your Portal</p>

                <?php if ($error): ?>
                    <div class="mb-6 rounded-2xl bg-red-50 border border-red-200 px-5 py-3 text-red-700 text-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-5">

                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">
                            EMAIL ADDRESS
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input type="email" name="email" id="email" required
                                value="<?= htmlspecialchars($email_value) ?>"
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                placeholder="yourname@example.com">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">
                            PASSWORD
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input type="password" name="password" id="password" required
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-3.5 pl-11 pr-12 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none text-sm"
                                placeholder="••••••••">
                            <button type="button" id="togglePassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                                <i class="fas fa-eye text-sm" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember me / Forgot password -->
                    <div class="flex items-center justify-between text-sm pt-1">
                        <label class="flex items-center gap-2 cursor-pointer text-slate-600 select-none">
                            <input type="checkbox" name="remember"
                                class="w-4 h-4 rounded border-slate-300 accent-[var(--blue)]">
                            <span class="font-medium">Remember me</span>
                        </label>
                        <a href="reset-password.php"
                            class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors text-xs">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit"
                        class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-sm tracking-wider mt-2">
                        SIGN IN
                    </button>

                </form>

                <div class="text-center mt-7 text-sm text-slate-600">
                    New applicant?
                    <a href="create-account.php"
                        class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)] transition-colors">
                        Create account
                    </a>
                </div>

                <!-- Mobile copyright -->
                <p class="lg:hidden text-center mt-6 text-xs text-slate-400">
                    © <?= date("Y") ?> Department of Migrant Workers • Regional Office IV-A
                </p>

            </div><!-- /form-panel -->
        </div><!-- /layout-card -->
    </div><!-- /page-wrapper -->

    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    </script>

</body>

</html>