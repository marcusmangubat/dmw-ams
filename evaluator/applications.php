<?php
// evaluator/applications.php – List applications for evaluation
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    header('Location: ../login.php');
    exit;
}

$evaluator_email = $_SESSION['user_email'];

// Get evaluator eid
$evaluator_name = 'Evaluator';
$evaluator_id   = null;
$stmt_ev = $database->prepare("SELECT eid, full_name FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $row = $ev_result->fetch_assoc();
    $evaluator_id   = (int)$row['eid'];
    $evaluator_name = $row['full_name'] ?? 'Evaluator';
}
$stmt_ev->close();

// Avatar initials
$parts    = explode(' ', trim($evaluator_name));
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($evaluator_name, 0, 2));

// Filter by status ('available' = the shared queue every evaluator can see
// and pick up, shown oldest-waiting-first; 'assign' includes legacy
// assigned)
$valid_statuses = ['available', 'assign', 'assigned', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$status_filter  = $_GET['status'] ?? '';
$status_filter  = in_array($status_filter, $valid_statuses, true) ? $status_filter : '';

// Search box — matches applicant name, email, position, or employer.
$search_filter = trim($_GET['q'] ?? '');

// Pagination
$per_page = 10;
$page     = max(1, (int) ($_GET['page'] ?? 1));

// Build query.
// 'available' is the one shared view: every evaluator sees the same
// unclaimed queue so any of them can pick up and evaluate an application.
// Every other tab is scoped strictly to the logged-in evaluator — an
// evaluator can only see/manage applications currently assigned to them.
$applications    = [];
$total_filtered  = 0;
$total_pages     = 1;

if ($status_filter === 'available') {
    // Shared queue, visible to all evaluators, oldest-waiting first —
    // first passed into the queue, first shown.
    $sql = "
        SELECT a.id, a.status, a.updated_at,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email,
               c.profile_picture AS applicant_picture
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted')
    ";
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted')
    ";
    $params = [];
    $types  = "";

    if ($search_filter !== '') {
        $searchCond = " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $sql      .= $searchCond;
        $countSql .= $searchCond;
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $stmt_count = $database->prepare($countSql);
    if ($params) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt_count->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $sql .= " ORDER BY a.updated_at ASC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types   .= "ii";

    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($evaluator_id) {
    // My cases: anything I currently own, plus anything on record as mine
    // even while Admin has it for compliance (current_evaluator_id keeps
    // it visible to me, read-only, until Admin reopens or reassigns it).
    $sql = "
        SELECT a.id, a.status, a.updated_at, a.handling_party,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email,
               c.profile_picture AS applicant_picture
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
    ";
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
    ";
    $params = [$evaluator_id];
    $types  = "i";

    if ($status_filter === 'assign' || $status_filter === 'assigned') {
        $sql      .= " AND a.status IN ('assign','assigned')";
        $countSql .= " AND a.status IN ('assign','assigned')";
    } elseif ($status_filter) {
        $sql      .= " AND a.status = ?";
        $countSql .= " AND a.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    if ($search_filter !== '') {
        $searchCond = " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $sql      .= $searchCond;
        $countSql .= $searchCond;
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $stmt_count = $database->prepare($countSql);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_filtered = (int) ($stmt_count->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt_count->close();

    $total_pages = max(1, (int) ceil($total_filtered / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $sql .= " ORDER BY a.updated_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types   .= "ii";

    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Stats for the filter tiles.
// 'available' reflects the shared queue (same for every evaluator).
// Every other bucket is scoped to the logged-in evaluator's own cases so
// the counts always match what that evaluator can actually see in the list.
$stats = ['available' => 0, 'assign' => 0, 'under_evaluation' => 0, 'for_compliance' => 0, 'approved' => 0, 'disapproved' => 0, 'resubmitted' => 0];

$avail_res = $database->query("
    SELECT COUNT(*) AS cnt
    FROM applications
    WHERE handling_party IS NULL
      AND status IN ('pending','assign','assigned','resubmitted')
");
if ($avail_res) {
    $stats['available'] = (int) ($avail_res->fetch_assoc()['cnt'] ?? 0);
}

if ($evaluator_id) {
    $stmt_stats = $database->prepare("SELECT status, COUNT(*) as cnt FROM applications WHERE current_evaluator_id = ? GROUP BY status");
    $stmt_stats->bind_param("i", $evaluator_id);
    $stmt_stats->execute();
    $res = $stmt_stats->get_result();
    while ($row = $res->fetch_assoc()) {
        $s   = $row['status'];
        $cnt = (int) $row['cnt'];
        if ($s === 'assigned' || $s === 'assign') {
            $stats['assign'] += $cnt;
        } elseif (isset($stats[$s])) {
            $stats[$s] += $cnt;
        }
    }
    $stmt_stats->close();
}

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

$page_title = $status_filter
    ? ucwords(str_replace('_', ' ', $status_filter))
    : 'My Cases';

// Single source of truth for status colors/icons/labels — used for the
// stat/filter tiles AND the status pill on every row, so a status is only
// ever defined once.
$status_style = [
    'available'        => ['bg' => '#d1fae5', 'fg' => '#065f46', 'icon' => 'fa-inbox',               'label' => 'Available'],
    'pending'          => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assign'           => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'assigned'         => ['bg' => '#dbeafe', 'fg' => '#1e40af', 'icon' => 'fa-user-check',           'label' => 'Assign'],
    'under_evaluation' => ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'icon' => 'fa-magnifying-glass',     'label' => 'Under Evaluation'],
    'for_compliance'   => ['bg' => '#ffedd5', 'fg' => '#92400e', 'icon' => 'fa-triangle-exclamation', 'label' => 'For Compliance'],
    'approved'         => ['bg' => '#d1fae5', 'fg' => '#065f46', 'icon' => 'fa-circle-check',         'label' => 'Approved'],
    'disapproved'      => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'icon' => 'fa-circle-xmark',         'label' => 'Disapproved'],
    'resubmitted'      => ['bg' => '#fef3c7', 'fg' => '#92400e', 'icon' => 'fa-rotate-right',         'label' => 'Resubmitted'],
    'default'          => ['bg' => '#f1f5f9', 'fg' => '#475569', 'icon' => 'fa-circle',               'label' => 'Unknown'],
];

function status_color(string $key, array $map): array
{
    return $map[$key] ?? $map['default'];
}

// Statuses that are locked from the evaluator's perspective — the case is
// currently out of their hands (with Admin) or already finalized, so there
// is nothing left for them to open or act on.
//   - for_compliance : sitting with Admin for compliance review
//   - resubmitted     : same situation as for_compliance — needs Admin to
//                       open/reassign it back to an evaluator first
//   - approved        : terminal, decision already made
//   - disapproved     : terminal, decision already made
// NOT locked: pending (shared queue), assign/assigned (owned by this
// evaluator), under_evaluation (reopened by Admin back to the evaluator).
$locked_statuses = ['for_compliance', 'resubmitted', 'approved', 'disapproved'];

// Order + keys for the stat/filter tile row (mirrors the original 7 filters)
$filter_keys = ['available', 'assign', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$total_count = array_sum($stats);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – Evaluator Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Shared design system (topbar / dock / CSS variables live here) -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <!-- Tailwind, used for everything below the topbar -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .font-display {
            font-family: 'DM Serif Display', serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        /* Fade-in-up entrance, staggered per row/tile via inline delay */
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

        /* Hide scrollbar on the mobile chip rail but keep it scrollable */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Locked row/card visual cue */
        .row-locked {
            opacity: .6;
            cursor: not-allowed !important;
        }

        /* ══════════════════════════════════════════
           RESPONSIVE KPI / FILTER TILES
           Cards, text, icons, padding, and spacing all
           scale down smoothly as the viewport shrinks so
           nothing overlaps or overflows on small screens.
        ══════════════════════════════════════════ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        @media (min-width: 480px) {
            .kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 9px;
            }
        }

        @media (min-width: 640px) {
            .kpi-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 10px;
            }
        }

        @media (min-width: 1024px) {
            .kpi-grid {
                grid-template-columns: repeat(8, minmax(0, 1fr));
                gap: 10px;
            }
        }

        .kpi-tile {
            min-width: 0;
            /* allow children to shrink instead of overflowing the grid cell */
            padding: 10px 11px;
            border-radius: 14px;
            gap: 6px;
        }

        @media (min-width: 480px) {
            .kpi-tile {
                padding: 11px 12px;
                border-radius: 15px;
            }
        }

        @media (min-width: 768px) {
            .kpi-tile {
                padding: 13px 14px;
                border-radius: 16px;
            }
        }

        .kpi-label {
            font-size: 8.5px;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (min-width: 480px) {
            .kpi-label {
                font-size: 9px;
            }
        }

        @media (min-width: 768px) {
            .kpi-label {
                font-size: 10px;
                gap: 6px;
            }
        }

        .kpi-label i {
            font-size: 9px;
            flex-shrink: 0;
        }

        @media (min-width: 480px) {
            .kpi-label i {
                font-size: 10px;
            }
        }

        @media (min-width: 768px) {
            .kpi-label i {
                font-size: 11px;
            }
        }

        .kpi-value {
            font-size: 17px;
            line-height: 1.15;
        }

        @media (min-width: 480px) {
            .kpi-value {
                font-size: 19px;
            }
        }

        @media (min-width: 640px) {
            .kpi-value {
                font-size: 21px;
            }
        }

        @media (min-width: 1024px) {
            .kpi-value {
                font-size: 24px;
            }
        }

        /* Page header + search: scale text/spacing down on small screens */
        .page-heading {
            font-size: 17px;
        }

        @media (min-width: 480px) {
            .page-heading {
                font-size: 19px;
            }
        }

        @media (min-width: 768px) {
            .page-heading {
                font-size: 22px;
            }
        }

        .page-subline {
            font-size: 10.5px;
        }

        @media (min-width: 480px) {
            .page-subline {
                font-size: 11.5px;
            }
        }
    </style>
</head>

<body class="bg-[var(--bg,#f4f6fb)]">

    <!-- ══════════════════════════════════════════
         TOPBAR (unchanged)
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
         PAGE HEAD + STAT / FILTER TILES
    ══════════════════════════════════════════ -->
    <main class="max-w-[1400px] mx-auto px-3 sm:px-4 pt-4 sm:pt-5" style="padding-bottom: calc(var(--dock-h) + 28px + env(safe-area-inset-bottom));">

        <div class="flex items-center justify-between gap-3 flex-wrap mb-4 sm:mb-5 fade-in" style="animation-delay:.02s">
            <div class="min-w-0">
                <h2 class="font-display page-heading leading-tight text-[var(--navy)] truncate"><?= htmlspecialchars($page_title) ?></h2>
                <p class="page-subline font-medium text-[var(--text3)] mt-1" id="app-count-line">
                    <?= $total_filtered ?> application<?= $total_filtered !== 1 ? 's' : '' ?> found<?= $status_filter ? ' · filtered by "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?><?= $search_filter !== '' ? ' · matching "' . htmlspecialchars($search_filter) . '"' : '' ?> · Page <?= $page ?> of <?= $total_pages ?>
                </p>
            </div>
            <a href="index.php"
                class="inline-flex items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 sm:px-4 sm:py-2 text-[11px] sm:text-xs font-semibold text-[var(--text2)] no-underline transition hover:-translate-y-0.5 hover:bg-[var(--surface)] flex-shrink-0">
                <i class="fas fa-arrow-left"></i> <span class="hidden xs:inline">Dashboard</span>
            </a>
        </div>

        <!-- Search (live — filters the list as you type, no page reload) -->
        <div class="fade-in mb-4 sm:mb-5" style="animation-delay:.03s">
            <div class="relative w-full md:w-2/3 lg:w-1/2">
                <i class="fas fa-magnifying-glass absolute left-3.5 sm:left-4 top-1/2 -translate-y-1/2 text-[12px] sm:text-[13px] text-[var(--text3)]"></i>
                <input
                    type="text"
                    id="app-search-input"
                    value="<?= htmlspecialchars($search_filter) ?>"
                    placeholder="Search by name, email, position, employer, RSF #, or country…"
                    autocomplete="off"
                    class="w-full rounded-2xl border border-[var(--border)] bg-[var(--card)] py-2.5 sm:py-3 pl-10 sm:pl-11 pr-10 sm:pr-11 text-[12px] sm:text-[13px] font-medium text-[var(--text1)] outline-none transition focus:border-[var(--navy)] focus:ring-1 focus:ring-[var(--navy)]">
                <button type="button" id="app-search-clear"
                    class="absolute right-3 sm:right-3.5 top-1/2 -translate-y-1/2 text-[11px] sm:text-[12px] text-[var(--text3)] hover:text-[var(--text1)]"
                    title="Clear search" style="<?= $search_filter === '' ? 'display:none' : '' ?>">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="kpi-grid mb-5 sm:mb-6">

            <a href="applications.php<?= $search_filter !== '' ? '?q=' . urlencode($search_filter) : '' ?>"
                class="kpi-tile filter-tile fade-in group relative overflow-hidden border <?= !$status_filter ? 'border-[var(--navy)] ring-1 ring-[var(--navy)]' : 'border-[var(--border)]' ?> bg-[var(--card)] flex flex-col justify-between no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                data-base="applications.php"
                style="animation-delay:.05s">
                <span class="kpi-label flex items-center font-bold uppercase tracking-wide text-[var(--text3)]">
                    <i class="fas fa-list"></i> <span class="truncate">All</span>
                </span>
                <span class="kpi-value font-extrabold text-[var(--text1)]"><?= $total_count ?></span>
            </a>

            <?php foreach ($filter_keys as $i => $key):
                $sc       = status_color($key, $status_style);
                $isActive = ($key === 'assign')
                    ? ($status_filter === 'assign' || $status_filter === 'assigned')
                    : ($status_filter === $key);
                $delay    = .05 + (($i + 1) * .03);
            ?>
                <a href="applications.php?status=<?= $key ?><?= $search_filter !== '' ? '&q=' . urlencode($search_filter) : '' ?>"
                    class="kpi-tile filter-tile fade-in group relative overflow-hidden border <?= $isActive ? 'border-[var(--navy)] ring-1 ring-[var(--navy)]' : 'border-[var(--border)]' ?> bg-[var(--card)] flex flex-col justify-between no-underline transition hover:-translate-y-0.5 hover:shadow-lg"
                    data-base="applications.php?status=<?= $key ?>"
                    style="animation-delay:<?= $delay ?>s">
                    <span class="absolute inset-y-0 left-0 w-1" style="background:<?= $sc['fg'] ?>"></span>
                    <span class="kpi-label flex items-center font-bold uppercase tracking-wide text-[var(--text3)]">
                        <i class="fas <?= $sc['icon'] ?>" style="color:<?= $sc['fg'] ?>"></i>
                        <span class="truncate"><?= htmlspecialchars($sc['label']) ?></span>
                    </span>
                    <span class="kpi-value font-extrabold text-[var(--text1)]"><?= $stats[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════════════════════════════════════
             APPLICATION LIST
        ══════════════════════════════════════════ -->
        <div id="empty-state" class="fade-in rounded-2xl border border-[var(--border)] bg-[var(--card)] py-10 sm:py-14 px-4 sm:px-6 text-center" style="animation-delay:.1s;<?= empty($applications) ? '' : 'display:none' ?>">
            <i class="fas fa-inbox text-3xl sm:text-4xl text-[var(--border)] block mb-3"></i>
            <p class="text-[13px] sm:text-sm font-bold text-[var(--text2)]">No applications found</p>
            <span class="text-[11px] sm:text-xs text-[var(--text3)]" id="empty-state-text">
                <?php if ($search_filter !== ''): ?>
                    No results for "<?= htmlspecialchars($search_filter) ?>"<?= $status_filter ? ' in "' . htmlspecialchars(str_replace('_', ' ', $status_filter)) . '"' : '' ?>. Try a different name, email, position, employer, RSF #, or country.
                <?php elseif ($status_filter === 'available'): ?>
                    Nothing waiting to be claimed right now — check back later.
                <?php elseif ($status_filter): ?>
                    No cases of yours with status "<?= htmlspecialchars(str_replace('_', ' ', $status_filter)) ?>".
                <?php else: ?>
                    You have no cases yet — check the Available tile to claim one.
                <?php endif; ?>
            </span>
        </div>

        <div id="results-wrapper" style="<?= empty($applications) ? 'display:none' : '' ?>">

            <!-- Desktop / tablet: real table, one row per application -->
            <div class="hidden md:block rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden fade-in" style="animation-delay:.1s">
                <div class="overflow-x-auto no-scrollbar">
                    <table class="w-full min-w-[1100px] border-collapse text-sm">
                        <thead>
                            <tr class="bg-[var(--surface)] text-[10px] font-bold uppercase tracking-wide text-[var(--text3)]">
                                <th class="text-left px-5 py-3">Applicant</th>
                                <th class="text-left px-5 py-3">Position &amp; Employer</th>
                                <th class="text-left px-5 py-3">RSF / Country</th>
                                <th class="text-left px-5 py-3">Status</th>
                                <th class="text-left px-5 py-3">Updated</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody id="app-tbody">
                            <?php foreach ($applications as $app):
                                $status     = strtolower($app['status'] ?? 'unknown');
                                $statusText = str_replace('_', ' ', ucwords($status));
                                $sc         = status_color($status, $status_style);
                                $isLocked   = in_array($status, $locked_statuses, true);

                                $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                                $avi = strtoupper(count($np) >= 2
                                    ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                                    : substr($app['applicant_name'] ?? 'NA', 0, 2));
                            ?>
                                <tr class="border-t border-[var(--border)] cursor-pointer transition hover:bg-[var(--surface)] <?= $isLocked ? 'row-locked' : '' ?>"
                                    onclick="openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')">
                                    <?php
                                    $app_has_pic = !empty($app['applicant_picture']);
                                    $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                                    ?>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] font-extrabold text-[var(--indigo)]"
                                                <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                                                <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="truncate text-[13px] font-bold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                                                <div class="truncate text-[11px] font-medium text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-[12.5px] font-semibold text-[var(--text1)]"><?= htmlspecialchars($app['position_title'] ?? '—') ?></div>
                                        <div class="text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['employer_name'] ?? '—') ?></div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-[12.5px] font-bold text-[var(--blue)]"><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></div>
                                        <div class="text-[11px] text-[var(--text3)]"><?= htmlspecialchars($app['country'] ?? '—') ?></div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide"
                                            style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                                            <i class="fas <?= $sc['icon'] ?>"></i><?= htmlspecialchars($statusText) ?>
                                        </span>
                                        <?php if ($isLocked): ?>
                                            <i class="fas fa-lock text-[10px] text-[var(--text3)] ml-1.5" title="Locked"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-[11.5px] text-[var(--text3)]">
                                        <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                                    </td>
                                    <td class="px-5 py-3 text-right text-[var(--text3)]">
                                        <i class="fas <?= $isLocked ? 'fa-lock' : 'fa-chevron-right' ?> text-xs"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Mobile: stacked cards -->
            <div class="md:hidden flex flex-col gap-2 sm:gap-2.5" id="app-cards">
                <?php foreach ($applications as $i => $app):
                    $status     = strtolower($app['status'] ?? 'unknown');
                    $statusText = str_replace('_', ' ', ucwords($status));
                    $sc         = status_color($status, $status_style);
                    $isLocked   = in_array($status, $locked_statuses, true);

                    $np  = explode(' ', trim($app['applicant_name'] ?? ''));
                    $avi = strtoupper(count($np) >= 2
                        ? substr($np[0], 0, 1) . substr(end($np), 0, 1)
                        : substr($app['applicant_name'] ?? 'NA', 0, 2));

                    $delay = .1 + min($i, 6) * .03;
                ?>
                    <a href="javascript:void(0)"
                        onclick="return openApplication(<?= (int)$app['id'] ?>, '<?= htmlspecialchars($status, ENT_QUOTES) ?>')"
                        class="fade-in flex items-center gap-2.5 sm:gap-3 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 sm:p-3.5 no-underline text-inherit transition hover:-translate-y-0.5 hover:shadow-md <?= $isLocked ? 'row-locked' : '' ?>"
                        style="animation-delay:<?= $delay ?>s">

                        <?php
                        $app_has_pic = !empty($app['applicant_picture']);
                        $app_pic_url = $app_has_pic ? '../' . $app['applicant_picture'] : null;
                        ?>
                        <div class="flex h-10 w-10 sm:h-11 sm:w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] sm:text-xs font-extrabold text-[var(--indigo)]"
                            <?= $app_has_pic ? "style=\"background-image:url('" . htmlspecialchars($app_pic_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                            <?= $app_has_pic ? '' : htmlspecialchars($avi) ?>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[13px] sm:text-[13.5px] font-bold text-[var(--text1)]"><?= htmlspecialchars($app['applicant_name'] ?? '—') ?></div>
                            <div class="truncate text-[10.5px] sm:text-[11px] font-medium text-[var(--text3)]"><?= htmlspecialchars($app['applicant_email'] ?? '') ?></div>
                            <div class="mt-1 sm:mt-1.5 flex flex-wrap items-center gap-x-2 sm:gap-x-3 gap-y-1 text-[9.5px] sm:text-[10.5px] font-medium text-[var(--text3)]">
                                <span class="font-bold text-[var(--blue)]"><i class="fas fa-hashtag text-[8px] sm:text-[9px] mr-1"></i><?= htmlspecialchars($app['rsf_number'] ?? '—') ?></span>
                                <span><i class="fas fa-briefcase text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['position_title'] ?? '—') ?></span>
                                <span class="hidden xs:inline"><i class="fas fa-building text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['employer_name'] ?? '—') ?></span>
                                <span><i class="fas fa-earth-asia text-[8px] sm:text-[9px] mr-1 opacity-70"></i><?= htmlspecialchars($app['country'] ?? '—') ?></span>
                            </div>
                        </div>

                        <div class="flex flex-shrink-0 flex-col items-end gap-1 sm:gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 sm:px-2.5 py-0.5 sm:py-1 text-[8px] sm:text-[9px] font-bold uppercase tracking-wide"
                                style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                                <i class="fas <?= $isLocked ? 'fa-lock' : $sc['icon'] ?>"></i><span class="hidden xs:inline"><?= htmlspecialchars($statusText) ?></span>
                            </span>
                            <span class="text-[9px] sm:text-[10px] font-medium text-[var(--text3)]">
                                <?= $app['updated_at'] ? date('M d, Y', strtotime($app['updated_at'])) : '—' ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>

        <?php if ($total_pages > 1): ?>
            <div id="pagination-wrap" class="flex items-center justify-between mt-4 sm:mt-5 px-1">
                <span class="text-[10.5px] sm:text-[11.5px] font-medium text-[var(--text3)]">Page <?= $page ?> of <?= $total_pages ?></span>
                <div class="flex items-center gap-1 sm:gap-1.5" id="pagination-links">
                    <a href="javascript:void(0)" onclick="goToPage(<?= max(1, $page - 1) ?>)"
                        class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <a href="javascript:void(0)" onclick="goToPage(<?= $p ?>)"
                            class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $p === $page ? 'bg-[var(--navy)] text-white' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                    <a href="javascript:void(0)" onclick="goToPage(<?= min($total_pages, $page + 1) ?>)"
                        class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition <?= $page >= $total_pages ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- ══════════════════════════════════════════
         FLOATING DOCK (unchanged)
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="applications.php" class="dk active">
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

            <a href="settings.html" class="dk">
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

        // Statuses that are locked from the evaluator's side — mirrors the
        // PHP $locked_statuses array above. Kept in sync manually since one
        // lives server-side (for query/render logic) and one client-side
        // (for the click gate).
        const LOCKED_STATUSES = ['for_compliance', 'resubmitted', 'approved', 'disapproved'];
        const CURRENT_STATUS = <?= json_encode($status_filter) ?>;
        let CURRENT_PAGE = <?= json_encode($page) ?>;
        /**
         * Gate for opening an application row/card.
         * Returns false in all cases to stop any default <a>/onclick
         * navigation from firing alongside this handler.
         */
        function openApplication(id, status) {
            if (LOCKED_STATUSES.includes(status)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Application Locked',
                    text: 'This application is currently with Admin (or already finalized) and cannot be accessed until it is reopened or reassigned to an evaluator.',
                    confirmButtonColor: '#1e40af',
                    confirmButtonText: 'Understood'
                });
                return false;
            }
            // Coming from the shared Available queue — clicking through
            // claims the case for this evaluator, so confirm first.
            if (CURRENT_STATUS === 'available') {
                Swal.fire({
                    icon: 'question',
                    title: 'Evaluate this application?',
                    text: 'Opening this will claim it for evaluation and assign it to you. It will then leave the shared Available queue and other evaluators will no longer be able to pick it up.',
                    showCancelButton: true,
                    confirmButtonColor: '#1e40af',
                    cancelButtonColor: '#94a3b8',
                    confirmButtonText: 'Yes, claim & evaluate',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location = 'view-application.php?id=' + id;
                    }
                });
                return false;
            }

            window.location = 'view-application.php?id=' + id;
            return false;
            window.location = 'view-application.php?id=' + id;
            return false;
        }

        // ══════════════════════════════════════════
        // LIVE SEARCH — filters the list as you type,
        // no page reload. Mirrors the PHP status_style /
        // locked_statuses tables above so rendered rows
        // look identical to the server-rendered ones.
        // ══════════════════════════════════════════


        const STATUS_STYLE = {
            available: {
                bg: '#d1fae5',
                fg: '#065f46',
                icon: 'fa-inbox',
                label: 'Available'
            },
            pending: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assign: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            assigned: {
                bg: '#dbeafe',
                fg: '#1e40af',
                icon: 'fa-user-check',
                label: 'Assign'
            },
            under_evaluation: {
                bg: '#e0e7ff',
                fg: '#3730a3',
                icon: 'fa-magnifying-glass',
                label: 'Under Evaluation'
            },
            for_compliance: {
                bg: '#ffedd5',
                fg: '#92400e',
                icon: 'fa-triangle-exclamation',
                label: 'For Compliance'
            },
            approved: {
                bg: '#d1fae5',
                fg: '#065f46',
                icon: 'fa-circle-check',
                label: 'Approved'
            },
            disapproved: {
                bg: '#fee2e2',
                fg: '#991b1b',
                icon: 'fa-circle-xmark',
                label: 'Disapproved'
            },
            resubmitted: {
                bg: '#fef3c7',
                fg: '#92400e',
                icon: 'fa-rotate-right',
                label: 'Resubmitted'
            },
            default: {
                bg: '#f1f5f9',
                fg: '#475569',
                icon: 'fa-circle',
                label: 'Unknown'
            },
        };
        const statusStyle = (key) => STATUS_STYLE[key] || STATUS_STYLE.default;

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric'
            });
        }

        function avatarInitials(name) {
            const parts = (name || '').trim().split(/\s+/).filter(Boolean);
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return (name || 'NA').substring(0, 2).toUpperCase();
        }

        function renderRow(app) {
            const status = (app.status || 'unknown').toLowerCase();
            const statusText = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const sc = statusStyle(status);
            const isLocked = LOCKED_STATUSES.includes(status);
            const avi = avatarInitials(app.applicant_name);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';

            return `
                <tr class="border-t border-[var(--border)] cursor-pointer transition hover:bg-[var(--surface)] ${isLocked ? 'row-locked' : ''}"
                    onclick="openApplication(${app.id}, '${status}')">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] font-extrabold text-[var(--indigo)]" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                            <div class="min-w-0">
                                <div class="truncate text-[13px] font-bold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                                <div class="truncate text-[11px] font-medium text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3">
                        <div class="text-[12.5px] font-semibold text-[var(--text1)]">${escapeHtml(app.position_title || '—')}</div>
                        <div class="text-[11px] text-[var(--text3)]">${escapeHtml(app.employer_name || '—')}</div>
                    </td>
                    <td class="px-5 py-3">
                        <div class="text-[12.5px] font-bold text-[var(--blue)]">${escapeHtml(app.rsf_number || '—')}</div>
                        <div class="text-[11px] text-[var(--text3)]">${escapeHtml(app.country || '—')}</div>
                    </td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[9.5px] font-bold uppercase tracking-wide" style="background:${sc.bg};color:${sc.fg};">
                            <i class="fas ${sc.icon}"></i>${escapeHtml(statusText)}
                        </span>
                        ${isLocked ? '<i class="fas fa-lock text-[10px] text-[var(--text3)] ml-1.5" title="Locked"></i>' : ''}
                    </td>
                    <td class="px-5 py-3 text-[11.5px] text-[var(--text3)]">${formatDate(app.updated_at)}</td>
                    <td class="px-5 py-3 text-right text-[var(--text3)]">
                        <i class="fas ${isLocked ? 'fa-lock' : 'fa-chevron-right'} text-xs"></i>
                    </td>
                </tr>`;
        }

        function renderCard(app, i) {
            const status = (app.status || 'unknown').toLowerCase();
            const statusText = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const sc = statusStyle(status);
            const isLocked = LOCKED_STATUSES.includes(status);
            const avi = avatarInitials(app.applicant_name);
            const delay = (.1 + Math.min(i, 6) * .03).toFixed(2);
            const hasPic = !!app.applicant_picture;
            const picUrl = hasPic ? '../' + app.applicant_picture : '';
            const avatarStyle = hasPic ? `style="background-image:url('${picUrl}');background-size:cover;background-position:center;"` : '';

            return `
                <a href="javascript:void(0)"
                    onclick="return openApplication(${app.id}, '${status}')"
                    class="fade-in flex items-center gap-2.5 sm:gap-3 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 sm:p-3.5 no-underline text-inherit transition hover:-translate-y-0.5 hover:shadow-md ${isLocked ? 'row-locked' : ''}"
                    style="animation-delay:${delay}s">
                    <div class="flex h-10 w-10 sm:h-11 sm:w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[#eef2ff] text-[11px] sm:text-xs font-extrabold text-[var(--indigo)]" ${avatarStyle}>${hasPic ? '' : escapeHtml(avi)}</div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[13px] sm:text-[13.5px] font-bold text-[var(--text1)]">${escapeHtml(app.applicant_name || '—')}</div>
                        <div class="truncate text-[10.5px] sm:text-[11px] font-medium text-[var(--text3)]">${escapeHtml(app.applicant_email || '')}</div>
                        <div class="mt-1 sm:mt-1.5 flex flex-wrap items-center gap-x-2 sm:gap-x-3 gap-y-1 text-[9.5px] sm:text-[10.5px] font-medium text-[var(--text3)]">
                            <span class="font-bold text-[var(--blue)]"><i class="fas fa-hashtag text-[8px] sm:text-[9px] mr-1"></i>${escapeHtml(app.rsf_number || '—')}</span>
                            <span><i class="fas fa-briefcase text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.position_title || '—')}</span>
                            <span class="hidden xs:inline"><i class="fas fa-building text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.employer_name || '—')}</span>
                            <span><i class="fas fa-earth-asia text-[8px] sm:text-[9px] mr-1 opacity-70"></i>${escapeHtml(app.country || '—')}</span>
                        </div>
                    </div>
                    <div class="flex flex-shrink-0 flex-col items-end gap-1 sm:gap-1.5">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 sm:px-2.5 py-0.5 sm:py-1 text-[8px] sm:text-[9px] font-bold uppercase tracking-wide" style="background:${sc.bg};color:${sc.fg};">
                            <i class="fas ${isLocked ? 'fa-lock' : sc.icon}"></i><span class="hidden xs:inline">${escapeHtml(statusText)}</span>
                        </span>
                        <span class="text-[9px] sm:text-[10px] font-medium text-[var(--text3)]">${formatDate(app.updated_at)}</span>
                    </div>
                </a>`;
        }

        const searchInput = document.getElementById('app-search-input');
        const clearBtn = document.getElementById('app-search-clear');
        const countLine = document.getElementById('app-count-line');
        const emptyState = document.getElementById('empty-state');
        const emptyText = document.getElementById('empty-state-text');
        const resultsWrap = document.getElementById('results-wrapper');
        const tbody = document.getElementById('app-tbody');
        const cardsWrap = document.getElementById('app-cards');

        function renderResults(list, term, total = list.length, totalPages = 1, page = 1) {
            if (!list.length) {
                resultsWrap.style.display = 'none';
                emptyState.style.display = '';
                emptyText.textContent = term ?
                    `No results for "${term}"${CURRENT_STATUS ? ' in "' + CURRENT_STATUS.replace(/_/g, ' ') + '"' : ''}. Try a different name, email, position, employer, RSF #, or country.` :
                    'No applications found.';
            } else {
                emptyState.style.display = 'none';
                resultsWrap.style.display = '';
                tbody.innerHTML = list.map(renderRow).join('');
                cardsWrap.innerHTML = list.map(renderCard).join('');
            }

            countLine.textContent =
                `${total} application${total !== 1 ? 's' : ''} found` +
                (CURRENT_STATUS ? ` · filtered by "${CURRENT_STATUS.replace(/_/g, ' ')}"` : '') +
                (term ? ` · matching "${term}"` : '') +
                ` · Page ${page} of ${totalPages}`;

            renderPagination(totalPages, page);
        }

        function renderPagination(totalPages, page) {
            let wrap = document.getElementById('pagination-wrap');
            if (totalPages <= 1) {
                if (wrap) wrap.remove();
                return;
            }
            let linksHtml = `<a href="javascript:void(0)" onclick="goToPage(${Math.max(1, page - 1)})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${page <= 1 ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}"><i class="fas fa-chevron-left"></i></a>`;
            for (let p = 1; p <= totalPages; p++) {
                linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${p})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${p === page ? 'bg-[var(--navy)] text-white' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}">${p}</a>`;
            }
            linksHtml += `<a href="javascript:void(0)" onclick="goToPage(${Math.min(totalPages, page + 1)})" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center rounded-lg text-[11px] sm:text-xs font-semibold transition ${page >= totalPages ? 'pointer-events-none opacity-40' : 'bg-[var(--surface)] hover:bg-[var(--border)] text-[var(--text2)]'}"><i class="fas fa-chevron-right"></i></a>`;

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'pagination-wrap';
                wrap.className = 'flex items-center justify-between mt-4 sm:mt-5 px-1';
                resultsWrap.insertAdjacentElement('afterend', wrap);
            }
            wrap.innerHTML = `<span class="text-[10.5px] sm:text-[11.5px] font-medium text-[var(--text3)]">Page ${page} of ${totalPages}</span><div class="flex items-center gap-1 sm:gap-1.5" id="pagination-links">${linksHtml}</div>`;
        }

        function goToPage(p) {
            if (p < 1) return;
            CURRENT_PAGE = p;
            runSearch(false);
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        let searchDebounce = null;
        let searchAbort = null;

        function runSearch(resetPage = true) {
            const term = searchInput.value.trim();
            clearBtn.style.display = term ? '' : 'none';
            if (resetPage) CURRENT_PAGE = 1;

            if (searchAbort) searchAbort.abort();
            searchAbort = new AbortController();

            const params = new URLSearchParams();
            if (CURRENT_STATUS) params.set('status', CURRENT_STATUS);
            if (term) params.set('q', term);
            params.set('page', CURRENT_PAGE);

            fetch('applications_search.php?' + params.toString(), {
                    signal: searchAbort.signal
                })
                .then(r => r.json())
                .then(data => renderResults(data.applications || [], term, data.total ?? (data.applications || []).length, data.total_pages ?? 1, data.page ?? CURRENT_PAGE))
                .catch(err => {
                    if (err.name !== 'AbortError') console.error('Search failed:', err);
                });
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearBtn.style.display = searchInput.value ? '' : 'none';
                syncTileLinks();
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(runSearch, 300);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                syncTileLinks();
                clearTimeout(searchDebounce);
                runSearch();
            });
        }

        // Keep the status filter tiles' hrefs carrying the current search
        // term, so clicking one to change tabs doesn't lose what's typed.
        const tileLinks = document.querySelectorAll('.filter-tile');

        function syncTileLinks() {
            const term = searchInput ? searchInput.value.trim() : '';
            tileLinks.forEach(a => {
                const base = a.dataset.base;
                if (!term) {
                    a.href = base;
                    return;
                }
                a.href = base + (base.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(term);
            });
        }
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>