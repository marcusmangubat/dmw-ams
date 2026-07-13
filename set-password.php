<?php

// Activated when user clicks invitation link → sets password and creates profile

session_start();
include 'db.php';  
date_default_timezone_set('Asia/Manila');

$token = $_GET['token'] ?? '';
$error   = '';
$success = '';

if (empty($token)) {
    die("Invalid or missing link.");
}

// Validate invitation token
$stmt = $database->prepare("
    SELECT * FROM invitations 
    WHERE token = ? 
      AND expires_at > NOW() 
      AND used = 0
");
$stmt->bind_param("s", $token);
$stmt->execute();
$invitation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invitation) {
    die("This invitation link has expired, been used, or is invalid.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password        = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic validation
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $database->begin_transaction();

        try {
            // 1. Create main webuser record
            $stmt = $database->prepare("
                INSERT INTO webuser (email, usertype, password) 
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param(
                "sss",
                $invitation['email'],
                $invitation['usertype'],
                $hashed_password
            );
            $stmt->execute();
            $stmt->close();

            // 2. Create role-specific profile
            if ($invitation['usertype'] === 'client') {
                $stmt = $database->prepare("
                    INSERT INTO clients (email, full_name, phone) 
                    VALUES (?, ?, ?)
                ");
                $phone = ''; // phone not collected yet – can be updated later by user
                $stmt->bind_param(
                    "sss",
                    $invitation['email'],
                    $invitation['full_name'],
                    $phone
                );
                $stmt->execute();
                $stmt->close();
            } elseif ($invitation['usertype'] === 'evaluator') {
                $stmt = $database->prepare("
                    INSERT INTO evaluators (email, full_name, phone) 
                    VALUES (?, ?, ?)
                ");
                $phone = '';
                $stmt->bind_param(
                    "sss",
                    $invitation['email'],
                    $invitation['full_name'],
                    $invitation['phone']
                );
                $stmt->execute();
                $stmt->close();
            }
            // Note: if you later support admin invitations, add elseif here

            // 3. Mark invitation as used
            $stmt = $database->prepare("
                UPDATE invitations 
                SET used = 1, used_at = NOW() 
                WHERE token = ?
            ");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $stmt->close();

            $database->commit();

            $success = "Your password has been set successfully!<br>
                        You can now <a href='login.php' class='text-blue-600 underline hover:text-blue-800'>log in</a>.";
        } catch (Exception $e) {
            $database->rollback();
            $error = "An error occurred while creating your account. Please try again or contact support.";
            // Optional: log($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password – DMW RO IV-A</title>

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

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
        }

        .serif {
            font-family: 'DM Serif Display', serif;
        }

        .bg-image {
            background-image: url('pictures/dmw.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
        }

        .overlay {
            background: linear-gradient(135deg, rgba(11, 31, 75, 0.88), rgba(22, 45, 106, 0.82));
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10;
        }

        .card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 25px 70px -15px rgb(11 31 75 / 0.45);
            position: relative;
            z-index: 20;
        }

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
            transform: translateY(-3px);
            box-shadow: 0 20px 35px -10px rgb(11 31 75 / 0.5);
        }
    </style>
</head>

<body class="bg-image flex items-center justify-center p-4 sm:p-6 lg:p-8 min-h-screen">

    <!-- Fixed Dark Overlay -->
    <div class="overlay"></div>

    <div class="relative z-20 w-full max-w-lg">

        <!-- Main Card -->
        <div class="card rounded-3xl overflow-hidden">

            <!-- Top Navy Bar with Logo (Left Side) -->
            <div class="bg-[var(--navy)] px-8 py-5 flex items-center gap-4">
                <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png"
                    alt="DMW Logo"
                    class="h-10 w-auto">
                <div>
                    <h1 class="serif text-white text-xl font-semibold tracking-tight">Department of Migrant Workers Regional Office IV-A CALABARZON</h1>
                    <p class="text-blue-200 text-xs tracking-widest font-medium"></p>
                </div>
            </div>

            <!-- Form Content -->
            <div class="p-8 sm:p-10">

                <h2 class="serif text-2xl font-semibold text-[var(--navy)] mb-1">Set Your Password</h2>
                <p class="text-slate-600 mb-6">
                    Welcome, <strong><?= htmlspecialchars($invitation['full_name'] ?: $invitation['email']) ?></strong>!
                </p>

                <?php if ($error): ?>
                    <div class="mb-6 rounded-2xl bg-red-50 border border-red-200 px-5 py-3 text-red-700 text-sm">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-6 rounded-2xl bg-green-50 border border-green-200 px-5 py-5 text-green-700 text-center text-lg leading-relaxed">
                        <?= $success ?>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                    <form method="POST" class="space-y-6">

                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">NEW PASSWORD <span class="text-red-500">*</span></label>
                            <input type="password" name="password" id="password" required minlength="8"
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-4 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none"
                                placeholder="••••••••">

                            <!-- Password Strength Checklist -->
                            <div id="password-requirements" class="mt-4 text-xs space-y-2">
                                <div id="req-length" class="flex items-center gap-2 text-slate-500">
                                    <span class="w-4">•</span> At least 8 characters
                                </div>
                                <div id="req-upper" class="flex items-center gap-2 text-slate-500">
                                    <span class="w-4">•</span> At least one uppercase letter
                                </div>
                                <div id="req-lower" class="flex items-center gap-2 text-slate-500">
                                    <span class="w-4">•</span> At least one lowercase letter
                                </div>
                                <div id="req-number" class="flex items-center gap-2 text-slate-500">
                                    <span class="w-4">•</span> At least one number
                                </div>
                                <div id="req-special" class="flex items-center gap-2 text-slate-500">
                                    <span class="w-4">•</span> At least one special character
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold tracking-widest text-slate-500 mb-2">CONFIRM PASSWORD <span class="text-red-500">*</span></label>
                            <input type="password" name="confirm_password" id="confirm_password" required
                                class="input-field w-full bg-white border border-slate-300 rounded-2xl py-4 px-5 text-[var(--navy)] placeholder:text-slate-400 focus:outline-none"
                                placeholder="••••••••">
                            <div id="password-match" class="mt-2 text-sm font-medium min-h-[1.25rem]"></div>
                        </div>

                        <button type="submit"
                            class="btn-primary w-full py-4 rounded-2xl text-white font-semibold text-base tracking-wider">
                            SET PASSWORD & ACTIVATE ACCOUNT
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-8 text-sm text-slate-600">
                    <a href="login.php" class="font-semibold text-[var(--blue-lt)] hover:text-[var(--blue)]">Back to Login</a>
                </div>

            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 text-white/70 text-xs tracking-wider">
            © <?= date("Y") ?> Department of Migrant Workers • Regional Office IV-A
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const pwdInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
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
                const hasLength = value.length >= 8;
                const hasUpper = /[A-Z]/.test(value);
                const hasLower = /[a-z]/.test(value);
                const hasNumber = /[0-9]/.test(value);
                const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(value);

                reqElements.length.className = hasLength ? 'flex items-center gap-2 text-green-600' : 'flex items-center gap-2 text-slate-500';
                reqElements.upper.className = hasUpper ? 'flex items-center gap-2 text-green-600' : 'flex items-center gap-2 text-slate-500';
                reqElements.lower.className = hasLower ? 'flex items-center gap-2 text-green-600' : 'flex items-center gap-2 text-slate-500';
                reqElements.number.className = hasNumber ? 'flex items-center gap-2 text-green-600' : 'flex items-center gap-2 text-slate-500';
                reqElements.special.className = hasSpecial ? 'flex items-center gap-2 text-green-600' : 'flex items-center gap-2 text-slate-500';

                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    return;
                }
                if (pwdInput.value === confirmInput.value) {
                    matchMsg.innerHTML = '<span class="text-green-600">✓ Passwords match</span>';
                } else {
                    matchMsg.innerHTML = '<span class="text-red-600">✗ Passwords do not match</span>';
                }
            }

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });

            confirmInput.addEventListener('input', checkMatch);

            // Show requirements when focused
            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
        });
    </script>

</body>

</html>