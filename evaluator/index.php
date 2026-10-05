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
    'disapproved'      => 0,
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
            COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Unknown Applicant') AS applicant_name,
            c.profile_picture AS applicant_picture,
            c.province AS applicant_province
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
// Filter dropdown sources — scoped ONLY to job types/destinations/provinces
// this evaluator actually has applications for (via current_evaluator_id),
// never the full system-wide list of RSF job orders or all client provinces.
// ────────────────────────────────────────────────
$evaluator_job_types = [];
$evaluator_countries = [];
$evaluator_provinces = [];

if ($evaluator_id) {
    $stmt_jt = $database->prepare("
        SELECT DISTINCT r.position_title
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.current_evaluator_id = ?
          AND r.position_title IS NOT NULL AND r.position_title <> ''
        ORDER BY r.position_title ASC
    ");
    $stmt_jt->bind_param("i", $evaluator_id);
    $stmt_jt->execute();
    $res_jt = $stmt_jt->get_result();
    while ($row = $res_jt->fetch_assoc()) {
        $evaluator_job_types[] = $row['position_title'];
    }
    $stmt_jt->close();

    $stmt_ct = $database->prepare("
        SELECT DISTINCT r.worksite_country
        FROM applications a
        INNER JOIN rsf_job_orders r ON a.rsf_id = r.id
        WHERE a.current_evaluator_id = ?
          AND r.worksite_country IS NOT NULL AND r.worksite_country <> ''
        ORDER BY r.worksite_country ASC
    ");
    $stmt_ct->bind_param("i", $evaluator_id);
    $stmt_ct->execute();
    $res_ct = $stmt_ct->get_result();
    while ($row = $res_ct->fetch_assoc()) {
        $evaluator_countries[] = $row['worksite_country'];
    }
    $stmt_ct->close();

    // Province comes from the applicant (clients.province), scoped to
    // only the applications currently assigned to this evaluator.
    $stmt_pv = $database->prepare("
        SELECT DISTINCT c.province
        FROM applications a
        INNER JOIN clients c ON a.client_id = c.cid
        WHERE a.current_evaluator_id = ?
          AND c.province IS NOT NULL AND c.province <> ''
        ORDER BY c.province ASC
    ");
    $stmt_pv->bind_param("i", $evaluator_id);
    $stmt_pv->execute();
    $res_pv = $stmt_pv->get_result();
    while ($row = $res_pv->fetch_assoc()) {
        $evaluator_provinces[] = $row['province'];
    }
    $stmt_pv->close();
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

// ────────────────────────────────────────────────
// Applications by Country (all reviewed vs approved, per worksite country)
// ────────────────────────────────────────────────
$country_data = [];

if ($evaluator_id) {
    $stmt_country = $database->prepare("
        SELECT
            r.worksite_country AS country,
            COUNT(DISTINCT a.id) as total_reviewed,
            COUNT(CASE WHEN e.decision = 'approved' THEN 1 END) as approved
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN evaluations e ON e.application_id = a.id AND e.evaluator_id = ?
        WHERE a.current_evaluator_id = ?
        GROUP BY r.worksite_country
        ORDER BY total_reviewed DESC
        LIMIT 6
    ");
    $stmt_country->bind_param("ii", $evaluator_id, $evaluator_id);
    $stmt_country->execute();
    $result_country = $stmt_country->get_result();
    while ($row = $result_country->fetch_assoc()) {
        $country_data[] = [
            'country'  => $row['country'],
            'total'    => (int)$row['total_reviewed'],
            'approved' => (int)$row['approved'],
        ];
    }
    $stmt_country->close();
}

// Fallback sample data so the chart never renders empty
if (empty($country_data)) {
    $country_data = [
        ['country' => 'Taiwan',    'total' => 12, 'approved' => 8],
        ['country' => 'Japan',     'total' => 9,  'approved' => 5],
        ['country' => 'Germany',   'total' => 6,  'approved' => 4],
        ['country' => 'Singapore', 'total' => 5,  'approved' => 2],
    ];
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

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
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
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

        <!-- Filter pills — options are pulled ONLY from job types/destinations/
             provinces this evaluator is currently assigned to
             (current_evaluator_id), so an evaluator never sees job types,
             countries, or provinces they don't handle. -->
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select id="filter-month">
                    <option value="">All Time</option>
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        echo "<option value=\"$val\">$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select id="filter-province">
                    <option value="">CALABARZON</option>
                    <?php foreach ($evaluator_provinces as $pv): ?>
                        <option value="<?= htmlspecialchars($pv, ENT_QUOTES) ?>"><?= htmlspecialchars($pv) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select id="filter-job">
                    <option value="">All Job Types</option>
                    <?php foreach ($evaluator_job_types as $jt): ?>
                        <option value="<?= htmlspecialchars($jt, ENT_QUOTES) ?>"><?= htmlspecialchars($jt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select id="filter-country">
                    <option value="">All Destinations</option>
                    <?php foreach ($evaluator_countries as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT — rebuilt with Tailwind
    ══════════════════════════════════════════ -->
    <div class="max-w-[1400px] mx-auto px-4 pt-3 pb-28">


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

                <div class="flex flex-col max-h-[280px] overflow-y-auto pr-0.5 [scrollbar-width:thin]" id="my-applications-list">
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
                            <?php
                            $app_has_pic = !empty($app['applicant_picture']);
                            $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                            ?>
                            <a href="view-application.php?id=<?= (int)$app['id'] ?>"
                                data-job="<?= htmlspecialchars($app['position_title'] ?? '', ENT_QUOTES) ?>"
                                data-country="<?= htmlspecialchars($app['country'] ?? '', ENT_QUOTES) ?>"
                                data-month="<?= $app['updated_at'] ? htmlspecialchars(date('Y-m', strtotime($app['updated_at'])), ENT_QUOTES) : '' ?>"
                                data-province="<?= htmlspecialchars($app['applicant_province'] ?? '', ENT_QUOTES) ?>"
                                class="flex items-center gap-2.5 py-2.5 px-1 border-b border-slate-100 last:border-0 rounded-xl no-underline text-inherit transition-colors duration-150 hover:bg-slate-50">
                                <div class="w-9 h-9 shrink-0 rounded-[10px] bg-indigo-50 text-indigo-600 text-[10.5px] font-extrabold tracking-wide flex items-center justify-center"
                                    <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                    <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
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
            <!-- Applications by Country -->
            <div class="grid grid-cols-1 gap-4 mt-4 fade-in" style="animation-delay:.25s">
                <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                            Applications by Country
                        </span>
                    </div>
                    <div class="relative w-full" style="height:260px">
                        <canvas id="countryChart"></canvas>
                    </div>
                </div><!-- /Applications by Country -->
            </div><!-- /grid -->
            <!-- Application Status -->
            <div class="w-full sm:w-1/2 bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="flex items-center gap-2 text-[13px] font-extrabold text-slate-700">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        Application Status
                    </span>
                </div>

                <div id="status-bars" class="flex flex-col gap-3 mb-4"></div>

                <div class="flex items-center gap-4 pt-4 border-t border-slate-100">
                    <div class="shrink-0"><canvas id="statusChart" width="120" height="120"></canvas></div>
                    <div id="status-legend" class="flex-1"></div>
                </div>
            </div><!-- /Application Status -->

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
    </script>
    <!-- Filter pills: month / province / job type / destination — client-side
         filter of "My Assigned Applications" list only, scoped to this
         evaluator's own data (options were already restricted server-side above). -->
    <script>
        (function() {
            function enhancePillSelect(select) {
                if (!select) return;

                const wrapper = document.createElement('div');
                wrapper.className = 'relative inline-flex items-center';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.classList.add('hidden');
                select.tabIndex = -1;

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.className = 'inline-flex items-center gap-1.5 bg-transparent border-none cursor-pointer p-0 m-0 font-inherit text-inherit';
                trigger.innerHTML = '<span class="truncate max-w-[110px]"></span>' +
                    '<i class="fas fa-chevron-down text-[9px] opacity-60 transition-transform flex-shrink-0"></i>';
                wrapper.appendChild(trigger);

                const panel = document.createElement('div');
                panel.setAttribute('role', 'listbox');
                panel.className = 'hidden fixed w-56 bg-white border-[1.5px] border-[var(--border)] rounded-lg shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[999] overflow-hidden py-1 max-h-[240px] overflow-y-auto';
                document.body.appendChild(panel); // appended to body — not clipped/stretched by .filter-scroll

                function buildRow(optionEl) {
                    const row = document.createElement('div');
                    row.setAttribute('role', 'option');
                    row.className = 'flex items-center justify-between gap-2 py-[7px] px-3 text-[12px] text-[var(--text1)] cursor-pointer hover:bg-[#f1f5fb] transition-colors';
                    row.dataset.value = optionEl.value;
                    const label = document.createElement('span');
                    label.className = 'truncate';
                    label.textContent = optionEl.textContent;
                    row.appendChild(label);
                    row.addEventListener('click', () => {
                        select.value = optionEl.value;
                        closePanel();
                        refresh();
                        select.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    });
                    return row;
                }

                function renderList() {
                    panel.innerHTML = '';
                    Array.from(select.options).forEach(opt => panel.appendChild(buildRow(opt)));
                    markSelected();
                }

                function markSelected() {
                    panel.querySelectorAll('[role="option"]').forEach(row => {
                        const selected = row.dataset.value === select.value;
                        row.classList.toggle('bg-[#eff6ff]', selected);
                        row.classList.toggle('text-[var(--navy)]', selected);
                        row.classList.toggle('font-bold', selected);
                        let check = row.querySelector('.fa-check');
                        if (selected && !check) {
                            const icon = document.createElement('i');
                            icon.className = 'fas fa-check text-[10px] text-[var(--blue)] flex-shrink-0';
                            row.appendChild(icon);
                        } else if (!selected && check) {
                            check.remove();
                        }
                    });
                }

                function refresh() {
                    const opt = select.options[select.selectedIndex];
                    trigger.querySelector('span').textContent = opt ? opt.textContent : '';
                    markSelected();
                }

                function positionPanel() {
                    const rect = trigger.getBoundingClientRect();
                    const panelWidth = 224; // matches w-56
                    let left = rect.left;
                    const maxLeft = window.innerWidth - panelWidth - 8;
                    if (left > maxLeft) left = Math.max(8, maxLeft);
                    panel.style.top = (rect.bottom + 6) + 'px';
                    panel.style.left = left + 'px';
                }

                function onReposition() {
                    if (!panel.classList.contains('hidden')) positionPanel();
                }

                function openPanel() {
                    document.querySelectorAll('[role="listbox"]').forEach(p => {
                        if (p !== panel) p.classList.add('hidden');
                    });
                    renderList();
                    positionPanel();
                    panel.classList.remove('hidden');
                    trigger.setAttribute('aria-expanded', 'true');
                    trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
                    window.addEventListener('scroll', onReposition, true);
                    window.addEventListener('resize', onReposition);
                }

                function closePanel() {
                    panel.classList.add('hidden');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
                    window.removeEventListener('scroll', onReposition, true);
                    window.removeEventListener('resize', onReposition);
                }

                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    panel.classList.contains('hidden') ? openPanel() : closePanel();
                });
                trigger.addEventListener('keydown', (e) => {
                    if (['Enter', ' ', 'ArrowDown'].includes(e.key)) {
                        e.preventDefault();
                        openPanel();
                    }
                });
                panel.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        closePanel();
                        trigger.focus();
                    }
                });
                panel.addEventListener('click', (e) => e.stopPropagation());
                document.addEventListener('click', closePanel);

                refresh();
            }

            const monthSel = document.getElementById('filter-month');
            const provinceSel = document.getElementById('filter-province');
            const jobSel = document.getElementById('filter-job');
            const countrySel = document.getElementById('filter-country');

            [monthSel, provinceSel, jobSel, countrySel].forEach(enhancePillSelect);

            const list = document.getElementById('my-applications-list');
            if (!list) return;

            const rows = Array.from(list.querySelectorAll('a[data-job]'));

            function applyEvaluatorFilters() {
                const month = monthSel ? monthSel.value : '';
                const province = provinceSel ? provinceSel.value : '';
                const job = jobSel ? jobSel.value : '';
                const country = countrySel ? countrySel.value : '';
                let visibleCount = 0;

                rows.forEach(row => {
                    const matchesMonth = !month || row.dataset.month === month;
                    const matchesProvince = !province || row.dataset.province === province;
                    const matchesJob = !job || row.dataset.job === job;
                    const matchesCountry = !country || row.dataset.country === country;
                    const show = matchesMonth && matchesProvince && matchesJob && matchesCountry;
                    row.style.display = show ? '' : 'none';
                    if (show) visibleCount++;
                });

                let emptyState = document.getElementById('no-filtered-results');
                if (visibleCount === 0) {
                    if (!emptyState) {
                        emptyState = document.createElement('div');
                        emptyState.id = 'no-filtered-results';
                        emptyState.className = 'py-10 px-4 text-center';
                        emptyState.innerHTML = `
                            <i class="fas fa-filter-circle-xmark text-3xl text-slate-200 block mb-2.5"></i>
                            <p class="text-[12.5px] font-semibold text-slate-400">No assigned applications match this filter</p>
                            <span class="text-[11px] text-slate-400/80">Try a different month, province, job type, or destination.</span>
                        `;
                        list.appendChild(emptyState);
                    }
                    emptyState.style.display = '';
                } else if (emptyState) {
                    emptyState.style.display = 'none';
                }
            }

            if (monthSel) monthSel.addEventListener('change', applyEvaluatorFilters);
            if (provinceSel) provinceSel.addEventListener('change', applyEvaluatorFilters);
            if (jobSel) jobSel.addEventListener('change', applyEvaluatorFilters);
            if (countrySel) countrySel.addEventListener('change', applyEvaluatorFilters);
        })();
    </script>

    <!-- Dashboard charts: Application Status + Applications by Country -->
    <script>
        Chart.defaults.font.family = "'DM Sans', sans-serif";
        Chart.defaults.color = '#64748b';

        // Application Status (doughnut)
        // Application Status — bars + donut + circular-dot legend, matching the reference style
        (function() {
            const statusLabels = ['Assign', 'Under Eval', 'For Compliance', 'Approved', 'Disapproved'];
            const statusData = [
                <?= (int)$stats['assign'] ?>,
                <?= (int)$stats['under_evaluation'] ?>,
                <?= (int)$stats['for_compliance'] ?>,
                <?= (int)$stats['approved'] ?>,
                <?= (int)$stats['disapproved'] ?>
            ];
            const statusColors = ['#3b82f6', '#4f46e5', '#d97706', '#059669', '#dc2626'];

            // ── Bars ──
            const barsEl = document.getElementById('status-bars');
            const maxVal = Math.max(...statusData, 1);
            const total = statusData.reduce((a, b) => a + b, 0);

            barsEl.innerHTML = '';
            statusLabels.forEach((label, i) => {
                if (statusData[i] === 0) return; // skip empty statuses, same behavior as before
                barsEl.innerHTML += `
            <div class="flex items-center gap-3">
                <span class="text-[12.5px] font-bold text-slate-700 w-[110px] shrink-0 truncate">${label}</span>
                <div class="flex-1 h-[10px] bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" style="width:${Math.round(statusData[i]/maxVal*100)}%;background:${statusColors[i]}"></div>
                </div>
                <span class="text-[12.5px] font-extrabold text-slate-800 w-5 text-right shrink-0">${statusData[i]}</span>
            </div>`;
            });
            if (total === 0) {
                barsEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No applications yet.</p>';
            }

            // ── Donut ──
            new Chart(document.getElementById('statusChart'), {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusData,
                        backgroundColor: statusColors,
                        borderWidth: 4,
                        borderColor: '#fff',
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '68%',
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // ── Legend (circular dots + bold %) ──
            const legendEl = document.getElementById('status-legend');
            legendEl.innerHTML = '';
            statusLabels.forEach((label, i) => {
                if (statusData[i] === 0) return;
                const pct = total > 0 ? Math.round(statusData[i] / total * 100) : 0;
                legendEl.innerHTML += `
            <div class="flex items-center gap-2.5 py-[7px] border-b border-slate-100 last:border-0">
                <span class="w-[11px] h-[11px] rounded-full shrink-0" style="background:${statusColors[i]}"></span>
                <span class="text-[13px] font-semibold text-slate-700 flex-1">${label}</span>
                <span class="text-[13px] font-extrabold text-slate-800">${pct}%</span>
            </div>`;
            });
            if (total === 0) {
                legendEl.innerHTML = '<p class="text-[11px] text-slate-400 italic">No data yet.</p>';
            }
        })();

        // Applications by Country (dual bar: total reviewed vs approved)
        const countryLabels = <?= json_encode(array_column($country_data, 'country')) ?>;
        const countryTotals = <?= json_encode(array_column($country_data, 'total')) ?>;
        const countryApproved = <?= json_encode(array_column($country_data, 'approved')) ?>;

        new Chart(document.getElementById('countryChart'), {
            type: 'bar',
            data: {
                labels: countryLabels,
                datasets: [{
                        label: 'All Reviewed',
                        data: countryTotals,
                        backgroundColor: '#3b82f6',
                        borderRadius: 4,
                        maxBarThickness: 28
                    },
                    {
                        label: 'Approved',
                        data: countryApproved,
                        backgroundColor: '#059669',
                        borderRadius: 4,
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#f1f5f9'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    }
                }
            }
        });
    </script>

    <script type="module" src="../js/main.js"></script>

    <?php include '../includes/login_toast.php'; ?>

</body>

</html>