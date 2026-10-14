<?php
session_start();
require_once '../db.php';

// Authentication Guard
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$patient_full_name = htmlspecialchars($_SESSION['user_name'] ?? 'Patient');
$patient_first_name = explode(' ', trim($patient_full_name))[0];

$booking_msg = '';
$booking_error = '';
$open_modal = '';

// ==========================================
// 1. SAVE NEW APPOINTMENT & CREATE ₱500 BILL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
    $department = trim($_POST['department'] ?? '');
    $doctor     = trim($_POST['doctor'] ?? '');
    $dateTime   = trim($_POST['appointment_datetime'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');

    if (empty($department) || empty($dateTime)) {
        $booking_error = "Please choose a department and appointment time.";
        $open_modal = 'bookModal';
    } else {
        if (empty($doctor)) {
            $doctor = "Dr. Assigned ($department Team)";
        }

        try {
            $pdo->beginTransaction();

            // GENERATE UNIQUE HOSPITAL BOOKING CODE
            $bookingCode = 'HSP-' . date('ym') . '-' . rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO appointments (user_id, booking_code, department, doctor_name, appointment_datetime, reason, status)
                VALUES (:user_id, :bcode, :dept, :doctor, :datetime, :reason, 'Confirmed')
            ");
            $stmt->execute([
                ':user_id'  => $user_id,
                ':bcode'    => $bookingCode,
                ':dept'     => $department,
                ':doctor'   => $doctor,
                ':datetime' => $dateTime,
                ':reason'   => $reason
            ]);
            $appointment_id = $pdo->lastInsertId();

            // Determine Recommended Lab
            $labName = null;
            $labFee = 0.00;
            $labStatus = 'none';

            if ($department === 'Cardiology') {
                $labName = '12-Lead ECG & Cardiac Biomarker Panel';
                $labFee = 650.00;
                $labStatus = 'pending';
            } elseif ($department === 'General Medicine' || $department === 'Pediatrics') {
                $labName = 'Complete Blood Count (CBC) & Urinalysis';
                $labFee = 350.00;
                $labStatus = 'pending';
            } elseif ($department === 'Dermatology') {
                $labName = 'Skin Culture & Allergy Patch Test';
                $labFee = 450.00;
                $labStatus = 'pending';
            }

            // Create ₱500 Base Billing
            $invoiceNum = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
            $baseFee = 500.00;

            $billStmt = $pdo->prepare("
                INSERT INTO billings (user_id, appointment_id, invoice_number, description, base_fee, lab_name, lab_fee, lab_status, total_amount, payment_status)
                VALUES (:user_id, :apt_id, :inv_num, :descr, :base_fee, :lab_name, :lab_fee, :lab_status, :total_amt, 'Unpaid')
            ");
            $billStmt->execute([
                ':user_id'    => $user_id,
                ':apt_id'     => $appointment_id,
                ':inv_num'    => $invoiceNum,
                ':descr'      => "Consultation [$bookingCode] - $department ($doctor)",
                ':base_fee'   => $baseFee,
                ':lab_name'   => $labName,
                ':lab_fee'    => $labFee,
                ':lab_status' => $labStatus,
                ':total_amt'  => $baseFee
            ]);

            // Notification
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:user_id, 'Appointment Booked', :msg, 'confirmed')
            ");
            $notifStmt->execute([
                ':user_id' => $user_id,
                ':msg'     => "Your visit with $doctor is confirmed. Reference Code: $bookingCode."
            ]);

            $pdo->commit();
            $booking_msg = "Appointment scheduled! Your Reference Code is: <strong>$bookingCode</strong>. A ₱500.00 invoice was created under Billing.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $booking_error = "Database Error: " . $e->getMessage();
            $open_modal = 'bookModal';
        }
    }
}

// ==========================================
// 2. CANCEL APPOINTMENT WITH GUARANTEED ₱50 FINE (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
    $apt_id = intval($_POST['appointment_id'] ?? 0);
    $cancel_reason = trim($_POST['cancellation_reason'] ?? '');
    if (empty($cancel_reason)) {
        $cancel_reason = "No reason provided by patient";
    }

    $checkApt = $pdo->prepare("SELECT * FROM appointments WHERE id = :id AND user_id = :user_id");
    $checkApt->execute([':id' => $apt_id, ':user_id' => $user_id]);
    $appointment = $checkApt->fetch(PDO::FETCH_ASSOC);

    if ($appointment && $appointment['status'] !== 'Cancelled') {
        try {
            $pdo->beginTransaction();

            $wasConfirmed = ($appointment['status'] === 'Confirmed');
            $code = !empty($appointment['booking_code']) ? $appointment['booking_code'] : ('#' . $apt_id);

            // 1. Mark Appointment as Cancelled
            $updApt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'Cancelled', cancellation_reason = :reason 
                WHERE id = :id
            ");
            $updApt->execute([':reason' => $cancel_reason, ':id' => $apt_id]);

            // 2. Apply ₱50 Penalty Fee
            if ($wasConfirmed) {
                // Check if a bill already exists for this appointment
                $bCheck = $pdo->prepare("SELECT id FROM billings WHERE appointment_id = :apt_id LIMIT 1");
                $bCheck->execute([':apt_id' => $apt_id]);
                $existingBill = $bCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingBill) {
                    // Update existing invoice to the ₱50 cancellation fee
                    $updBill = $pdo->prepare("
                        UPDATE billings 
                        SET description = :desc, 
                            base_fee = 50.00, 
                            lab_fee = 0.00, 
                            lab_status = 'declined', 
                            total_amount = 50.00, 
                            payment_status = 'Unpaid'
                        WHERE id = :bill_id
                    ");
                    $updBill->execute([
                        ':desc'    => "Cancellation Penalty [$code] - " . $appointment['doctor_name'],
                        ':bill_id' => $existingBill['id']
                    ]);
                } else {
                    // GUARANTEED INSERT: If no bill was linked, create a new ₱50 fine invoice!
                    $fineInvoiceNum = 'INV-FINE-' . date('Ymd') . '-' . rand(1000, 9999);
                    $insBill = $pdo->prepare("
                        INSERT INTO billings (user_id, appointment_id, invoice_number, description, base_fee, lab_fee, lab_status, total_amount, payment_status)
                        VALUES (:user_id, :apt_id, :inv_num, :desc, 50.00, 0.00, 'declined', 50.00, 'Unpaid')
                    ");
                    $insBill->execute([
                        ':user_id' => $user_id,
                        ':apt_id'  => $apt_id,
                        ':inv_num' => $fineInvoiceNum,
                        ':desc'    => "Cancellation Penalty [$code] - " . $appointment['doctor_name']
                    ]);
                }

                // Create Notification
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (:user_id, 'Appointment Cancelled (Fine Added)', :msg, 'comment')
                ");
                $notifStmt->execute([
                    ':user_id' => $user_id,
                    ':msg'     => "Cancelled booking [$code] with " . $appointment['doctor_name'] . ". A ₱50.00 reservation fine was added to your billing."
                ]);

                $booking_msg = "Appointment [$code] cancelled. A <strong>₱50.00 doctor time reservation fee</strong> was added to your Billing & Statements.";
            } else {
                $booking_msg = "Booking [$code] cancelled.";
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $booking_error = "Error cancelling appointment: " . $e->getMessage();
        }
    }
}

// ==========================================
// 3. PATIENT DECISION: ACCEPT OR DECLINE LAB
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_lab') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    $bStmt = $pdo->prepare("SELECT * FROM billings WHERE id = :id AND user_id = :user_id");
    $bStmt->execute([':id' => $bill_id, ':user_id' => $user_id]);
    $bill = $bStmt->fetch(PDO::FETCH_ASSOC);

    if ($bill) {
        if ($decision === 'accept') {
            $newTotal = $bill['base_fee'] + $bill['lab_fee'];
            $upd = $pdo->prepare("UPDATE billings SET lab_status = 'accepted', total_amount = :tot WHERE id = :id");
            $upd->execute([':tot' => $newTotal, ':id' => $bill_id]);
            $booking_msg = "Laboratory test accepted! ₱" . number_format($bill['lab_fee'], 2) . " was added to your invoice.";
        } elseif ($decision === 'decline') {
            $newTotal = $bill['base_fee'];
            $upd = $pdo->prepare("UPDATE billings SET lab_status = 'declined', total_amount = :tot WHERE id = :id");
            $upd->execute([':tot' => $newTotal, ':id' => $bill_id]);
            $booking_msg = "Laboratory test declined. Your invoice remains at ₱" . number_format($bill['base_fee'], 2) . ".";
        }
        $open_modal = 'billingModal';
    }
}

// ==========================================
// 4. SIMULATE SETTLING INVOICE (PAYMENT)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_invoice') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    $pStmt = $pdo->prepare("UPDATE billings SET payment_status = 'Paid' WHERE id = :id AND user_id = :user_id");
    $pStmt->execute([':id' => $bill_id, ':user_id' => $user_id]);
    $booking_msg = "Payment confirmed! Invoice marked as Paid.";
    $open_modal = 'billingModal';
}

// ==========================================
// 5. FETCH BILLINGS & TOTAL OUTSTANDING
// ==========================================
$billQuery = $pdo->prepare("SELECT * FROM billings WHERE user_id = :user_id ORDER BY created_at DESC");
$billQuery->execute([':user_id' => $user_id]);
$billings = $billQuery->fetchAll(PDO::FETCH_ASSOC);

$totalOutstanding = 0.00;
foreach ($billings as $b) {
    if ($b['payment_status'] === 'Unpaid') {
        $totalOutstanding += $b['total_amount'];
    }
}

// ==========================================
// 6. FETCH DOCTORS, NOTIFICATIONS & APPOINTMENTS
// ==========================================
$docStmt = $pdo->prepare("SELECT full_name, department FROM users WHERE role = 'doctor'");
$docStmt->execute();
$dbDoctors = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([':user_id' => $user_id]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCount = 0;
foreach ($notifications as $n) {
    if ($n['is_read'] == 0) $unreadCount++;
}

// Medical Records
$recStmt = $pdo->prepare("
    SELECT mr.*, u.full_name AS doctor_name 
    FROM medical_records mr
    JOIN users u ON mr.doctor_id = u.id
    WHERE mr.patient_id = :user_id
    ORDER BY mr.created_at DESC
");
$recStmt->execute([':user_id' => $user_id]);
$medicalRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Appointments
$fetchStmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = :user_id ORDER BY appointment_datetime ASC");
$fetchStmt->execute([':user_id' => $user_id]);
$appointments = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

// Next upcoming active appointment
$nextAppointment = null;
$now = date('Y-m-d H:i:s');
foreach ($appointments as $apt) {
    if ($apt['appointment_datetime'] >= $now && $apt['status'] !== 'Cancelled') {
        $nextAppointment = $apt;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Patient Portal</title>
    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/patient.css">

    <style>
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md, 8px);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .alert--error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert--success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        
        .auto-doctor-box {
            background-color: #f1f5f9;
            border: 1px solid var(--border-subtle);
            color: var(--primary-dark);
            font-weight: 600;
            cursor: not-allowed;
        }
        .auto-hint { font-size: 0.775rem; color: var(--text-muted); margin-top: 0.25rem; }

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

        /* Notification Styling */
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
            width: 340px;
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
        .notif-item {
            padding: 0.85rem 1.1rem;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            gap: 0.75rem;
            text-decoration: none;
        }
        .notif-item.unread { background-color: #f0f9ff; }
        .notif-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #dcfce7;
            color: #15803d;
        }
        .notif-content h5 { font-size: 0.85rem; color: var(--text-dark); margin-bottom: 0.2rem; font-weight: 600; }
        .notif-content p { font-size: 0.775rem; color: var(--text-body); line-height: 1.4; margin-bottom: 0.25rem; }
        .notif-content small { font-size: 0.7rem; color: var(--text-muted); }

        /* Dashboard Split Layout */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 2rem;
            align-items: start;
        }
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
        }

        /* Calendar Styling */
        .calendar-widget {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            box-shadow: var(--shadow-md);
        }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
        .calendar-header h3 { font-size: 1.2rem; color: var(--text-dark); font-weight: 700; }
        .calendar-nav-btn {
            background: #f1f5f9;
            border: 1px solid var(--border-subtle);
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--text-dark);
            transition: var(--transition);
        }
        .calendar-nav-btn:hover { background-color: var(--primary-light); color: var(--primary); }
        .calendar-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: 600; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem; }
        .calendar-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-dark);
            position: relative;
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            transition: var(--transition);
        }
        .calendar-day:hover:not(.empty) { background-color: var(--primary-light); color: var(--primary); }
        .calendar-day.empty { background: transparent; border: transparent; cursor: default; }
        .calendar-day.today { border-color: var(--primary); font-weight: 700; }
        .calendar-day.selected { background-color: var(--primary) !important; color: #ffffff !important; }
        .calendar-day.has-event::after {
            content: '';
            position: absolute;
            bottom: 4px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--accent-teal);
        }
        .calendar-day.selected.has-event::after { background-color: #ffffff; }

        .calendar-agenda { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border-subtle); }
        .agenda-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
        .agenda-header h4 { font-size: 0.9rem; color: var(--text-dark); }
        .agenda-header span { font-size: 0.775rem; color: var(--text-muted); }
        .agenda-list { display: flex; flex-direction: column; gap: 0.75rem; max-height: 270px; overflow-y: auto; }
        
        .agenda-card {
            background: var(--bg-body);
            border-left: 3px solid var(--accent-teal);
            border-radius: var(--radius-md);
            padding: 0.85rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .agenda-card.cancelled {
            border-left-color: #94a3b8;
            opacity: 0.75;
        }

        .agenda-card h5 { font-size: 0.875rem; color: var(--text-dark); margin-bottom: 0.25rem; }
        .agenda-card p { font-size: 0.775rem; color: var(--text-muted); margin: 0 0 0.2rem 0; }

        /* Invoice Card Styles */
        .invoice-card {
            background: var(--bg-body);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .invoice-card__top { display: flex; justify-content: space-between; align-items: flex-start; }
        .lab-recommendation-box {
            background: #ffffff;
            border: 1px dashed var(--primary);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        @media (max-width: 992px) {
            .dashboard-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="navbar__brand">
            <img src="../images/logo.png" alt="Hosplinic Logo" class="navbar__logo">
            <h1 class="navbar__title">Hosplinic</h1>
            <span class="patient-badge">Patient Portal</span>
        </div>
        
        <div class="navbar__actions" style="display: flex; align-items: center; gap: 1rem;">
            
            <!-- NOTIFICATION BELL -->
            <div class="notif-wrapper">
                <button type="button" class="notif-btn" id="notifBtn" aria-label="Notifications">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </button>

                <!-- Dropdown -->
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <h4>Notifications</h4>
                        <span style="font-size: 0.75rem; color: var(--primary); font-weight: 600;"><?= $unreadCount ?> new</span>
                    </div>

                    <div class="notif-list">
                        <?php if (count($notifications) > 0): ?>
                            <?php foreach ($notifications as $notif): ?>
                                <div class="notif-item <?= $notif['is_read'] == 0 ? 'unread' : '' ?>">
                                    <div class="notif-icon">
                                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                    <div class="notif-content">
                                        <h5><?= htmlspecialchars($notif['title']) ?></h5>
                                        <p><?= htmlspecialchars($notif['message']) ?></p>
                                        <small><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                                No notifications yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <span class="patient-greeting">Hi, <?= $patient_full_name ?></span>
            <a href="../logout.php" class="btn btn--outline">Logout</a>
        </div>
    </header>

    <main class="dashboard" style="max-width: 1200px;">

        <!-- Welcome Banner with Reference Code -->
        <div class="dashboard__banner">
            <div>
                <h2>Welcome Back, <?= $patient_first_name ?></h2>
                <p>Manage your health records, check clinic bookings, and verify your reference codes.</p>
            </div>
            <div class="banner-notice">
                <?php if ($nextAppointment): ?>
                    <span>Next Visit: <strong><?= date('M d, Y \a\t h:i A', strtotime($nextAppointment['appointment_datetime'])) ?></strong> (<?= htmlspecialchars($nextAppointment['doctor_name']) ?>)</span>
                    <div style="margin-top: 0.25rem;">
                        Reference: <span class="booking-code-badge"><?= htmlspecialchars($nextAppointment['booking_code']) ?></span>
                    </div>
                <?php else: ?>
                    <span>No upcoming appointments scheduled.</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($booking_msg)): ?>
            <div class="alert alert--success"><?= $booking_msg ?></div>
        <?php endif; ?>
        <?php if (!empty($booking_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($booking_error) ?></div>
        <?php endif; ?>

        <!-- ==========================================
             DASHBOARD SPLIT: CARDS (LEFT) & CALENDAR (RIGHT)
             ========================================== -->
        <div class="dashboard-layout">
            
            <!-- 1. LEFT: CARDS IN GRID LAYOUT -->
            <div class="cards-grid">
                
                <!-- Card 1: Add Appointment -->
                <div class="card" id="openBookModal" role="button" tabindex="0">
                    <div>
                        <div class="card__icon card__icon--blue">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </div>
                        <h3 class="card__title">Book Appointment</h3>
                        <p class="card__desc">Consultation fee: <strong>₱500.00</strong>. Each booking generates an official hospital reference code.</p>
                        
                        <div class="pill-group">
                            <span class="pill pill--ok">Base Fee: ₱500.00</span>
                        </div>
                    </div>
                    <span class="card__link">+ Schedule Visit Now &rarr;</span>
                </div>

                <!-- Card 2: Billing & Statements -->
                <div class="card" id="openBillingModal" role="button" tabindex="0">
                    <div>
                        <div class="card__icon card__icon--purple">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                        </div>
                        <h3 class="card__title">Billing & Statements</h3>
                        <p class="card__desc">Review consultation invoices, cancellation fees, and settle outstanding balances online.</p>
                        
                        <div class="pill-group">
                            <?php if ($totalOutstanding > 0): ?>
                                <span class="pill pill--warn" style="background:#fee2e2; color:#b91c1c;">₱<?= number_format($totalOutstanding, 2) ?> Outstanding</span>
                            <?php else: ?>
                                <span class="pill pill--ok">₱0.00 (All Paid)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="card__link">View Invoices &rarr;</span>
                </div>

                <!-- Card 3: Diagnosis & Records -->
                <div class="card" id="openDiagnosisModal" role="button" tabindex="0">
                    <div>
                        <div class="card__icon card__icon--amber">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <h3 class="card__title">Diagnosis & Records</h3>
                        <p class="card__desc">Access verified doctor notes, clinical documents, and downloadable files.</p>
                        
                        <div class="pill-group">
                            <span class="pill pill--ok"><?= count($medicalRecords) ?> Record(s) Available</span>
                        </div>
                    </div>
                    <span class="card__link">View Clinical Records &rarr;</span>
                </div>

            </div>

            <!-- 2. RIGHT: LIVE DASHBOARD CALENDAR (WITH DOTS) -->
            <div class="calendar-widget">
                <div class="calendar-header">
                    <h3 id="calendarMonthYear">Month Year</h3>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="calendar-nav-btn" id="prevMonthBtn">&lt;</button>
                        <button type="button" class="calendar-nav-btn" id="nextMonthBtn">&gt;</button>
                    </div>
                </div>

                <div class="calendar-weekdays">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>

                <div class="calendar-days" id="calendarDays">
                    <!-- Populated automatically on dashboard load -->
                </div>

                <p style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                    <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent-teal);"></span>
                    Days with dots have scheduled doctor visits.
                </p>

                <!-- Daily Agenda with Cancel Option & Booking Reference Codes -->
                <div class="calendar-agenda">
                    <div class="agenda-header">
                        <h4>Appointments for Date:</h4>
                        <span id="selectedDateText">Today</span>
                    </div>

                    <div class="agenda-list" id="dashboardAgendaList">
                        <!-- Injected on date click -->
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- ==========================================
         MODAL: CANCEL APPOINTMENT CONFIRMATION
         ========================================== -->
    <div class="modal-overlay" id="cancelModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Cancel Appointment</h3>
                <button class="modal__close" data-close="cancelModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="patient.php" method="POST" class="patient-form">
                    <input type="hidden" name="action" value="cancel_appointment">
                    <input type="hidden" name="appointment_id" id="cancel_apt_id" value="">

                    <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.825rem; color: #92400e; margin-bottom: 1rem;">
                        <strong>&#9888; Physician Time Reservation Policy:</strong>
                        <p style="margin: 0.35rem 0 0 0;">
                            Cancelling Reference: <span class="booking-code-badge" id="cancel_display_code">HSP-0000</span><br>
                            Because this slot was reserved by the physician, a <strong>₱50.00 reservation fee</strong> will be billed.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="cancellation_reason">Reason for Cancellation (Optional)</label>
                        <textarea 
                            name="cancellation_reason" 
                            id="cancellation_reason" 
                            rows="2" 
                            placeholder="e.g. Schedule conflict, feeling better, emergency..."
                        ></textarea>
                    </div>

                    <div class="modal-actions" style="margin-top: 1.25rem;">
                        <button type="submit" class="btn btn--primary" style="background:#dc2626; flex:1;">
                            Confirm Cancellation (₱50 Fee)
                        </button>
                        <button type="button" class="btn btn--outline" data-close="cancelModal">Keep Appointment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: BOOK APPOINTMENT -->
    <div class="modal-overlay" id="bookModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Book an Appointment</h3>
                <button class="modal__close" data-close="bookModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="patient.php" method="POST" class="patient-form">
                    <input type="hidden" name="action" value="book_appointment">

                    <div class="form-group">
                        <label for="deptSelect">Select Medical Department</label>
                        <select name="department" id="deptSelect" required>
                            <option value="">-- Choose a Department --</option>
                            <option value="Cardiology">Cardiology</option>
                            <option value="Pediatrics">Pediatrics</option>
                            <option value="Dermatology">Dermatology</option>
                            <option value="General Medicine">General Medicine</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="autoDoctorInput">Assigned Specialist</label>
                        <input 
                            type="text" 
                            name="doctor" 
                            id="autoDoctorInput" 
                            class="auto-doctor-box"
                            value="Select a department above..." 
                            readonly 
                            required
                        >
                        <span class="auto-hint" id="autoHintText">The specialist will be assigned automatically based on clinic availability.</span>
                    </div>

                    <div class="form-group">
                        <label for="bookDateTime">Date & Preferred Time</label>
                        <input type="datetime-local" name="appointment_datetime" id="bookDateTime" required>
                    </div>

                    <div class="form-group">
                        <label for="bookNotes">Reason for Visit</label>
                        <textarea name="reason" id="bookNotes" rows="2" placeholder="Briefly describe your symptoms or concern..."></textarea>
                    </div>

                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.75rem; border-radius: var(--radius-md); font-size: 0.8rem; color: #166534;">
                        &#128179; <strong>Billing & Check-in Notice:</strong> An initial consultation fee of <strong>₱500.00</strong> will be invoiced, and a unique <strong>Reference Code</strong> will be assigned upon confirmation.
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Confirm & Book (₱500)</button>
                        <button type="button" class="btn btn--outline" data-close="bookModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: BILLING -->
    <div class="modal-overlay" id="billingModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Billing & Statements</h3>
                <button class="modal__close" data-close="billingModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="billing-summary-box">
                    <div>
                        <small>Total Outstanding Balance</small>
                        <strong class="billing-balance">₱<?= number_format($totalOutstanding, 2) ?></strong>
                    </div>
                    <?php if ($totalOutstanding > 0): ?>
                        <span class="pill pill--warn" style="background:#fee2e2; color:#b91c1c;">Payment Pending</span>
                    <?php else: ?>
                        <span class="pill pill--ok">All Invoices Paid</span>
                    <?php endif; ?>
                </div>

                <h4 style="margin: 1.5rem 0 0.75rem 0; font-size: 0.95rem;">Recent Invoices</h4>

                <?php if (count($billings) > 0): ?>
                    <?php foreach ($billings as $bill): ?>
                        <div class="invoice-card">
                            <div class="invoice-card__top">
                                <div>
                                    <strong style="font-size: 0.95rem; color: var(--text-dark);"><?= htmlspecialchars($bill['invoice_number']) ?></strong>
                                    <p style="font-size: 0.825rem; color: var(--text-muted);"><?= htmlspecialchars($bill['description']) ?> &bull; <?= date('M d, Y', strtotime($bill['created_at'])) ?></p>
                                </div>
                                <div style="text-align: right;">
                                    <strong style="font-size: 1.1rem; color: var(--text-dark);">₱<?= number_format($bill['total_amount'], 2) ?></strong>
                                    <div>
                                        <span class="pill <?= $bill['payment_status'] === 'Paid' ? 'pill--ok' : 'pill--warn' ?>">
                                            <?= htmlspecialchars($bill['payment_status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div style="font-size: 0.8rem; color: var(--text-muted); border-top: 1px solid var(--border-subtle); padding-top: 0.5rem; display: flex; justify-content: space-between;">
                                <span>Fee:</span>
                                <span>₱<?= number_format($bill['base_fee'], 2) ?></span>
                            </div>

                            <?php if (!empty($bill['lab_name']) && $bill['lab_fee'] > 0): ?>
                                <div class="lab-recommendation-box">
                                    <div style="font-size: 0.8rem;">
                                        <strong>&#129514; Doctor's Recommended Lab Test:</strong>
                                        <p style="margin: 0; color: var(--text-body);"><?= htmlspecialchars($bill['lab_name']) ?> (<strong>+₱<?= number_format($bill['lab_fee'], 2) ?></strong>)</p>
                                    </div>

                                    <?php if ($bill['lab_status'] === 'pending' && $bill['payment_status'] === 'Unpaid'): ?>
                                        <form action="patient.php" method="POST" style="display: flex; gap: 0.4rem;">
                                            <input type="hidden" name="action" value="respond_lab">
                                            <input type="hidden" name="bill_id" value="<?= $bill['id'] ?>">
                                            <button type="submit" name="decision" value="accept" class="btn btn--primary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">Accept (+₱<?= number_format($bill['lab_fee'], 0) ?>)</button>
                                            <button type="submit" name="decision" value="decline" class="btn btn--outline" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">Decline</button>
                                        </form>
                                    <?php elseif ($bill['lab_status'] === 'accepted'): ?>
                                        <span class="pill pill--ok" style="font-size: 0.75rem;">&#10003; Lab Accepted (+₱<?= number_format($bill['lab_fee'], 2) ?>)</span>
                                    <?php elseif ($bill['lab_status'] === 'declined'): ?>
                                        <span class="pill pill--neutral" style="font-size: 0.75rem;">&#10005; Lab Declined</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($bill['payment_status'] === 'Unpaid'): ?>
                                <form action="patient.php" method="POST" style="margin-top: 0.25rem;">
                                    <input type="hidden" name="action" value="pay_invoice">
                                    <input type="hidden" name="bill_id" value="<?= $bill['id'] ?>">
                                    <button type="submit" class="btn btn--primary" style="width: 100%; padding: 0.5rem; font-size: 0.85rem;">
                                        Pay Balance (₱<?= number_format($bill['total_amount'], 2) ?>)
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 2rem 0;">No invoices generated yet.</p>
                <?php endif; ?>

                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="billingModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: DIAGNOSIS -->
    <div class="modal-overlay" id="diagnosisModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Clinical Diagnosis & Medical Documents</h3>
                <button class="modal__close" data-close="diagnosisModal">&times;</button>
            </div>
            <div class="modal__body">
                <?php if (count($medicalRecords) > 0): ?>
                    <?php foreach ($medicalRecords as $record): ?>
                        <div class="record-card" style="margin-bottom: 1rem;">
                            <div class="record-card__header">
                                <div>
                                    <h4 style="color: var(--text-dark); margin-bottom: 0.2rem;"><?= htmlspecialchars($record['title']) ?></h4>
                                    <small style="color: var(--text-muted);">Attending: <?= htmlspecialchars($record['doctor_name']) ?> &bull; <?= date('M d, Y', strtotime($record['created_at'])) ?></small>
                                </div>
                                <span class="pill pill--ok">Available</span>
                            </div>
                            <?php if (!empty($record['diagnosis_notes'])): ?>
                                <div class="record-card__content" style="margin: 0.75rem 0; font-size: 0.85rem; color: var(--text-body);">
                                    <p><strong>Doctor's Notes:</strong> <?= nl2br(htmlspecialchars($record['diagnosis_notes'])) ?></p>
                                </div>
                            <?php endif; ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 0.65rem 0.85rem; margin-top: 0.75rem;">
                                <div style="font-size: 0.8rem; color: var(--text-dark); display: flex; align-items: center; gap: 0.4rem;">
                                    <span style="font-size: 1.1rem;">&#128196;</span>
                                    <div>
                                        <strong><?= htmlspecialchars($record['file_name']) ?></strong>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">(<?= htmlspecialchars($record['file_size']) ?>)</span>
                                    </div>
                                </div>
                                <a href="../<?= htmlspecialchars($record['file_path']) ?>" download="<?= htmlspecialchars($record['file_name']) ?>" class="btn btn--primary" style="padding: 0.4rem 0.85rem; font-size: 0.8rem;">
                                    &#128190; Download
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2.5rem 0; font-size: 0.875rem;">No medical records uploaded yet.</p>
                <?php endif; ?>

                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="diagnosisModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Patient Care Portal</p>
    </footer>

    <!-- Interactive Scripts -->
    <script>
        const registeredDoctors = <?= json_encode($dbDoctors) ?>;
        const patientAppointments = <?= json_encode($appointments) ?>;

        // Notification dropdown toggle
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');

        if (notifBtn && notifDropdown) {
            notifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle('active');
            });

            document.addEventListener('click', (e) => {
                if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                    notifDropdown.classList.remove('active');
                }
            });
        }

        // Auto-assign doctor based on department
        const deptSelect = document.getElementById('deptSelect');
        const autoDoctorInput = document.getElementById('autoDoctorInput');
        const autoHintText = document.getElementById('autoHintText');

        if (deptSelect) {
            deptSelect.addEventListener('change', function() {
                const selectedDept = this.value;
                if (!selectedDept) {
                    autoDoctorInput.value = "Select a department above...";
                    autoHintText.innerText = "The specialist will be assigned automatically based on clinic availability.";
                    return;
                }

                const matchedDoctor = registeredDoctors.find(doc => 
                    doc.department && doc.department.toLowerCase() === selectedDept.toLowerCase()
                );

                if (matchedDoctor) {
                    autoDoctorInput.value = matchedDoctor.full_name;
                    autoHintText.innerHTML = `&#10003; <strong>${matchedDoctor.full_name}</strong> is assigned for ${selectedDept}.`;
                } else {
                    autoDoctorInput.value = `Attending Physician (${selectedDept})`;
                    autoHintText.innerText = `An on-call specialist from ${selectedDept} will take your consultation.`;
                }
            });
        }

        // Live Dashboard Calendar
        let calendarDate = new Date();
        let selectedDayDate = new Date();
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

        const appointmentsByDate = {};
        patientAppointments.forEach(apt => {
            const dStr = apt.appointment_datetime.split(' ')[0];
            if (!appointmentsByDate[dStr]) {
                appointmentsByDate[dStr] = [];
            }
            appointmentsByDate[dStr].push(apt);
        });

        function renderDashboardCalendar() {
            const year = calendarDate.getFullYear();
            const month = calendarDate.getMonth();

            document.getElementById('calendarMonthYear').innerText = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const lastDay = new Date(year, month + 1, 0).getDate();
            const calendarDays = document.getElementById('calendarDays');
            calendarDays.innerHTML = '';

            for (let i = 0; i < firstDayIndex; i++) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'calendar-day empty';
                calendarDays.appendChild(emptyDiv);
            }

            const today = new Date();

            for (let day = 1; day <= lastDay; day++) {
                const dayDiv = document.createElement('div');
                dayDiv.className = 'calendar-day';
                dayDiv.innerText = day;

                const formattedMonth = String(month + 1).padStart(2, '0');
                const formattedDay = String(day).padStart(2, '0');
                const fullDateKey = `${year}-${formattedMonth}-${formattedDay}`;

                if (day === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                    dayDiv.classList.add('today');
                }

                if (day === selectedDayDate.getDate() && month === selectedDayDate.getMonth() && year === selectedDayDate.getFullYear()) {
                    dayDiv.classList.add('selected');
                }

                if (appointmentsByDate[fullDateKey] && appointmentsByDate[fullDateKey].some(a => a.status !== 'Cancelled')) {
                    dayDiv.classList.add('has-event');
                }

                dayDiv.addEventListener('click', () => {
                    selectedDayDate = new Date(year, month, day);
                    renderDashboardCalendar();
                    updateDashboardAgenda(fullDateKey, day);
                });

                calendarDays.appendChild(dayDiv);
            }

            const activeKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(selectedDayDate.getDate()).padStart(2, '0')}`;
            updateDashboardAgenda(activeKey, selectedDayDate.getDate());
        }

        // Open Cancel Modal Function with Booking Code preview
        function promptCancelAppointment(aptId, bookingCode) {
            document.getElementById('cancel_apt_id').value = aptId;
            document.getElementById('cancel_display_code').innerText = bookingCode;
            openModal('cancelModal');
        }

        function updateDashboardAgenda(dateKey, dayNumber) {
            const dateText = document.getElementById('selectedDateText');
            const listContainer = document.getElementById('dashboardAgendaList');
            const displayDate = `${monthNames[calendarDate.getMonth()]} ${dayNumber}, ${calendarDate.getFullYear()}`;

            dateText.innerText = displayDate;
            listContainer.innerHTML = '';

            const dayApts = appointmentsByDate[dateKey];

            if (dayApts && dayApts.length > 0) {
                dayApts.forEach(apt => {
                    const card = document.createElement('div');
                    const isCancelled = (apt.status === 'Cancelled');
                    card.className = `agenda-card ${isCancelled ? 'cancelled' : ''}`;
                    
                    const code = apt.booking_code ? apt.booking_code : ('#' + apt.id);
                    let cancelBtnHtml = '';
                    if (!isCancelled) {
                        cancelBtnHtml = `
                            <button type="button" onclick="promptCancelAppointment(${apt.id}, '${code}')" class="btn btn--outline" style="padding: 0.35rem 0.65rem; font-size: 0.725rem; color: #dc2626; border-color: #fca5a5;">
                                Cancel
                            </button>
                        `;
                    }

                    card.innerHTML = `
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                                <span class="booking-code-badge">${code}</span>
                                <span class="pill ${isCancelled ? 'pill--neutral' : 'pill--ok'}" style="font-size: 0.7rem;">
                                    ${apt.status}
                                </span>
                            </div>
                            <h5>${apt.doctor_name} &bull; ${apt.department}</h5>
                            <p>Time: <strong>${apt.appointment_datetime}</strong></p>
                            ${apt.reason ? `<p>Reason: <em>${apt.reason}</em></p>` : ''}
                            ${isCancelled && apt.cancellation_reason ? `<p style="color:#b91c1c;">Cancellation: <em>${apt.cancellation_reason}</em></p>` : ''}
                        </div>
                        <div>
                            ${cancelBtnHtml}
                        </div>
                    `;
                    listContainer.appendChild(card);
                });
            } else {
                listContainer.innerHTML = `
                    <div style="text-align: center; color: var(--text-muted); padding: 0.85rem 0; font-size: 0.8rem;">
                        No appointments on this date.
                    </div>
                `;
            }
        }

        document.getElementById('prevMonthBtn').addEventListener('click', () => {
            calendarDate.setMonth(calendarDate.getMonth() - 1);
            renderDashboardCalendar();
        });

        document.getElementById('nextMonthBtn').addEventListener('click', () => {
            calendarDate.setMonth(calendarDate.getMonth() + 1);
            renderDashboardCalendar();
        });

        renderDashboardCalendar();

        // Modals Logic
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

        document.getElementById('openBookModal').addEventListener('click', () => openModal('bookModal'));
        document.getElementById('openBillingModal').addEventListener('click', () => openModal('billingModal'));
        document.getElementById('openDiagnosisModal').addEventListener('click', () => openModal('diagnosisModal'));

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

        <?php if (!empty($open_modal)): ?>
            openModal('<?= $open_modal ?>');
        <?php endif; ?>
    </script>
</body>
</html>