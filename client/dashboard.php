<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

</head>

<body>

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar">AU</div>
        </div>
    </header>
    <?php
    date_default_timezone_set('Asia/Manila');

    $hour = date('H');

    if ($hour < 12) {
        $greeting = "Good morning";
    } elseif ($hour < 18) {
        $greeting = "Good afternoon";
    } else {
        $greeting = "Good evening";
    }
    ?>
    <!-- SUBBAR  Greeting + Filters-->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?php echo $greeting; ?>, Admin</h2>
                <p>Current status of online applications</p>
            </div>
        </div>
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select>
                    <option>March 2026</option>
                    <option>Feb 2026</option>
                    <option>Jan 2026</option>
                    <option>Q1 2026</option>
                </select>
            </label>
            <label
                class="filter-pill flex items-center gap-1.5 px-3 py-1.5 bg-surface border border-bdr rounded-lg text-xs font-semibold text-t2 shrink-0 cursor-pointer">
                <i class="fas fa-map-marker-alt text-ok text-[10px]"></i>
                <select class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer">
                    <option>CALABARZON</option>
                    <option>Laguna</option>
                    <option>Batangas</option>
                    <option>Cavite</option>
                    <option>Rizal</option>
                    <option>Quezon</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select>
                    <option>All Job Types</option>
                    <option>Caregiver</option>
                    <option>Dom. Worker</option>
                    <option>Engineer</option>
                    <option>Nurse</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select>
                    <option>All Destinations</option>
                    <option>Hong Kong</option>
                    <option>Singapore</option>
                    <option>UAE</option>
                    <option>Qatar</option>
                    <option>Saudi Arabia</option>
                </select>
            </label>
        </div>
    </div>

    <!-- 
     MAIN  Z-PATTERN LAYOUT
 -->
    <div class="main">

        <!--  Z ROW 1: KPI Cards  left  right  -->
        <div class="z-stats">
            <div class="kpi-card c-blue fade-in d1">
                <div class="kpi-top">
                    <div class="kpi-label">Total Apps</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-folder-open"></i></div>
                </div>
                <div class="kpi-value c-blue">1,847</div>
                <div class="kpi-sub"><b> 12%</b> vs last mo.</div>
            </div>

            <div class="kpi-card c-amber fade-in d2">
                <div class="kpi-top">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-icon c-amber"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="kpi-value c-amber">264</div>
                <div class="kpi-sub" style="color:var(--amber)"> Needs attention</div>
            </div>

            <div class="kpi-card c-indigo fade-in d3">
                <div class="kpi-top">
                    <div class="kpi-label">Under Eval.</div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-value c-indigo">392</div>
                <div class="kpi-sub">Avg. 4.2 days</div>
            </div>

            <div class="kpi-card c-green fade-in d4">
                <div class="kpi-top">
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-value c-green">187</div>
                <div class="kpi-sub"><b> 8%</b> vs last mo.</div>
            </div>
        </div>

        <!--  Z ROW 2: Monthly chart + Right panel (diagonal)  -->
        <div class="z-mid fade-in d4">

            <!-- Monthly Volume Chart -->
            <div class="card">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt)"></span>
                        Monthly Application Volume
                    </div>
                    <a href="applications.php" class="card-link">All <i class="fas fa-arrow-right"
                            style="font-size:9px"></i></a>
                </div>
                <canvas id="chartMonthly"></canvas>
            </div>

            <!-- Right column: Status + Job Orders -->
            <div style="display:flex;flex-direction:column;gap:12px">

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            Status Breakdown
                        </div>
                    </div>
                    <div id="status-list"></div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--gold)"></span>
                            Active Job Orders
                        </div>
                        <a href="job-orders.php" class="card-link">RSF <i class="fas fa-arrow-right"
                                style="font-size:9px"></i></a>
                    </div>

                    <div class="job-item">
                        <div class="job-head">
                            <div>
                                <div class="job-name">RSF-2026-045</div>
                                <div class="job-sub">Caregiver - Hong Kong</div>
                            </div>
                            <div class="job-count" style="color:var(--green)">47<span
                                    style="color:var(--text3);font-weight:500;font-size:10px">/60</span></div>
                        </div>
                        <div class="job-track">
                            <div class="job-fill" style="width:78%;background:linear-gradient(90deg,#059669,#34d399)">
                            </div>
                        </div>
                    </div>
                    <div class="job-item">
                        <div class="job-head">
                            <div>
                                <div class="job-name">RSF-2026-038</div>
                                <div class="job-sub">Nurse - Singapore</div>
                            </div>
                            <div class="job-count" style="color:var(--blue-lt)">22<span
                                    style="color:var(--text3);font-weight:500;font-size:10px">/40</span></div>
                        </div>
                        <div class="job-track">
                            <div class="job-fill" style="width:55%;background:linear-gradient(90deg,#1d4ed8,#60a5fa)">
                            </div>
                        </div>
                    </div>
                    <div class="job-item">
                        <div class="job-head">
                            <div>
                                <div class="job-name">RSF-2026-031</div>
                                <div class="job-sub">Dom. Worker - UAE</div>
                            </div>
                            <div class="job-count" style="color:var(--amber)">33<span
                                    style="color:var(--text3);font-weight:500;font-size:10px">/50</span></div>
                        </div>
                        <div class="job-track">
                            <div class="job-fill" style="width:66%;background:linear-gradient(90deg,#d97706,#fbbf24)">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!--  Z ROW 3: Pie (left) + Province bar (right)  -->
        <div class="z-bottom fade-in d5">

            <div class="card">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--amber)"></span>
                        Destination Countries
                    </div>
                </div>
                <div class="pie-wrap">
                    <div class="pie-canvas-wrap">
                        <canvas id="chartPie" width="160" height="160"></canvas>
                    </div>
                    <div id="pie-legend" style="flex:1"></div>
                </div>
            </div>

        </div>

    </div><!-- /main -->


    <!--    
     FLOATING BOTTOM DOCK
 -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="dashboard.php" class="dk active" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <span class="dk-badge">12</span>
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Apps</span>
                <div class="flyout" id="appsMenu">
                    <div class="flyout-arr"></div>
                    <a href="applications.php" class="fi"><i class="fas fa-list" style="color:#60a5fa"></i>All
                        Applications</a>
                    <a href="applications.php?status=pending" class="fi"><i class="fas fa-clock"
                            style="color:#fbbf24"></i>Pending</a>
                    <a href="applications.php?status=unassign" class="fi"><i class="fas fa-user-slash"
                            style="color:#f87171"></i>Unassign</a>
                    <a href="applications.php?status=assign" class="fi"><i class="fas fa-user-check"
                            style="color:#60a5fa"></i>Assign</a>
                    <a href="applications.php?status=under_evaluation" class="fi"><i class="fas fa-search"
                            style="color:#a78bfa"></i>Under Evaluation</a>
                    <a href="applications.php?status=for_compliance" class="fi"><i class="fas fa-triangle-exclamation"
                            style="color:#fb923c"></i>For Compliance</a>
                    <a href="applications.php?status=approved" class="fi"><i class="fas fa-check-circle"
                            style="color:#34d399"></i>Approved</a>
                    <a href="applications.php?status=disapproved" class="fi"><i class="fas fa-times-circle"
                            style="color:#f87171"></i>Disapproved</a>
                </div>
            </div>

            <div class="dk-div"></div>

            <a href="user.php" class="dk" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
            </a>

            <a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>

            <a href="job-orders.php" class="dk" data-tip="Job Orders">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">RSF</span>
            </a>

            <a href="job.php" class="dk" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="analytics.php" class="dk">
                <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i><span class="dk-lbl"">Analytics</span></a>

            <div class=" dk-div">
    </div>

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


    <script>
        /*  Live date  */
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        /*  Status list  */
        [{
                l: 'Pending',
                c: 120,
                color: '#f59e0b'
            },
            {
                l: 'Unassigned',
                c: 144,
                color: '#ef4444'
            },
            {
                l: 'Assigned',
                c: 215,
                color: '#3b82f6'
            },
            {
                l: 'Under Eval.',
                c: 392,
                color: '#6366f1'
            },
            {
                l: 'Compliance',
                c: 88,
                color: '#f97316'
            },
            {
                l: 'Approved',
                c: 187,
                color: '#10b981'
            },
            {
                l: 'Disapproved',
                c: 42,
                color: '#dc2626'
            },
        ].forEach(s => {
            const pct = Math.round(s.c / 392 * 100);
            document.getElementById('status-list').innerHTML += `
  <div class="s-row">
    <span class="s-dot" style="background:${s.color}"></span>
    <span class="s-name">${s.l}</span>
    <span class="s-num" style="color:${s.color}">${s.c}</span>
    <div class="s-bar-wrap"><div class="s-bar-fill" style="width:${pct}%;background:${s.color}"></div></div>
  </div>`;
        });

        /*  Monthly bar chart  */
        new Chart(document.getElementById('chartMonthly'), {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                        label: 'Applications',
                        data: [312, 289, 347, 290, 310, 335, 280, 300, 320, 345, 365, 390],
                        backgroundColor: 'rgba(29,78,216,.12)',
                        borderColor: '#1d4ed8',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false
                    },
                    {
                        label: 'Approved',
                        data: [140, 120, 187, 130, 145, 160, 110, 135, 150, 165, 180, 200],
                        backgroundColor: 'rgba(5,150,105,.12)',
                        borderColor: '#059669',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                family: 'DM Sans',
                                size: 11
                            },
                            boxWidth: 10,
                            padding: 12
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'DM Sans',
                                size: 10
                            },
                            maxRotation: 0
                        }
                    },
                    y: {
                        grid: {
                            color: '#f1f5f9'
                        },
                        ticks: {
                            font: {
                                family: 'DM Sans',
                                size: 10
                            }
                        }
                    }
                }
            }
        });

        /*  Pie / Doughnut  */
        const pc = ['#1d4ed8', '#059669', '#d97706', '#6366f1', '#dc2626', '#94a3b8'];
        const pl = ['Hong Kong', 'Singapore', 'UAE', 'Saudi Arabia', 'Qatar', 'Others'];
        const pd = [32, 18, 22, 14, 8, 6];

        new Chart(document.getElementById('chartPie'), {
            type: 'doughnut',
            data: {
                labels: pl,
                datasets: [{
                    data: pd,
                    backgroundColor: pc,
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverOffset: 5
                }]
            },
            options: {
                cutout: '62%',
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        pl.forEach((l, i) => {
            document.getElementById('pie-legend').innerHTML += `
  <div class="p-row">
    <span class="p-sw" style="background:${pc[i]}"></span>
    <span class="p-lbl">${l}</span>
    <span class="p-pct">${pd[i]}%</span>
  </div>`;
        });

        /*  Province bar  */
        new Chart(document.getElementById('chartProvince'), {
            type: 'bar',
            data: {
                labels: ['Laguna', 'Batangas', 'Cavite', 'Rizal', 'Quezon', 'Lucena'],
                datasets: [{
                    label: 'Applications',
                    data: [498, 371, 312, 285, 214, 167],
                    backgroundColor: [
                        'rgba(79,70,229,.85)', 'rgba(79,70,229,.72)', 'rgba(79,70,229,.60)',
                        'rgba(79,70,229,.48)', 'rgba(79,70,229,.36)', 'rgba(79,70,229,.24)'
                    ],
                    borderColor: '#6366f1',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'DM Sans',
                                size: 10
                            },
                            maxRotation: 30
                        }
                    },
                    y: {
                        grid: {
                            color: '#f1f5f9'
                        },
                        ticks: {
                            font: {
                                family: 'DM Sans',
                                size: 10
                            }
                        }
                    }
                }
            }
        });

        /*  Dock flyout toggle  */
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
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>