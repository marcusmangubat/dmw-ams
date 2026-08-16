<?php
// evaluator/index.php – DMW Evaluator Dashboard
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');

// Require evaluator login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

// ────────────────────────────────────────────────
// Get evaluator info
// ────────────────────────────────────────────────
$evaluator_name = 'Evaluator';
$evaluator_id   = null;

$stmt_ev = $database->prepare("
    SELECT eid, full_name 
    FROM evaluators 
    WHERE email = ? 
    LIMIT 1
");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();

if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// ────────────────────────────────────────────────
// Global stats: applications by status
// ────────────────────────────────────────────────
$stats = [
    'assign'           => 0,
    'under_evaluation' => 0,
    'for_compliance'   => 0,
    'approved'         => 0,
    'resubmitted'      => 0,
    'total'            => 0,
];

// Only count applications currently assigned to this evaluator
// (current_evaluator_id is the FK on `applications` -> `evaluators`.`eid`,
// and reflects who is presently handling the application).
if ($evaluator_id) {
    $stmt_stats = $database->prepare("
        SELECT status, COUNT(*) as cnt 
        FROM applications 
        WHERE current_evaluator_id = ?
        GROUP BY status
    ");
    $stmt_stats->bind_param("i", $evaluator_id);
    $stmt_stats->execute();
    $res = $stmt_stats->get_result();

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = $row['status'];
            $cnt = (int) $row['cnt'];
            if (in_array($key, ['assigned', 'assign', 'pending'], true)) {
                $stats['assign'] += $cnt;
            } elseif (array_key_exists($key, $stats)) {
                $stats[$key] += $cnt;
            }
            $stats['total'] += $cnt;
        }
    }
    $stmt_stats->close();
}

// Applications sitting unclaimed in the pool right now (anyone can open one)
$available_count = 0;
$res_avail = $database->query("
    SELECT COUNT(*) AS cnt FROM applications
    WHERE handling_party IS NULL AND status IN ('pending','assign','assigned','resubmitted')
");
if ($res_avail) {
    $available_count = (int) $res_avail->fetch_assoc()['cnt'];
}

// ────────────────────────────────────────────────
// My active assignments
// ────────────────────────────────────────────────
$my_applications = [];

if ($evaluator_id) {
    $stmt = $database->prepare("
        SELECT 
            a.id, 
            a.status, 
            a.updated_at, 
            r.rsf_number, 
            r.position_title, 
            r.worksite_country AS country,
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name
        FROM applications a
        INNER JOIN rsf_job_orders    r  ON a.rsf_id           = r.id
        LEFT  JOIN clients           c  ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
        ORDER BY a.updated_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $evaluator_id);
    $stmt->execute();
    $my_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ────────────────────────────────────────────────
// Count active assignments
// ────────────────────────────────────────────────
$my_assigned_count = 0;

if ($evaluator_id) {
    $stmt_cnt = $database->prepare("
        SELECT COUNT(*) as cnt 
        FROM applications 
        WHERE current_evaluator_id = ? 
          AND handling_party = 'evaluator'
    ");
    $stmt_cnt->bind_param("i", $evaluator_id);
    $stmt_cnt->execute();
    $my_assigned_count = (int)$stmt_cnt->get_result()->fetch_assoc()['cnt'];
    $stmt_cnt->close();
}

// Greeting
$hour       = (int)date('G');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($evaluator_name))[0] ?: $evaluator_name;

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// ────────────────────────────────────────────────
// Status pill → Tailwind utility classes (same hex values as before,
// just expressed as Tailwind's matching palette tokens)
// ────────────────────────────────────────────────
function pill_classes(string $status): string
{
    return match ($status) {
        'approved'         => 'bg-emerald-100 text-emerald-800',
        'under_evaluation' => 'bg-indigo-50 text-indigo-800',
        'for_compliance'   => 'bg-orange-50 text-amber-800',
        'disapproved'      => 'bg-red-100 text-red-800',
        'assign', 'pending', 'assigned' => 'bg-blue-50 text-blue-800',
        'resubmitted'      => 'bg-indigo-50 text-indigo-800',
        default            => 'bg-slate-100 text-slate-600',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluator Dashboard – DMW Regional Office IV-A</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <!-- Fonts used by dashboard.css -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!--
        dashboard.css is kept ONLY because the topbar, subbar and floating
        dock below still depend on it and were explicitly left untouched.
        Everything else on this page (quick access, KPI cards, application
        list, status breakdown) no longer needs it — it's built with
        Tailwind utility classes instead.
    -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <!-- Tailwind (CDN) powers every section below the subbar -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* The only rule Tailwind's CDN can't express as a utility class,
           kept so the untouched topbar markup renders identically. */
        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Fade-in-up entrance, staggered per section via inline delay */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            opacity: 0;
            animation: fadeInUp .4s ease forwards;
        }
    </style>
</head>

<body class="font-sans">

    <!-- ══════════════════════════════════════════
         TOPBAR — unchanged
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
         SUBBAR — unchanged
    ══════════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= $greeting ?>, <?= htmlspecialchars($first_name) ?></h2>
                <p>(<?= number_format($available_count) ?>) Pending Applications</p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT — rebuilt with Tailwind
    ══════════════════════════════════════════ -->
    <div class="max-w-6xl mx-auto px-4 pt-3 pb-28">


        <!-- KPI Stats -->
        <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-slate-400 mb-2 fade-in" style="animation-delay:.1s">
            Overview
        </p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5 fade-in" style="animation-delay:.15s">

            <!-- Total -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide">Total Applications</div>
                        <div class="text-2xl font-extrabold text-blue-600 mt-1"><?= number_format($stats['total']) ?></div>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center text-sm shrink-0">
                        <i class="fas fa-file-lines"></i>
                    </div>
                </div>
                <div class="text-[10.5px] text-slate-400 font-medium mt-2">All applications you review</div>
            </div>

            <!-- Available to claim -->
            <a href="applications.php?status=available"
                class="group bg-white border border-slate-200 rounded-2xl p-4 shadow-sm no-underline block transition-all duration-200 hover:shadow-md hover:-translate-y-0.5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide">Available</div>
                        <div class="text-2xl font-extrabold text-emerald-600 mt-1"><?= number_format($available_count) ?></div>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm shrink-0 transition-transform duration-200 group-hover:scale-110">
                        <i class="fas fa-inbox"></i>
                    </div>
                </div>
                <div class="text-[10.5px] text-slate-400 font-medium mt-2">Unclaimed — first to open it gets it</div>
            </a>

            <!-- Under evaluation -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide">Under Evaluation</div>
                        <div class="text-2xl font-extrabold text-indigo-600 mt-1"><?= number_format($stats['under_evaluation']) ?></div>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm shrink-0">
                        <i class="fas fa-magnifying-glass"></i>
                    </div>
                </div>
                <div class="text-[10.5px] text-slate-400 font-medium mt-2">Currently being reviewed by you</div>
            </div>

            <!-- Approved -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow duration-200">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10.5px] font-semibold text-slate-400 uppercase tracking-wide">Approved</div>
                        <div class="text-2xl font-extrabold text-emerald-600 mt-1"><?= number_format($stats['approved']) ?></div>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm shrink-0">
                        <i class="fas fa-circle-check"></i>
                    </div>
                </div>
                <div class="text-[10.5px] text-slate-400 font-medium mt-2">Approved applications you evaluated</div>
            </div>

        </div>

        <!-- Quick Access -->
        <p class="text-[9.5px] font-bold tracking-[.08em] uppercase text-slate-400 mb-2 fade-in" style="animation-delay:.05s">
            Quick Access
        </p>
        <div class="grid grid-cols-3 gap-2.5 mb-5 fade-in" style="animation-delay:.1s">

            <a href="applications.php"
                class="group bg-white border border-slate-200 rounded-2xl px-2 py-3.5 flex flex-col items-center gap-2 text-center no-underline text-slate-500 font-bold text-[11px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-[3px] hover:shadow-lg hover:shadow-slate-900/10 active:scale-[.97]">
                <div class="w-9 h-9 rounded-[10px] flex items-center justify-center text-sm bg-blue-50 text-blue-500">
                    <i class="fas fa-list"></i>
                </div>
                My Assignments
            </a>

            <a href="applications.php?status=for_compliance"
                class="group bg-white border border-slate-200 rounded-2xl px-2 py-3.5 flex flex-col items-center gap-2 text-center no-underline text-slate-500 font-bold text-[11px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-[3px] hover:shadow-lg hover:shadow-slate-900/10 active:scale-[.97]">
                <div class="w-9 h-9 rounded-[10px] flex items-center justify-center text-sm bg-amber-50 text-amber-600">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                For Compliance
            </a>

            <a href="reports.php"
                class="group bg-white border border-slate-200 rounded-2xl px-2 py-3.5 flex flex-col items-center gap-2 text-center no-underline text-slate-500 font-bold text-[11px] transition-all duration-200 hover:bg-slate-50 hover:-translate-y-[3px] hover:shadow-lg hover:shadow-slate-900/10 active:scale-[.97]">
                <div class="w-9 h-9 rounded-[10px] flex items-center justify-center text-sm bg-emerald-50 text-emerald-600">
                    <i class="fas fa-chart-bar"></i>
                </div>
                Reports
            </a>

        </div>


        <!-- My Assigned Applications + Status Breakdown -->
        <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-4 fade-in" style="animation-delay:.2s">

            <!-- My Assigned Applications -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                        My Assigned Applications
                    </span>
                    <a href="applications.php"
                        class="text-[11px] font-bold text-blue-500 hover:text-blue-600 no-underline flex items-center gap-1">
                        View all <i class="fas fa-arrow-right text-[9px]"></i>
                    </a>
                </div>

                <div class="flex flex-col">
                    <?php if (empty($my_applications)): ?>
                        <div class="py-10 px-4 text-center">
                            <i class="fas fa-inbox text-3xl text-slate-200 block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-slate-400">No applications assigned yet</p>
                            <span class="text-[11px] text-slate-400/80">Applications assigned to you will appear here once processed.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_applications as $app): ?>
                            <?php
                            $status     = strtolower($app['status'] ?? 'unknown');
                            $statusText = str_replace('_', ' ', ucwords($status));
                            $pillClass  = pill_classes($status);

                            $np  = explode(' ', trim($app['applicant_name']));
                            $avi = strtoupper(count($np) >= 2
                                ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                : substr($app['applicant_name'], 0, 2));
                            ?>
                            <a href="view-application.php?id=<?= (int)$app['id'] ?>"
                                class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                                <div class="w-9 h-9 shrink-0 rounded-[10px] bg-indigo-50 text-indigo-600 text-[10.5px] font-extrabold tracking-wide flex items-center justify-center">
                                    <?= htmlspecialchars($avi) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[12.5px] font-bold text-slate-800 truncate"><?= htmlspecialchars($app['applicant_name']) ?></div>
                                    <div class="text-[10.5px] text-slate-400 font-medium mt-0.5 truncate">
                                        <?= htmlspecialchars($app['rsf_number'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['position_title'] ?? '—') ?>
                                        &nbsp;·&nbsp;<?= htmlspecialchars($app['country'] ?? '—') ?>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-block text-[9.5px] font-bold tracking-wider uppercase px-2.5 py-[3px] rounded-full <?= $pillClass ?>">
                                        <?= htmlspecialchars($statusText) ?>
                                    </span>
                                    <div class="text-[9.5px] text-slate-400 font-medium mt-[3px]">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div><!-- /My Assigned Applications -->

            <!-- Status Breakdown -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        Status Breakdown
                    </span>
                </div>

                <?php
                $breakdown = [
                    ['label' => 'Assign',           'key' => 'assign',           'dot' => '#3b82f6'],
                    ['label' => 'Under Evaluation', 'key' => 'under_evaluation', 'dot' => '#4f46e5'],
                    ['label' => 'For Compliance',    'key' => 'for_compliance',   'dot' => '#d97706'],
                    ['label' => 'Resubmitted',       'key' => 'resubmitted',      'dot' => '#ca8a04'],
                    ['label' => 'Approved',          'key' => 'approved',         'dot' => '#059669'],
                ];
                $total = max($stats['total'], 1);
                foreach ($breakdown as $b):
                    $pct = round(($stats[$b['key']] / $total) * 100);
                ?>
                    <div class="flex items-center gap-2 py-2">
                        <span class="w-2 h-2 rounded-full shrink-0" style="background:<?= $b['dot'] ?>"></span>
                        <span class="text-[11px] font-semibold text-slate-600 w-[92px] shrink-0 truncate"><?= $b['label'] ?></span>
                        <span class="text-[11px] font-bold w-7 text-right shrink-0" style="color:<?= $b['dot'] ?>"><?= number_format($stats[$b['key']]) ?></span>
                        <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500" style="width:<?= $pct ?>%;background:<?= $b['dot'] ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div><!-- /Status Breakdown -->

        </div><!-- /grid -->

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK — unchanged
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk active">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Tasks</span>
                <?php if ($my_assigned_count > 0): ?>
                    <span class="dk-badge"><?= $my_assigned_count > 99 ? '99+' : $my_assigned_count ?></span>
                <?php endif; ?>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="reports.php" class="dk">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>

            <div class="dk-div"></div>

            <a href="settings.php" class="dk">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>

            <div class="dk" onclick="logout('Evaluator')">
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
    </script>
    <script type="module" src="../js/main.js"></script>

    <?php include '../includes/login_toast.php'; ?>

</body>

</html>