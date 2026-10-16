<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['user_name'] ?? 'Administrator');
$staff_msg = '';
$staff_error = '';

// ==========================================
// 1. HANDLE STAFF REGISTRATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_staff') {
    $fullName    = trim($_POST['fullName'] ?? '');
    $identifier  = trim($_POST['identifier'] ?? '');
    $phoneNumber = trim($_POST['phoneNumber'] ?? '');
    $role        = $_POST['role'] ?? 'doctor';
    $department  = trim($_POST['department'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirmPassword'] ?? '';

    if (empty($fullName) || empty($identifier) || empty($phoneNumber) || empty($password)) {
        $staff_error = "All basic fields are required.";
    } elseif ($role === 'doctor' && empty($department)) {
        $staff_error = "Medical Department is required for Doctor accounts.";
    } elseif ($password !== $confirmPass) {
        $staff_error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $staff_error = "Password must be at least 6 characters.";
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE identifier = :identifier LIMIT 1");
        $checkStmt->execute([':identifier' => $identifier]);

        if ($checkStmt->fetch()) {
            $staff_error = "Email or Username is already registered.";
        } else {
            $hashedPass = password_hash($password, PASSWORD_BCRYPT);
            $finalDept = ($role === 'doctor' || !empty($department)) ? $department : null;

            $insertStmt = $pdo->prepare("
                INSERT INTO users (full_name, identifier, phone_number, password_hash, role, department)
                VALUES (:full_name, :identifier, :phone_number, :password_hash, :role, :department)
            ");
            
            $created = $insertStmt->execute([
                ':full_name'     => $fullName,
                ':identifier'   => $identifier,
                ':phone_number'  => $phoneNumber,
                ':password_hash' => $hashedPass,
                ':role'          => $role,
                ':department'    => $finalDept
            ]);

            if ($created) {
                $roleLabel = ucfirst($role);
                $deptLabel = !empty($finalDept) ? " ($finalDept)" : "";
                $staff_msg = "$roleLabel account for '$fullName'$deptLabel created successfully!";
            } else {
                $staff_error = "Failed to create account. Please try again.";
            }
        }
    }
}

// Add this logic if you want an Admin to be able to Reset/Reject a payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_payment') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    // Reset back to Unpaid so the patient has to re-submit
    $pdo->prepare("UPDATE billings SET payment_status = 'Unpaid', receipt_path = NULL WHERE id = :id")->execute([':id' => $bill_id]);
    $staff_msg = "Payment rejected. Patient will be notified to re-submit.";
}

// ==========================================
// 2. HANDLE ADMIN CONFIRMING PAYMENT
// ==========================================
// ==========================================
// 2. HANDLE ADMIN CONFIRMING PAYMENT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_payment') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    
    // Admin sets it to Paid
    $payStmt = $pdo->prepare("UPDATE billings SET payment_status = 'Paid' WHERE id = :id");
    $payStmt->execute([':id' => $bill_id]);

    $bInfo = $pdo->prepare("SELECT user_id, invoice_number FROM billings WHERE id = :id");
    $bInfo->execute([':id' => $bill_id]);
    $billRow = $bInfo->fetch(PDO::FETCH_ASSOC);

    if ($billRow) {
        $notif = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (:uid, 'Payment Verified & Confirmed', :msg, 'confirmed')
        ");
        $notif->execute([
            ':uid' => $billRow['user_id'],
            ':msg' => "Your payment for invoice [{$billRow['invoice_number']}] has been verified and marked as Paid by Administration."
        ]);
    }
    
    $staff_msg = "Payment verified successfully!";
}

// ==========================================
// 3. FETCH PENDING PAYMENTS (RECEIPTS & ONSITE)
// ==========================================
// Debugging check: Uncomment this line to see what the database holds
// var_dump($pdo->query("SELECT payment_status FROM billings")->fetchAll());
// ==========================================
// 3. FETCH PENDING PAYMENTS
// ==========================================
// ==========================================
// 3. FETCH PENDING PAYMENTS
// ==========================================
// We use REGEXP to catch any status containing the word "Pending"
$pendingBillsStmt = $pdo->query("
    SELECT b.*, u.full_name AS patient_name 
    FROM billings b
    JOIN users u ON b.user_id = u.id
    WHERE b.payment_status REGEXP 'Pending'
    ORDER BY b.created_at ASC
");
$pendingPayments = $pendingBillsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Admin Notifications
$notifStmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = :aid 
    ORDER BY created_at DESC LIMIT 6
");
$notifStmt->execute([':aid' => $admin_id]);
$adminNotifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCount = count(array_filter($adminNotifications, fn($n) => $n['is_read'] == 0));

// Fetch Doctor Audits & Incidents
$docAuditStmt = $pdo->query("
    SELECT id, full_name, identifier, department, unavailable_count 
    FROM users 
    WHERE role = 'doctor' 
    ORDER BY unavailable_count DESC, full_name ASC
");
$doctorAudits = $docAuditStmt->fetchAll(PDO::FETCH_ASSOC);
$totalDoctorStrikes = array_sum(array_column($doctorAudits, 'unavailable_count'));

$logStmt = $pdo->query("
    SELECT l.*, 
           d.full_name AS doctor_name, 
           rd.full_name AS reassigned_doctor_name,
           a.booking_code
    FROM doctor_unavailability_logs l
    JOIN users d ON l.doctor_id = d.id
    LEFT JOIN users rd ON l.reassigned_doctor_id = rd.id
    JOIN appointments a ON l.appointment_id = a.id
    ORDER BY l.created_at DESC LIMIT 10
");
$incidentLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Admin Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin.css">

    <style>
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md, 8px);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        .alert--error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert--success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        
        .staff-form { display: flex; flex-direction: column; gap: 1rem; }
        .staff-form .form-group { display: flex; flex-direction: column; gap: 0.35rem; }
        .staff-form label { font-size: 0.85rem; font-weight: 600; color: var(--text-dark); }
        .staff-form input, .staff-form select {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-subtle);
            font-family: inherit;
            font-size: 0.9rem;
        }

        .booking-code-badge {
            display: inline-block;
            background: #f8fafc;
            color: #0f172a;
            border: 1px dashed #64748b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            font-size: 0.775rem;
            padding: 0.15rem 0.55rem;
            border-radius: 4px;
            letter-spacing: 0.05em;
        }

        .notif-wrapper { position: relative; display: inline-block; }
        .notif-btn {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            position: relative;
            transition: var(--transition);
        }
        .notif-btn:hover { background-color: #f8fafc; border-color: var(--primary); color: var(--primary); }
        .notif-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background-color: #ef4444;
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }
        .notif-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            width: 350px;
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            box-shadow: 0 16px 32px -8px rgba(15, 23, 42, 0.16);
            z-index: 1000;
            display: none;
            flex-direction: column;
            overflow: hidden;
        }
        .notif-dropdown.active { display: flex; }
        .notif-header {
            padding: 0.85rem 1.1rem;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .notif-header h4 { font-size: 0.9rem; color: var(--text-dark); font-weight: 700; margin: 0; }
        .notif-list { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; }
        .notif-item { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 0.75rem; text-decoration: none; }
        .notif-item.unread { background-color: #fef2f2; }
        .notif-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #fee2e2;
            color: #dc2626;
        }
        .notif-content h5 { font-size: 0.85rem; color: var(--text-dark); margin-bottom: 0.2rem; font-weight: 600; }
        .notif-content p { font-size: 0.775rem; color: var(--text-body); line-height: 1.4; margin-bottom: 0.25rem; }
        .notif-content small { font-size: 0.7rem; color: var(--text-muted); }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="navbar__brand">
            <img src="../images/logo.png" alt="Hosplinic Logo" class="navbar__logo">
            <h1 class="navbar__title">Hosplinic</h1>
            <span class="admin-badge">Master Admin</span>
        </div>
        
        <div class="navbar__actions" style="display: flex; align-items: center; gap: 1rem;">
            
            <!-- ADMIN NOTIFICATION BELL -->
            <div class="notif-wrapper">
                <button type="button" class="notif-btn" id="adminNotifBtn" aria-label="Notifications">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </button>

                <div class="notif-dropdown" id="adminNotifDropdown">
                    <div class="notif-header">
                        <h4>Admin Incident Alerts</h4>
                        <span style="font-size: 0.75rem; color: #dc2626; font-weight: 600;"><?= $unreadCount ?> unread</span>
                    </div>

                    <div class="notif-list">
                        <?php if (count($adminNotifications) > 0): ?>
                            <?php foreach ($adminNotifications as $aNotif): ?>
                                <div class="notif-item <?= $aNotif['is_read'] == 0 ? 'unread' : '' ?>">
                                    <div class="notif-icon">
                                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                    </div>
                                    <div class="notif-content">
                                        <h5><?= htmlspecialchars($aNotif['title']) ?></h5>
                                        <p><?= htmlspecialchars($aNotif['message']) ?></p>
                                        <small><?= date('M d, h:i A', strtotime($aNotif['created_at'])) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                                No incident alerts at this time.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <a href="../index.php" class="btn btn--outline">Main Site</a>
            <a href="../logout.php" class="btn btn--primary">Logout</a>
        </div>
    </header>

    <main class="dashboard" style="max-width: 1200px;">
        <div class="dashboard__intro" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; text-align:left;">
            <div>
                <h2>Administrative Operations</h2>
                <p>Manage facility inventory, monitor physician incident reports, and register medical staff.</p>
            </div>

            <button class="btn btn--primary" id="openStaffModal">+ Register Doctor / Admin</button>
        </div>

        <?php if (!empty($staff_msg)): ?>
            <div class="alert alert--success"><?= htmlspecialchars($staff_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($staff_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($staff_error) ?></div>
        <?php endif; ?>

        <!-- 4 CARDS DASHBOARD GRID -->
        <div class="dashboard__grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">

            <!-- Card 1: Inventory -->
            <div class="card" id="openInventoryModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <h3 class="card__title">Inventory Management</h3>
                    <p class="card__desc">Monitor medical supplies, view critical stock thresholds, and manage pharmacy reorders.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">1,240 Total In Stock</span>
                        <span class="pill pill--warn">4 Low Stock Alerts</span>
                    </div>
                </div>
                <span class="card__link">Manage Inventory &rarr;</span>
            </div>

            <!-- Card 2: Reporting & Analytics -->
            <div class="card" id="openAnalyticsModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Reporting & Analytics</h3>
                    <p class="card__desc">Track weekly patient volume, review operational efficiency, and export clinic performance.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">+14.2% Monthly Growth</span>
                        <span class="pill pill--neutral">96% Satisfaction</span>
                    </div>
                </div>
                <span class="card__link">View Analytics &rarr;</span>
            </div>

            <!-- Card 3: Doctor Unavailability & Attendance -->
            <div class="card" id="openUnavailReportModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon" style="background:#fee2e2; color:#dc2626;">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Doctor Incident Reports</h3>
                    <p class="card__desc">Audit physician unavailability strikes, view patient pass-off logs, and review stated reasons.</p>
                    
                    <div class="pill-group">
                        <span class="pill <?= $totalDoctorStrikes > 0 ? 'pill--danger' : 'pill--ok' ?>">
                            <?= $totalDoctorStrikes ?> Total Incident(s)
                        </span>
                        <span class="pill pill--neutral"><?= count($doctorAudits) ?> Active Doctors</span>
                    </div>
                </div>
                <span class="card__link">View Doctor Audits &rarr;</span>
            </div>

            <!-- Card 4: Payment Verification Queue -->
            <div class="card" id="openPaymentVerifyModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon" style="background: #e0f2fe; color: #0284c7;">💰</div>
                    <h3 class="card__title">Payment Verifications</h3>
                    <p class="card__desc">Review online payment receipts and on-site cash payments pending approval.</p>
                    <div class="pill-group">
                        <span class="pill pill--warn"><?= count($pendingPayments) ?> Pending Verification(s)</span>
                    </div>
                </div>
                <span class="card__link">Verify Payments &rarr;</span>
            </div>

        </div>
    </main>

    <!-- MODAL: DOCTOR AUDIT -->
    <div class="modal-overlay" id="unavailReportModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Doctor Unavailability & Attendance Audit</h3>
                <button class="modal__close" data-close="unavailReportModal">&times;</button>
            </div>
            <div class="modal__body">
                <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; color: var(--text-dark);">Physician Incident Counters</h4>
                <div class="table-wrapper" style="margin-bottom: 1.75rem;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Doctor Name</th>
                                <th>Department</th>
                                <th>Username</th>
                                <th>Incident Counter</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($doctorAudits) > 0): ?>
                                <?php foreach ($doctorAudits as $doc): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($doc['full_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($doc['department'] ?? 'General') ?></td>
                                        <td><?= htmlspecialchars($doc['identifier']) ?></td>
                                        <td><span style="font-weight: 700; color: <?= $doc['unavailable_count'] > 0 ? '#b91c1c' : '#15803d' ?>;"><?= $doc['unavailable_count'] ?> Incident(s)</span></td>
                                        <td>
                                            <?php if ($doc['unavailable_count'] >= 3): ?>
                                                <span class="pill pill--danger">Review Needed</span>
                                            <?php elseif ($doc['unavailable_count'] > 0): ?>
                                                <span class="pill pill--warn">Flagged</span>
                                            <?php else: ?>
                                                <span class="pill pill--ok">Good Standing</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">No doctor accounts registered.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; color: var(--text-dark);">Recent Reassignment Audit Logs</h4>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Original Doctor</th>
                                <th>Booking Code</th>
                                <th>Stated Reason</th>
                                <th>Transferred To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($incidentLogs) > 0): ?>
                                <?php foreach ($incidentLogs as $log): ?>
                                    <tr>
                                        <td><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                                        <td><strong style="color: #b91c1c;"><?= htmlspecialchars($log['doctor_name']) ?></strong></td>
                                        <td><span class="booking-code-badge"><?= htmlspecialchars($log['booking_code']) ?></span></td>
                                        <td><em><?= htmlspecialchars($log['reason'] ?? 'Not specified') ?></em></td>
                                        <td><strong style="color: #15803d;"><?= htmlspecialchars($log['reassigned_doctor_name'] ?? 'On-Duty Pool') ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding: 1.5rem 0;">No incident logs recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="unavailReportModal">Close Audit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: STAFF REGISTRATION -->
    <div class="modal-overlay" id="staffModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Register Medical Staff</h3>
                <button class="modal__close" data-close="staffModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="admin.php" method="POST" class="staff-form">
                    <input type="hidden" name="action" value="register_staff">
                    <div class="form-group">
                        <label for="role">Account Role</label>
                        <select name="role" id="role" required>
                            <option value="doctor">Doctor (Physician)</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="form-group" id="deptGroup">
                        <label for="department">Medical Department <span id="deptRequiredStar" style="color: #dc2626;">*</span></label>
                        <select name="department" id="department" required>
                            <option value="">-- Choose Department --</option>
                            <option value="Cardiology">Cardiology</option>
                            <option value="Pediatrics">Pediatrics</option>
                            <option value="Dermatology">Dermatology</option>
                            <option value="General Medicine">General Medicine</option>
                        </select>
                        <small id="deptOptionalNotice" style="font-size: 0.75rem; color: var(--text-muted); display: none;">(Optional for Administrator accounts)</small>
                    </div>
                    <div class="form-group">
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="fullName" placeholder="e.g. Dr. Michael Chen" required>
                    </div>
                    <div class="form-group">
                        <label for="identifier">Username / Email</label>
                        <input type="text" id="identifier" name="identifier" placeholder="e.g. dr.chen@hosplinic" required>
                    </div>
                    <div class="form-group">
                        <label for="phoneNumber">Phone Number</label>
                        <input type="tel" id="phoneNumber" name="phoneNumber" placeholder="e.g. +1 555-019-2834" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                    </div>
                    <div class="modal-footer-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Create Staff Account</button>
                        <button type="button" class="btn btn--outline" data-close="staffModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: INVENTORY -->
    <div class="modal-overlay" id="inventoryModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Medical & Pharmacy Stock</h3>
                <button class="modal__close" data-close="inventoryModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Item Name</th><th>Category</th><th>Quantity</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Amoxicillin 500mg</strong></td><td>Antibiotic</td><td>12 boxes</td><td><span class="pill pill--danger">Critical Low</span></td></tr>
                            <tr><td><strong>Surgical Gloves (M)</strong></td><td>PPE</td><td>45 boxes</td><td><span class="pill pill--ok">Optimal</span></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer-actions"><button class="btn btn--outline" data-close="inventoryModal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- MODAL: ANALYTICS -->
    <div class="modal-overlay" id="analyticsModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Clinic Performance & Trends</h3>
                <button class="modal__close" data-close="analyticsModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="analytics-stats">
                    <div class="stat-item"><small>Avg Wait Time</small><strong>14 mins</strong></div>
                    <div class="stat-item"><small>Monthly Visits</small><strong>620</strong></div>
                    <div class="stat-item"><small>Satisfaction</small><strong>96.4%</strong></div>
                </div>
                <div class="modal-footer-actions"><button class="btn btn--outline" data-close="analyticsModal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- MODAL: PAYMENT VERIFICATION -->
    <div class="modal-overlay" id="paymentVerifyModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Pending Payment Verifications</h3>
                <button class="modal__close" data-close="paymentVerifyModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="list-container">
                    <?php if (count($pendingPayments) > 0): ?>
                        <?php foreach ($pendingPayments as $pay): ?>
                            <div class="list-item" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid var(--border-subtle); flex-wrap: wrap; gap: 1rem;">
                                <div>
                                    <strong><?= htmlspecialchars($pay['patient_name']) ?></strong> &bull; <?= htmlspecialchars($pay['invoice_number']) ?>
                                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0.2rem 0;">
                                        Method: <strong><?= htmlspecialchars($pay['payment_method'] ?? 'Online') ?></strong> | Amount: <strong>₱<?= number_format($pay['total_amount'], 2) ?></strong>
                                    </p>
                                    <?php if (!empty($pay['receipt_path'])): ?>
                                        <a href="../<?= htmlspecialchars($pay['receipt_path']) ?>" target="_blank" class="btn btn--outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-top: 0.3rem;">
                                            &#128206; View Attached Receipt (<?= htmlspecialchars($pay['receipt_name']) ?>)
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: #b45309;">(On-site cash/check payment pending at clinic desk)</span>
                                    <?php endif; ?>
                                </div>
                                <form action="admin.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="verify_payment">
                                    <input type="hidden" name="bill_id" value="<?= $pay['id'] ?>">
                                    <button type="submit" class="btn btn--primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                        &#10003; Confirm Payment
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 2rem 0;">No pending payments to verify.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="paymentVerifyModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Admin Portal</p>
    </footer>

    <script>
        const adminNotifBtn = document.getElementById('adminNotifBtn');
        const adminNotifDropdown = document.getElementById('adminNotifDropdown');

        if (adminNotifBtn && adminNotifDropdown) {
            adminNotifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                adminNotifDropdown.classList.toggle('active');
            });

            document.addEventListener('click', (e) => {
                if (!adminNotifDropdown.contains(e.target) && e.target !== adminNotifBtn) {
                    adminNotifDropdown.classList.remove('active');
                }
            });
        }

        const roleSelect = document.getElementById('role');
        const deptSelect = document.getElementById('department');
        const deptRequiredStar = document.getElementById('deptRequiredStar');
        const deptOptionalNotice = document.getElementById('deptOptionalNotice');

        function toggleDepartmentRequirement() {
            if (roleSelect.value === 'doctor') {
                deptSelect.required = true;
                deptRequiredStar.style.display = 'inline';
                deptOptionalNotice.style.display = 'none';
            } else {
                deptSelect.required = false;
                deptRequiredStar.style.display = 'none';
                deptOptionalNotice.style.display = 'block';
            }
        }

        roleSelect.addEventListener('change', toggleDepartmentRequirement);
        toggleDepartmentRequirement();

        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
        }

        document.getElementById('openStaffModal').addEventListener('click', () => openModal('staffModal'));
        document.getElementById('openInventoryModal').addEventListener('click', () => openModal('inventoryModal'));
        document.getElementById('openAnalyticsModal').addEventListener('click', () => openModal('analyticsModal'));
        document.getElementById('openUnavailReportModal').addEventListener('click', () => openModal('unavailReportModal'));
        document.getElementById('openPaymentVerifyModal').addEventListener('click', () => openModal('paymentVerifyModal'));

        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => closeModal(modal.id));
            }
        });
    </script>

    <script src="../scripts/session_timeout.js"></script>
</body>
</html>