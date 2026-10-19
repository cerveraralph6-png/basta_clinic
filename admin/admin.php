<?php
session_start();
require_once '../db.php';


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'adjust_stock') {
    header('Content-Type: application/json');
    $item_id = intval($_POST['item_id'] ?? 0);
    $adj     = intval($_POST['adjustment'] ?? 0);

    $stmt = $pdo->prepare("SELECT quantity, min_threshold, item_name FROM inventory WHERE id = :id");
    $stmt->execute([':id' => $item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        $newQty = max(0, intval($item['quantity']) + $adj);
        $upd = $pdo->prepare("UPDATE inventory SET quantity = :q WHERE id = :id");
        $upd->execute([':q' => $newQty, ':id' => $item_id]);

        $isLow = ($newQty <= intval($item['min_threshold']));
        echo json_encode([
            'success' => true,
            'new_qty' => $newQty,
            'is_low'  => $isLow,
            'min_th'  => $item['min_threshold']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Item not found']);
    }
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = htmlspecialchars($_SESSION['user_name'] ?? 'Administrator');
$staff_msg = '';
$staff_error = '';


function logAdminAudit($pdo, $adminId, $action, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:uid, :act, :det, :ip)");
    $stmt->execute([':uid' => $adminId, ':act' => $action, ':det' => $details, ':ip' => $ip]);
}




if (isset($_GET['export_reports']) && $_GET['export_reports'] == '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Clinic_Performance_Report_' . date('Y-m-d') . '.csv');
    

    $repStmt = $pdo->query("
        SELECT 
            u.full_name, 
            u.department,
            COUNT(a.id) AS total_consultations,
            u.unavailable_count,
            COALESCE(SUM(b.total_amount), 0) * 0.70 AS doctor_earnings
        FROM users u
        LEFT JOIN appointments a ON u.id = a.doctor_id AND a.status = 'Confirmed'
        LEFT JOIN billings b ON a.id = b.appointment_id AND b.payment_status = 'Paid'
        WHERE u.role = 'doctor'
        GROUP BY u.id
        ORDER BY doctor_earnings DESC
    ");

    while ($row = $repStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['full_name'],
            $row['department'] ?? 'General',
            $row['total_consultations'],
            $row['unavailable_count'],
            number_format($row['doctor_earnings'], 2)
        ]);
    }
    fclose($output);
    exit;
}




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
                logAdminAudit($pdo, $admin_id, 'STAFF_REGISTER', "Created $role account for $fullName ($identifier)");
                $roleLabel = ucfirst($role);
                $deptLabel = !empty($finalDept) ? " ($finalDept)" : "";
                $staff_msg = "$roleLabel account for '$fullName'$deptLabel created successfully!";
            } else {
                $staff_error = "Failed to create account. Please try again.";
            }
        }
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_inventory_item') {
    $code      = trim($_POST['item_code'] ?? '');
    $name      = trim($_POST['item_name'] ?? '');
    $category  = trim($_POST['category'] ?? 'Consumable');
    $qty       = intval($_POST['quantity'] ?? 0);
    $min_th    = intval($_POST['min_threshold'] ?? 10);
    $unit_pr   = floatval($_POST['unit_price'] ?? 0.00);

    if (empty($code) || empty($name)) {
        $staff_error = "Item code and name are required.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory (item_code, item_name, category, quantity, min_threshold, unit_price)
                VALUES (:code, :name, :cat, :qty, :min_th, :price)
            ");
            $stmt->execute([
                ':code'   => $code,
                ':name'   => $name,
                ':cat'    => $category,
                ':qty'    => $qty,
                ':min_th' => $min_th,
                ':price'  => $unit_pr
            ]);
            logAdminAudit($pdo, $admin_id, 'INVENTORY_ADD', "Added stock item $code ($name)");
            $staff_msg = "Item [$code] '$name' added to inventory successfully.";
        } catch (PDOException $e) {
            $staff_error = "Error adding item: Item Code ($code) might already exist.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    $item_id   = intval($_POST['item_id'] ?? 0);
    $adjustment = intval($_POST['adjustment'] ?? 0);

    $chk = $pdo->prepare("SELECT item_name, quantity FROM inventory WHERE id = :id");
    $chk->execute([':id' => $item_id]);
    $curr = $chk->fetch(PDO::FETCH_ASSOC);

    if ($curr) {
        $newQty = max(0, $curr['quantity'] + $adjustment);
        $upd = $pdo->prepare("UPDATE inventory SET quantity = :q WHERE id = :id");
        $upd->execute([':q' => $newQty, ':id' => $item_id]);
        logAdminAudit($pdo, $admin_id, 'INVENTORY_ADJUST', "Adjusted {$curr['item_name']} by {$adjustment} (Now: {$newQty})");
        $staff_msg = "Stock for '{$curr['item_name']}' updated to $newQty.";
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_payment') {
    $bill_id     = intval($_POST['bill_id'] ?? 0);
    $receipt_no  = trim($_POST['official_receipt_no'] ?? '');
    $pay_method  = trim($_POST['payment_method'] ?? 'Cash');

    if (empty($receipt_no)) {
        $staff_error = "Please provide an Official Clinic Receipt Number (OR Number) to credit this payment.";
    } else {
        $payStmt = $pdo->prepare("
            UPDATE billings 
            SET payment_status = 'Paid',
                payment_method = :method,
                official_receipt_no = :receipt_no
            WHERE id = :id
        ");
        $payStmt->execute([
            ':method'     => $pay_method,
            ':receipt_no' => $receipt_no,
            ':id'         => $bill_id
        ]);

        $bInfo = $pdo->prepare("SELECT user_id, invoice_number, total_amount FROM billings WHERE id = :id");
        $bInfo->execute([':id' => $bill_id]);
        $billRow = $bInfo->fetch(PDO::FETCH_ASSOC);

        if ($billRow) {
            logAdminAudit($pdo, $admin_id, 'PAYMENT_CREDITED', "Credited ₱{$billRow['total_amount']} for invoice {$billRow['invoice_number']} (OR #: $receipt_no)");
            $notif = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:uid, 'Payment Credited', :msg, 'confirmed')
            ");
            $notif->execute([
                ':uid' => $billRow['user_id'],
                ':msg' => "Payment of ₱" . number_format($billRow['total_amount'], 2) . " for invoice [{$billRow['invoice_number']}] has been confirmed and credited by Administration. Official Receipt #: $receipt_no."
            ]);
        }
        
        $staff_msg = "Payment for invoice [{$billRow['invoice_number']}] credited successfully under OR #$receipt_no!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_payment') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    $pdo->prepare("UPDATE billings SET payment_status = 'Unpaid', receipt_path = NULL WHERE id = :id")->execute([':id' => $bill_id]);
    logAdminAudit($pdo, $admin_id, 'PAYMENT_REJECTED', "Rejected payment receipt for bill #$bill_id");
    $staff_msg = "Payment rejected. Patient will be notified to re-submit.";
}





$pendingBillsStmt = $pdo->query("
    SELECT b.*, u.full_name AS patient_name, u.phone_number 
    FROM billings b
    JOIN users u ON b.user_id = u.id
    WHERE b.payment_status != 'Paid'
    ORDER BY b.created_at DESC
");
$pendingPayments = $pendingBillsStmt->fetchAll(PDO::FETCH_ASSOC);


$invStmt = $pdo->query("SELECT * FROM inventory ORDER BY (quantity <= min_threshold) DESC, item_name ASC");
$inventoryList = $invStmt->fetchAll(PDO::FETCH_ASSOC);
$totalInventoryQty = array_sum(array_column($inventoryList, 'quantity'));
$lowStockItems = array_filter($inventoryList, fn($item) => $item['quantity'] <= $item['min_threshold']);


$revStmt = $pdo->query("SELECT SUM(total_amount) FROM billings WHERE payment_status = 'Paid'");
$totalRevenue = floatval($revStmt->fetchColumn() ?: 0.00);

$pendStmt = $pdo->query("SELECT SUM(total_amount) FROM billings WHERE payment_status REGEXP 'Pending'");
$pendingRevenue = floatval($pendStmt->fetchColumn() ?: 0.00);


$aptStatStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
    FROM appointments
");
$aptStats = $aptStatStmt->fetch(PDO::FETCH_ASSOC);


$docReportStmt = $pdo->query("
    SELECT 
        u.id, 
        u.full_name, 
        u.department,
        COUNT(a.id) AS total_consultations,
        u.unavailable_count,
        COALESCE(SUM(b.total_amount), 0) * 0.70 AS doctor_earnings
    FROM users u
    LEFT JOIN appointments a ON u.id = a.doctor_id AND a.status = 'Confirmed'
    LEFT JOIN billings b ON a.id = b.appointment_id AND b.payment_status = 'Paid'
    WHERE u.role = 'doctor'
    GROUP BY u.id
    ORDER BY doctor_earnings DESC
");
$doctorReports = $docReportStmt->fetchAll(PDO::FETCH_ASSOC);


$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :aid ORDER BY created_at DESC LIMIT 6");
$notifStmt->execute([':aid' => $admin_id]);
$adminNotifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCount = count(array_filter($adminNotifications, fn($n) => $n['is_read'] == 0));


$docAuditStmt = $pdo->query("SELECT id, full_name, identifier, department, unavailable_count FROM users WHERE role = 'doctor' ORDER BY unavailable_count DESC, full_name ASC");
$doctorAudits = $docAuditStmt->fetchAll(PDO::FETCH_ASSOC);
$totalDoctorStrikes = array_sum(array_column($doctorAudits, 'unavailable_count'));

$logStmt = $pdo->query("
    SELECT l.*, d.full_name AS doctor_name, rd.full_name AS reassigned_doctor_name, a.booking_code
    FROM doctor_unavailability_logs l
    JOIN users d ON l.doctor_id = d.id
    LEFT JOIN users rd ON l.reassigned_doctor_id = rd.id
    JOIN appointments a ON l.appointment_id = a.id
    ORDER BY l.created_at DESC LIMIT 10
");
$incidentLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);


$secAuditStmt = $pdo->query("
    SELECT a.*, u.full_name AS user_name 
    FROM audit_logs a 
    JOIN users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC LIMIT 15
");
$securityLogs = $secAuditStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Master Admin</title>

    <link rel="stylesheet" href="../styles/admin.css">

    <style>
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius-md, 8px); font-size: 0.875rem; margin-bottom: 1rem; }
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

       
        .btn-qty {
            padding: 0.2rem 0.5rem;
            font-size: 0.75rem;
            border: 1px solid var(--border-subtle);
            background: #ffffff;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-qty:hover { background: #f1f5f9; }

        .modal--wide {
            max-width: 950px;
            width: 95%;
        }

       
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md, 8px);
            margin-top: 1rem;
            background: #ffffff;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }

        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-subtle);
            white-space: nowrap;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
            white-space: nowrap;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background-color: #f8fafc;
        }

       
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            line-height: 1.2;
        }

        .btn-qty {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border: 1px solid var(--border-subtle);
            background: #ffffff;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .btn-qty:hover:not(:disabled) {
            background: #f1f5f9;
            border-color: var(--primary);
            color: var(--primary);
        }
        .btn-qty:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
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
                <h2>Administrative Operations &amp; Facility Oversight</h2>
                <p>Welcome, <strong><?= $admin_name ?></strong>. Track medical stock, financial reports, doctor performance, and system security.</p>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn btn--outline" id="openSecurityModal">&#128737; Security Logs</button>
                <button class="btn btn--primary" id="openStaffModal">+ Register Doctor / Admin</button>
            </div>
        </div>

        <?php if (!empty($staff_msg)): ?>
            <div class="alert alert--success"><?= htmlspecialchars($staff_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($staff_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($staff_error) ?></div>
        <?php endif; ?>

        <!-- 4 MAIN ACTION CARDS -->
        <div class="dashboard__grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">

            <!-- Card 1: Live Inventory Management -->
            <div class="card" id="openInventoryModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <h3 class="card__title">Inventory Management</h3>
                    <p class="card__desc">Tracks clinic supplies, medicines, and surgical stock with low-threshold warning alerts.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok"><?= $totalInventoryQty ?> Units In Stock</span>
                        <?php if (count($lowStockItems) > 0): ?>
                            <span class="pill pill--danger"><?= count($lowStockItems) ?> Low Stock Item(s)</span>
                        <?php else: ?>
                            <span class="pill pill--ok">All Supplies Optimal</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="card__link">Manage Clinic Inventory &rarr;</span>
            </div>

            <!-- Card 2: Reporting & Analytics -->
            <div class="card" id="openAnalyticsModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Reporting &amp; Analytics</h3>
                    <p class="card__desc">Generates financial summaries, patient visit metrics, and calculated staff earnings.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">₱<?= number_format($totalRevenue, 2) ?> Revenue</span>
                        <span class="pill pill--neutral"><?= $aptStats['total'] ?> Consultations</span>
                    </div>
                </div>
                <span class="card__link">View Performance Reports &rarr;</span>
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

    <!-- MODAL 1: REAL INVENTORY MANAGEMENT -->
    <!-- MODAL 1: REAL INVENTORY MANAGEMENT -->
    <div class="modal-overlay" id="inventoryModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Clinic Supplies &amp; Pharmacy Inventory</h3>
                <button class="modal__close" data-close="inventoryModal">&times;</button>
            </div>
            <div class="modal__body">
                
                <!-- Quick Add Form -->
                <div style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 1.1rem; border-radius: var(--radius-md); margin-bottom: 1.25rem;">
                    <h4 style="margin: 0 0 0.75rem 0; font-size: 0.9rem; color: var(--text-dark);">+ Add Stock or Medication</h4>
                    <form action="admin.php" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.65rem; align-items: flex-end;">
                        <input type="hidden" name="action" value="add_inventory_item">
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Code</label>
                            <input type="text" name="item_code" placeholder="e.g. MED-01" required style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Description</label>
                            <input type="text" name="item_name" placeholder="Item name" required style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Category</label>
                            <select name="category" style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                                <option value="Medication">Medication</option>
                                <option value="Consumable">Consumable</option>
                                <option value="Equipment">Equipment</option>
                                <option value="Surgical">Surgical</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Qty</label>
                            <input type="number" name="quantity" value="50" min="0" required style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Min Alert</label>
                            <input type="number" name="min_threshold" value="15" min="1" required style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; font-weight:600; display:block; margin-bottom:0.25rem;">Unit ₱</label>
                            <input type="number" step="0.01" name="unit_price" value="0.00" required style="width:100%; padding:0.45rem; font-size:0.85rem; border:1px solid var(--border-subtle); border-radius:4px;">
                        </div>
                        <div>
                            <button type="submit" class="btn btn--primary" style="width:100%; padding:0.5rem; font-size:0.85rem;">Save Item</button>
                        </div>
                    </form>
                </div>

                <!-- Clean, Scroll-Safe Inventory Table -->
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 15%;">Item Code</th>
                                <th style="width: 30%;">Item Name</th>
                                <th style="width: 15%;">Category</th>
                                <th style="width: 10%; text-align: center;">Stock</th>
                                <th style="width: 10%;">Unit Price</th>
                                <th style="width: 10%;">Status</th>
                                <th style="width: 10%; text-align: center;">Quick Adjust</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inventoryList) > 0): ?>
                                <?php foreach ($inventoryList as $item): ?>
                                    <tr id="inv-row-<?= $item['id'] ?>">
                                        <td><code class="booking-code-badge"><?= htmlspecialchars($item['item_code']) ?></code></td>
                                        <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($item['category']) ?></td>
                                        <td style="text-align: center;">
                                            <strong id="qty-val-<?= $item['id'] ?>" style="font-size: 0.95rem;"><?= $item['quantity'] ?></strong>
                                        </td>
                                        <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                                        <td id="status-cell-<?= $item['id'] ?>">
                                            <?php if ($item['quantity'] <= $item['min_threshold']): ?>
                                                <span class="pill pill--danger">&#9888; Low Stock (&le; <?= $item['min_threshold'] ?>)</span>
                                            <?php else: ?>
                                                <span class="pill pill--ok">&#10003; In Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: inline-flex; gap: 0.3rem;">
                                                <button type="button" class="btn-qty" onclick="adjustStockAjax(<?= $item['id'] ?>, 10)">+10</button>
                                                <button type="button" class="btn-qty" onclick="adjustStockAjax(<?= $item['id'] ?>, -1)">-1</button>
                                                <button type="button" class="btn-qty" onclick="adjustStockAjax(<?= $item['id'] ?>, -10)">-10</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:2rem 0;">No items recorded in inventory.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="modal-footer-actions" style="margin-top: 1.25rem;">
                    <button class="btn btn--outline" data-close="inventoryModal">Close Inventory</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: REPORTING & ANALYTICS -->
    <div class="modal-overlay" id="analyticsModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Clinic Reporting &amp; Financial Analytics</h3>
                <button class="modal__close" data-close="analyticsModal">&times;</button>
            </div>
            <div class="modal__body">
                
                <!-- Financial Cards -->
                <div class="analytics-stats" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
                    <div class="stat-item">
                        <small>Total Settled Revenue</small>
                        <strong style="color:#15803d;">₱<?= number_format($totalRevenue, 2) ?></strong>
                    </div>
                    <div class="stat-item">
                        <small>Pending Receivables</small>
                        <strong style="color:#b45309;">₱<?= number_format($pendingRevenue, 2) ?></strong>
                    </div>
                    <div class="stat-item">
                        <small>Confirmed Consults</small>
                        <strong><?= $aptStats['confirmed'] ?> / <?= $aptStats['total'] ?></strong>
                    </div>
                    <div class="stat-item">
                        <small>Cancellations</small>
                        <strong style="color:#b91c1c;"><?= $aptStats['cancelled'] ?></strong>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <h4 style="margin:0; font-size:0.95rem; color:var(--text-dark);">Physician Activity &amp; Earnings (70% Doctor Share)</h4>
                    <a href="admin.php?export_reports=1" class="btn btn--outline" style="font-size:0.8rem;">&#128190; Export to CSV</a>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Physician</th>
                                <th>Department</th>
                                <th>Confirmed Visits</th>
                                <th>Unavailability Strikes</th>
                                <th>Calculated Doctor Share (70%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($doctorReports as $doc): ?>
                                <tr>
                                    <td><strong>Dr. <?= htmlspecialchars($doc['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($doc['department'] ?? 'General Medicine') ?></td>
                                    <td><?= $doc['total_consultations'] ?></td>
                                    <td>
                                        <span class="pill <?= $doc['unavailable_count'] > 0 ? 'pill--danger' : 'pill--ok' ?>">
                                            <?= $doc['unavailable_count'] ?> strikes
                                        </span>
                                    </td>
                                    <td><strong>₱<?= number_format($doc['doctor_earnings'], 2) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="modal-footer-actions" style="margin-top: 1.25rem;">
                    <button class="btn btn--outline" data-close="analyticsModal">Close Reports</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: DOCTOR INCIDENT AUDIT -->
    <div class="modal-overlay" id="unavailReportModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Doctor Unavailability &amp; Attendance Audit</h3>
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
                                <th>Date &amp; Time</th>
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

    <!-- MODAL 4: PAYMENT VERIFICATION -->
    <!-- MODAL 4: PAYMENT VERIFICATION & CASHIER CREDIT -->
    <div class="modal-overlay" id="paymentVerifyModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Cashier Desk: Verify &amp; Credit Patient Payments</h3>
                <button class="modal__close" data-close="paymentVerifyModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1rem;">
                    Pre-payment is disabled for online bookings. Enter the <strong>Official Receipt (OR) Number</strong> issued at the clinic desk to mark invoices as Paid and credit the patient's record.
                </p>

                <div class="list-container">
                    <?php if (count($pendingPayments) > 0): ?>
                        <?php foreach ($pendingPayments as $pay): ?>
                            <div class="list-item" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid var(--border-subtle); flex-wrap: wrap; gap: 1rem;">
                                <div style="flex: 1; min-width: 250px;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                                        <code class="booking-code-badge"><?= htmlspecialchars($pay['invoice_number']) ?></code>
                                        <span class="pill pill--pending">₱<?= number_format($pay['total_amount'], 2) ?> Unpaid</span>
                                    </div>
                                    <h4 style="margin: 0.2rem 0;"><?= htmlspecialchars($pay['patient_name']) ?> &bull; <?= htmlspecialchars($pay['phone_number'] ?? 'N/A') ?></h4>
                                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">
                                        <?= htmlspecialchars($pay['description']) ?> &bull; Created: <?= date('M d, Y', strtotime($pay['created_at'])) ?>
                                    </p>
                                </div>

                                <!-- Form to Enter OR # and Confirm Payment -->
                                <form action="admin.php" method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; margin: 0;">
                                    <input type="hidden" name="action" value="verify_payment">
                                    <input type="hidden" name="bill_id" value="<?= $pay['id'] ?>">

                                    <div>
                                        <label style="font-size: 0.725rem; font-weight: 600; display: block; margin-bottom: 0.2rem;">Payment Method</label>
                                        <select name="payment_method" style="padding: 0.45rem 0.6rem; font-size: 0.8rem; border: 1px solid var(--border-subtle); border-radius: 4px;" required>
                                            <option value="Cash">Cash (Onsite)</option>
                                            <option value="GCash">GCash</option>
                                            <option value="Maya">Maya</option>
                                            <option value="Card">Credit / Debit Card</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label style="font-size: 0.725rem; font-weight: 600; display: block; margin-bottom: 0.2rem;">Official Receipt (OR) # <span style="color:#dc2626;">*</span></label>
                                        <input 
                                            type="text" 
                                            name="official_receipt_no" 
                                            placeholder="e.g. OR-84920" 
                                            required 
                                            style="padding: 0.45rem 0.65rem; font-size: 0.8rem; border: 1px solid var(--border-subtle); border-radius: 4px; width: 140px;"
                                        >
                                    </div>

                                    <button type="submit" class="btn btn--primary" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; white-space: nowrap;">
                                        &#10003; Credit Payment
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 2rem 0;">All patient balances are currently settled.</p>
                    <?php endif; ?>
                </div>

                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="paymentVerifyModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 5: STAFF REGISTRATION -->
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

    <!-- MODAL 6: DATA SECURITY & AUDIT TRAIL -->
    <div class="modal-overlay" id="securityModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>&#128737; Clinical Data Security &amp; Audit Trail</h3>
                <button class="modal__close" data-close="securityModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1rem;">
                    Immutable access logs recording administrative actions, staff creation, inventory adjustments, and payment verifications to safeguard patient data.
                </p>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Staff Member</th>
                                <th>Action Performed</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($securityLogs) > 0): ?>
                                <?php foreach ($securityLogs as $slog): ?>
                                    <tr>
                                        <td><small><?= date('M d, Y h:i:s A', strtotime($slog['created_at'])) ?></small></td>
                                        <td><strong><?= htmlspecialchars($slog['user_name']) ?></strong></td>
                                        <td><span class="pill pill--neutral"><?= htmlspecialchars($slog['action']) ?></span></td>
                                        <td><?= htmlspecialchars($slog['details']) ?></td>
                                        <td><code><?= htmlspecialchars($slog['ip_address']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:1.5rem 0;">No security audit events logged yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer-actions" style="margin-top: 1.25rem;">
                    <button class="btn btn--outline" data-close="securityModal">Close Security Logs</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Master Admin Portal</p>
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
        document.getElementById('openSecurityModal').addEventListener('click', () => openModal('securityModal'));

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

        
        function adjustStockAjax(itemId, adjustment) {
            const formData = new FormData();
            formData.append('ajax_action', 'adjust_stock');
            formData.append('item_id', itemId);
            formData.append('adjustment', adjustment);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    
                    const qtyElem = document.getElementById(`qty-val-${itemId}`);
                    if (qtyElem) qtyElem.innerText = data.new_qty;

                    
                    const statusCell = document.getElementById(`status-cell-${itemId}`);
                    if (statusCell) {
                        if (data.is_low) {
                            statusCell.innerHTML = `<span class="pill pill--danger">&#9888; Low Stock (&le; ${data.min_th})</span>`;
                        } else {
                            statusCell.innerHTML = `<span class="pill pill--ok">&#10003; In Stock</span>`;
                        }
                    }
                } else {
                    alert(data.error || 'Failed to update stock');
                }
            })
            .catch(err => console.error('Error updating stock:', err));
        }
    </script>

    <script src="../scripts/session_timeout.js"></script>
    <script src="../scripts/session_checker.js"></script>
</body>
</html>