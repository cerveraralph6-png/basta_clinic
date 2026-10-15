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

// Helper: Calculate earliest date 3 working days ahead (Skipping Sat & Sun)
function getMinBookingDate() {
    $date = new DateTime();
    $added = 0;
    while ($added < 3) {
        $date->modify('+1 day');
        $dayOfWeek = (int)$date->format('N'); // 1 (Mon) to 7 (Sun)
        if ($dayOfWeek <= 5) { // Only count Monday - Friday
            $added++;
        }
    }
    return $date;
}

// ==========================================
// 0. AJAX API: CHECK HOURLY SLOT CAPACITY
// ==========================================
if (isset($_GET['check_slot']) && isset($_GET['date']) && isset($_GET['time'])) {
    header('Content-Type: application/json');
    $checkDate = trim($_GET['date']);
    $checkTime = trim($_GET['time']); // e.g. "08:00:00"
    $targetHour = substr($checkTime, 0, 2); // "08"

    $docCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor' AND department IS NOT NULL");
    $totalActiveDoctors = max(1, intval($docCountStmt->fetchColumn()));
    $maxCapacity = $totalActiveDoctors * 3;

    $slotQuery = $pdo->prepare("
        SELECT COUNT(*) FROM appointments 
        WHERE DATE(appointment_datetime) = :bdate 
          AND HOUR(appointment_datetime) = :bhour 
          AND status != 'Cancelled'
    ");
    $slotQuery->execute([
        ':bdate' => $checkDate,
        ':bhour' => $targetHour
    ]);
    $bookedCount = intval($slotQuery->fetchColumn());

    echo json_encode([
        'is_full' => ($bookedCount >= $maxCapacity),
        'booked' => $bookedCount,
        'max' => $maxCapacity
    ]);
    exit;
}

// ==========================================
// 1. SAVE NEW APPOINTMENT (WITH CAPACITY CHECK)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
    $department = trim($_POST['department'] ?? '');
    $doctor_id  = intval($_POST['doctor_id'] ?? 0);
    $doctor_name = trim($_POST['doctor'] ?? '');
    $bookDate   = trim($_POST['appointment_date'] ?? '');
    $bookTime   = trim($_POST['appointment_time'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');

    $dateTime   = (!empty($bookDate) && !empty($bookTime)) ? ($bookDate . ' ' . $bookTime) : '';
    $targetHour = !empty($bookTime) ? substr($bookTime, 0, 2) : '';

    $attachmentPath = null;
    $attachmentName = null;

    if (isset($_FILES['patient_attachment']) && $_FILES['patient_attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['patient_attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'mp4', 'webm'];

        if (in_array($ext, $allowed)) {
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newFileName = 'attach_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $attachmentPath = 'uploads/' . $newFileName;
                $attachmentName = $file['name'];
            }
        }
    }

    $docCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor' AND department IS NOT NULL");
    $totalActiveDoctors = max(1, intval($docCountStmt->fetchColumn()));
    $maxCapacity = $totalActiveDoctors * 3;

    $slotQuery = $pdo->prepare("
        SELECT COUNT(*) FROM appointments 
        WHERE DATE(appointment_datetime) = :bdate 
          AND HOUR(appointment_datetime) = :bhour 
          AND status != 'Cancelled'
    ");
    $slotQuery->execute([':bdate' => $bookDate, ':bhour' => $targetHour]);
    $bookedCount = intval($slotQuery->fetchColumn());

    $selectedTimestamp = strtotime($dateTime);
    $minAllowedDate = getMinBookingDate();
    $minDateStr = $minAllowedDate->format('Y-m-d');
    $dayOfWeek = (int)date('N', $selectedTimestamp);

    if (empty($department) || empty($bookDate) || empty($bookTime)) {
        $booking_error = "Please choose a department, date, and consultation time slot.";
        $open_modal = 'bookModal';
    } elseif ($bookDate < $minDateStr) {
        $booking_error = "Booking policy violation: Same-day or rush bookings are disabled. Earliest allowed date is " . $minAllowedDate->format('M d, Y') . ".";
        $open_modal = 'bookModal';
    } elseif ($bookedCount >= $maxCapacity) {
        $booking_error = "This hour slot is fully booked (Max capacity reached). Please select another time or date.";
        $open_modal = 'bookModal';
    } elseif ($dayOfWeek === 7) {
        $booking_error = "The clinic is closed on Sundays. Please select Monday through Saturday.";
        $open_modal = 'bookModal';
    } elseif ($department === 'Cardiology' && $dayOfWeek > 4) {
        $booking_error = "Cardiology specialists are available Monday through Thursday only.";
        $open_modal = 'bookModal';
    } elseif ($department === 'Pediatrics' && $dayOfWeek > 5) {
        $booking_error = "Pediatrics specialists are available Monday through Friday only.";
        $open_modal = 'bookModal';
    } else {
        if ($doctor_id <= 0 || empty($doctor_name)) {
            $findDoc = $pdo->prepare("
                SELECT id, full_name 
                FROM users 
                WHERE role = 'doctor' AND department = :dept 
                ORDER BY RAND() 
                LIMIT 1
            ");
            $findDoc->execute([':dept' => $department]);
            $found = $findDoc->fetch(PDO::FETCH_ASSOC);

            if ($found) {
                $doctor_id = $found['id'];
                $doctor_name = $found['full_name'];
            } else {
                $doctor_id = null;
                $doctor_name = "On-Duty Attending ($department)";
            }
        }

        try {
            $pdo->beginTransaction();

            $bookingCode = 'HSP-' . date('ym') . '-' . rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO appointments (user_id, doctor_id, booking_code, department, doctor_name, appointment_datetime, reason, attachment_path, attachment_name, status)
                VALUES (:user_id, :doc_id, :bcode, :dept, :doctor, :datetime, :reason, :attach_path, :attach_name, 'Pending')
            ");
            $stmt->execute([
                ':user_id'     => $user_id,
                ':doc_id'      => $doctor_id,
                ':bcode'       => $bookingCode,
                ':dept'        => $department,
                ':doctor'      => $doctor_name,
                ':datetime'    => $dateTime,
                ':reason'      => $reason,
                ':attach_path' => $attachmentPath,
                ':attach_name' => $attachmentName
            ]);
            $appointment_id = $pdo->lastInsertId();

            // Set up Lab Recommendation
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

            // Create Base Billing Invoice
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
                ':descr'      => "Consultation [$bookingCode] - $department ($doctor_name)",
                ':base_fee'   => $baseFee,
                ':lab_name'   => $labName,
                ':lab_fee'    => $labFee,
                ':lab_status' => $labStatus,
                ':total_amt'  => $baseFee
            ]);

            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:user_id, 'Appointment Submitted (Pending)', :msg, 'reminder')
            ");
            $notifStmt->execute([
                ':user_id' => $user_id,
                ':msg'     => "Your consultation with $doctor_name is Pending doctor confirmation. Reference: $bookingCode."
            ]);

            $pdo->commit();
            $booking_msg = "Appointment requested! Your booking is <strong>Pending Doctor Approval</strong>. Reference Code: <strong>$bookingCode</strong>.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $booking_error = "Database Error: " . $e->getMessage();
            $open_modal = 'bookModal';
        }
    }
}

// ==========================================
// 2. CANCEL APPOINTMENT
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
            $formattedDate = date('M d, Y h:i A', strtotime($appointment['appointment_datetime']));

            $updApt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'Cancelled', cancellation_reason = :reason 
                WHERE id = :id
            ");
            $updApt->execute([':reason' => $cancel_reason, ':id' => $apt_id]);

            if (!empty($appointment['doctor_id'])) {
                $docNotifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (:did, 'Appointment Cancelled by Patient', :msg, 'comment')
                ");
                $docNotifStmt->execute([
                    ':did' => $appointment['doctor_id'],
                    ':msg' => "Patient {$patient_full_name} cancelled consultation [{$code}] on {$formattedDate}. Reason: {$cancel_reason}"
                ]);
            }

            if ($wasConfirmed) {
                $bCheck = $pdo->prepare("SELECT id FROM billings WHERE appointment_id = :apt_id LIMIT 1");
                $bCheck->execute([':apt_id' => $apt_id]);
                $existingBill = $bCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingBill) {
                    $updBill = $pdo->prepare("
                        UPDATE billings 
                        SET description = :desc, base_fee = 50.00, lab_fee = 0.00, lab_status = 'declined', total_amount = 50.00, payment_status = 'Unpaid'
                        WHERE id = :bill_id
                    ");
                    $updBill->execute([
                        ':desc'    => "Cancellation Penalty [$code] - " . $appointment['doctor_name'],
                        ':bill_id' => $existingBill['id']
                    ]);
                } else {
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

                $booking_msg = "Appointment [$code] cancelled. Since this was already confirmed, a <strong>₱50.00 reservation fee</strong> was charged.";
            } else {
                $voidBill = $pdo->prepare("
                    UPDATE billings 
                    SET description = :desc, total_amount = 0.00, payment_status = 'Paid'
                    WHERE appointment_id = :apt_id AND payment_status = 'Unpaid'
                ");
                $voidBill->execute([
                    ':desc'   => "Voided (Pending Booking Cancelled) [$code]",
                    ':apt_id' => $apt_id
                ]);

                $booking_msg = "Appointment [$code] was cancelled while still Pending. <strong>No cancellation fee</strong> was charged.";
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $booking_error = "Error cancelling appointment: " . $e->getMessage();
        }
    }
}

// ==========================================
// 3. RESPOND TO LAB & SUBMIT PAYMENT WITH PROOF
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    $bill_id = intval($_POST['bill_id'] ?? 0);
    $method = trim($_POST['payment_method'] ?? '');

    $receiptPath = null;
    $receiptName = null;

    if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_receipt'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = 'receipt_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFileName)) {
                $receiptPath = 'uploads/' . $newFileName;
                $receiptName = $file['name'];
            }
        }
    }

    if (in_array($method, ['GCash', 'Maya', 'Card']) && empty($receiptPath)) {
        $booking_error = "Proof of payment receipt is required for online payment methods.";
        $open_modal = 'billingModal';
    } else {
        $payStatus = ($method === 'Onsite Payment') ? 'Payment Pending (On-site verification)' : 'Paid';

        $stmt = $pdo->prepare("
            UPDATE billings 
            SET payment_method = :method, receipt_path = :rpath, receipt_name = :rname, payment_status = :pstatus 
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([
            ':method'  => $method,
            ':rpath'   => $receiptPath,
            ':rname'   => $receiptName,
            ':pstatus' => $payStatus,
            ':id'      => $bill_id,
            ':user_id' => $user_id
        ]);

        $booking_msg = ($method === 'Onsite Payment') 
            ? "On-site payment selected. Status is <strong>Payment Pending</strong> until admin verification." 
            : "Payment submitted successfully with receipt proof!";
        $open_modal = 'billingModal';
    }
}

// ==========================================
// 4. FETCH DATA
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

$docStmt = $pdo->prepare("SELECT id, full_name, department FROM users WHERE role = 'doctor' AND department IS NOT NULL");
$docStmt->execute();
$dbDoctors = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([':user_id' => $user_id]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCount = count(array_filter($notifications, fn($n) => $n['is_read'] == 0));

$recStmt = $pdo->prepare("
    SELECT mr.*, u.full_name AS doctor_name 
    FROM medical_records mr
    JOIN users u ON mr.doctor_id = u.id
    WHERE mr.patient_id = :user_id
    ORDER BY mr.created_at DESC
");
$recStmt->execute([':user_id' => $user_id]);
$medicalRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);

$fetchStmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = :user_id ORDER BY appointment_datetime ASC");
$fetchStmt->execute([':user_id' => $user_id]);
$appointments = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

$nextAppointment = null;
$now = date('Y-m-d H:i:s');
foreach ($appointments as $apt) {
    if ($apt['appointment_datetime'] >= $now && $apt['status'] !== 'Cancelled') {
        $nextAppointment = $apt;
        break;
    }
}

$minDateObj = getMinBookingDate();
$minDateOnly = $minDateObj->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Patient Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/patient.css">

    <style>
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius-md, 8px); font-size: 0.875rem; margin-bottom: 1.5rem; }
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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

        .pill--pending { background-color: #fef3c7; color: #b45309; }

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
        .notif-item { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 0.75rem; text-decoration: none; }
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

        .dashboard-layout { display: grid; grid-template-columns: 1fr 380px; gap: 2rem; align-items: start; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; }

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
        .agenda-card.cancelled { border-left-color: #94a3b8; opacity: 0.75; }
        .agenda-card h5 { font-size: 0.875rem; color: var(--text-dark); margin-bottom: 0.25rem; }
        .agenda-card p { font-size: 0.775rem; color: var(--text-muted); margin: 0 0 0.2rem 0; }

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
            .form-row { grid-template-columns: 1fr; }
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
            
            <div class="notif-wrapper">
                <button type="button" class="notif-btn" id="notifBtn" aria-label="Notifications">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notif-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </button>

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

        <div class="dashboard__banner">
            <div>
                <h2>Welcome Back, <?= $patient_first_name ?></h2>
                <p>Manage your health records, check clinic bookings, and verify your reference codes.</p>
            </div>
            <div class="banner-notice">
                <?php if ($nextAppointment): ?>
                    <span>Next Visit: <strong><?= date('M d, Y \a\t h:i A', strtotime($nextAppointment['appointment_datetime'])) ?></strong> (<?= htmlspecialchars($nextAppointment['doctor_name']) ?>)</span>
                    <div style="margin-top: 0.25rem; display: flex; align-items: center; gap: 0.4rem;">
                        Reference: <span class="booking-code-badge"><?= htmlspecialchars($nextAppointment['booking_code']) ?></span>
                        <span class="pill <?= $nextAppointment['status'] === 'Confirmed' ? 'pill--ok' : 'pill--pending' ?>">
                            <?= htmlspecialchars($nextAppointment['status']) ?>
                        </span>
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

        <div class="dashboard-layout">
            
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
                        <p class="card__desc">Choose your department. Subject to max hourly capacity limits (Max 3/doc/hour).</p>
                        
                        <div class="pill-group">
                            <span class="pill pill--pending">Capacity Control Active</span>
                        </div>
                    </div>
                    <span class="card__link">+ Request Visit Now &rarr;</span>
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
                        <p class="card__desc">Review consultation invoices, upload payment proofs, and settle balances.</p>
                        
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

            <!-- RIGHT: LIVE CALENDAR WIDGET WITH DOTS -->
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

                <div class="calendar-days" id="calendarDays"></div>

                <p style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                    <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent-teal);"></span>
                    Days with dots have scheduled doctor visits.
                </p>

                <div class="calendar-agenda">
                    <div class="agenda-header">
                        <h4>Appointments for Date:</h4>
                        <span id="selectedDateText">Today</span>
                    </div>

                    <div class="agenda-list" id="dashboardAgendaList"></div>
                </div>
            </div>

        </div>
    </main>

    <!-- MODAL: CANCEL APPOINTMENT CONFIRMATION -->
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

                    <div id="cancelFeeNotice" style="background: #fffbeb; border: 1px solid #fde68a; padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.825rem; color: #92400e; margin-bottom: 1rem;">
                        <strong>&#9888; Cancellation Notice:</strong>
                        <p style="margin: 0.35rem 0 0 0;" id="cancelNoticeText">
                            Cancelling Reference: <span class="booking-code-badge" id="cancel_display_code">HSP-0000</span>
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
                        <button type="submit" class="btn btn--primary" style="background:#dc2626; flex:1;" id="confirmCancelSubmitBtn">
                            Confirm Cancellation
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
                <form action="patient.php" method="POST" enctype="multipart/form-data" class="patient-form" id="bookAppointmentForm">
                    <input type="hidden" name="action" value="book_appointment">
                    <input type="hidden" name="doctor_id" id="autoDoctorId" value="">

                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 0.75rem 1rem; border-radius: var(--radius-md); font-size: 0.8rem; color: #1e40af; margin-bottom: 0.5rem;">
                        &#128197; <strong>Capacity Policy:</strong> Slots are limited to 3-4 patients per doctor per hour. Fully booked hours will be disabled.
                    </div>

                    <div class="form-group">
                        <label for="deptSelect">Select Medical Department</label>
                        <select name="department" id="deptSelect" required>
                            <option value="">-- Choose a Department --</option>
                            <option value="Cardiology">Cardiology (Mon – Thu, 8am–4pm)</option>
                            <option value="Pediatrics">Pediatrics (Mon – Fri, 9am–3:30pm)</option>
                            <option value="Dermatology">Dermatology (Tue – Sat, 10am–5pm)</option>
                            <option value="General Medicine">General Medicine (Mon – Sat, 8am–5pm)</option>
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
                        <span class="auto-hint" id="autoHintText">The attending specialist will be assigned automatically based on clinic availability.</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="bookDate">Consultation Date</label>
                            <input 
                                type="date" 
                                name="appointment_date" 
                                id="bookDate" 
                                min="<?= $minDateOnly ?>" 
                                required
                            >
                            <span class="auto-hint" id="dateHint" style="color: #0369a1;">
                                Earliest: <strong><?= $minDateObj->format('M d, Y') ?></strong>
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="bookTime">Time Slot</label>
                            <select name="appointment_time" id="bookTime" required disabled>
                                <option value="">-- Select Date First --</option>
                            </select>
                            <span class="auto-hint" id="slotCapacityHint">Choose a date to check hourly availability.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="bookNotes">Reason for Visit</label>
                        <textarea name="reason" id="bookNotes" rows="2" placeholder="Briefly describe your symptoms or concern..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="patientAttachment">Medical Attachment (Optional)</label>
                        <input 
                            type="file" 
                            name="patient_attachment" 
                            id="patientAttachment" 
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.mp4,.webm"
                        >
                        <span class="auto-hint">Attach symptom photos, previous laboratory/ECG PDFs, or video clips (MP4/WebM).</span>
                    </div>

                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.8rem; color: #166534; display: flex; flex-direction: column; gap: 0.35rem;">
                        <div>
                            &#128179; <strong>Billing Notice:</strong> An initial consultation fee of <strong>₱500.00</strong> will be invoiced to your account upon booking.
                        </div>
                        <div style="font-size: 0.775rem; color: #15803d;">
                            &#9203; <em>Status: Your request will be recorded as <strong>Pending</strong> until approved by the attending specialist.</em>
                        </div>
                    </div>

                    <div class="modal-actions" style="margin-top: 1rem;">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Confirm & Book</button>
                        <button type="button" class="btn btn--outline" data-close="bookModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==========================================
         NEW MODAL: PAYMENT METHOD & RECEIPT UPLOAD
         ========================================== -->
    <div class="modal-overlay" id="paymentModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Select Payment Method & Upload Proof</h3>
                <button class="modal__close" type="button" id="closePaymentModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="patient.php" method="POST" enctype="multipart/form-data" class="patient-form">
                    <input type="hidden" name="action" value="submit_payment">
                    <input type="hidden" name="bill_id" id="payment_bill_id" value="">

                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.75rem; border-radius: var(--radius-md); font-size: 0.825rem; color: #166534; margin-bottom: 1rem;">
                        &#128197; <strong>Flexible Payment Policy:</strong> You can pay after your consultation. If you choose <strong>On-site Payment</strong>, your status will show as <em>Payment Pending (On-site verification)</em> until our staff verifies it at the clinic.
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select name="payment_method" id="payment_method" required onchange="toggleReceiptField(this.value)">
                            <option value="">-- Choose Payment Method --</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                            <option value="Card">Credit / Debit Card</option>
                            <option value="Onsite Payment">Onsite Payment (Pay at Clinic)</option>
                        </select>
                    </div>

                    <div class="form-group" id="receiptUploadGroup">
                        <label for="payment_receipt">Upload Proof of Receipt <span id="receiptRequiredStar" style="color:#dc2626;">*</span></label>
                        <input type="file" name="payment_receipt" id="payment_receipt" accept=".jpg,.jpeg,.png,.pdf">
                        <span class="auto-hint">Attach screenshot or photo of your transfer confirmation / transaction slip.</span>
                    </div>

                    <div class="modal-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Submit Payment Proof</button>
                        <button type="button" class="btn btn--outline" id="cancelPaymentModal">Cancel</button>
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
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.85rem 1rem; border-radius: var(--radius-md); font-size: 0.85rem; color: #166534; margin-bottom: 1.25rem;">
                    &#128197; <strong>Flexible Payment Policy:</strong> Payments can be made after your consultation date or hour. Pre-payment is completely optional.
                </div>

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
                                        <?php if (!empty($bill['payment_method'])): ?>
                                            <small style="display:block; color:var(--text-muted); font-size:0.75rem;">Via <?= htmlspecialchars($bill['payment_method']) ?></small>
                                        <?php endif; ?>
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
                                <div style="margin-top: 0.25rem;">
                                    <button type="button" onclick="openPaymentModal(<?= $bill['id'] ?>)" class="btn btn--primary" style="width: 100%; padding: 0.5rem; font-size: 0.85rem;">
                                        Pay Balance (₱<?= number_format($bill['total_amount'], 2) ?>)
                                    </button>
                                </div>
                            <?php elseif (strpos($bill['payment_status'], 'Pending') !== false): ?>
                                <span class="pill pill--pending" style="display:block; text-align:center; padding:0.4rem;">Receipt submitted. Awaiting Admin verification.</span>
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
        const minDateOnly = "<?= $minDateOnly ?>";

        const departmentRules = {
            'Cardiology': { days: [1, 2, 3, 4], label: 'Mon – Thu (08:00 AM – 04:00 PM)' },
            'Pediatrics': { days: [1, 2, 3, 4, 5], label: 'Mon – Fri (09:00 AM – 03:30 PM)' },
            'Dermatology': { days: [2, 3, 4, 5, 6], label: 'Tue – Sat (10:00 AM – 05:00 PM)' },
            'General Medicine': { days: [1, 2, 3, 4, 5, 6], label: 'Mon – Sat (08:00 AM – 05:00 PM)' }
        };

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

        const deptSelect = document.getElementById('deptSelect');
        const autoDoctorInput = document.getElementById('autoDoctorInput');
        const autoDoctorId = document.getElementById('autoDoctorId');
        const autoHintText = document.getElementById('autoHintText');
        const bookDateInput = document.getElementById('bookDate');
        const bookTimeSelect = document.getElementById('bookTime');
        const slotCapacityHint = document.getElementById('slotCapacityHint');

        if (deptSelect) {
            deptSelect.addEventListener('change', function() {
                const selectedDept = this.value;
                if (!selectedDept) {
                    autoDoctorInput.value = "Select a department above...";
                    autoDoctorId.value = "";
                    autoHintText.innerText = "The attending doctor will review and approve your requested appointment slot.";
                    return;
                }

                const matchingDoctors = registeredDoctors.filter(doc => 
                    doc.department && doc.department.toLowerCase() === selectedDept.toLowerCase()
                );

                if (matchingDoctors.length > 0) {
                    const randomIndex = Math.floor(Math.random() * matchingDoctors.length);
                    const selectedDoctor = matchingDoctors[randomIndex];

                    autoDoctorInput.value = selectedDoctor.full_name;
                    autoDoctorId.value = selectedDoctor.id;
                    autoHintText.innerHTML = `&#10003; <strong>${selectedDoctor.full_name}</strong> was assigned for your ${selectedDept} consultation.`;
                } else {
                    autoDoctorInput.value = `Attending Physician (${selectedDept})`;
                    autoDoctorId.value = "";
                    autoHintText.innerText = `An on-call specialist from ${selectedDept} will review your request.`;
                }

                if (bookDateInput.value) {
                    validatePickedDate(bookDateInput.value, selectedDept);
                }
            });
        }

        function validatePickedDate(dateVal, selectedDept) {
            const picked = new Date(dateVal + 'T00:00:00');
            const dayOfWeek = picked.getDay(); 

            if (dayOfWeek === 0) {
                alert("The outpatient clinic is closed on Sundays. Please select a day from Monday to Saturday.");
                bookDateInput.value = "";
                return;
            }

            if (selectedDept && departmentRules[selectedDept]) {
                const rule = departmentRules[selectedDept];
                const isoDay = dayOfWeek === 0 ? 7 : dayOfWeek;

                if (!rule.days.includes(isoDay)) {
                    alert(`Availability Notice: Doctors in ${selectedDept} are only available on: ${rule.label}. Please pick an active shift day.`);
                    bookDateInput.value = "";
                    return;
                }
            }
        }

        // Standard clinic consultation time slots
        const availableTimeSlots = [
            { val: "08:00:00", label: "08:00 AM" },
            { val: "09:00:00", label: "09:00 AM" },
            { val: "10:00:00", label: "10:00 AM" },
            { val: "11:00:00", label: "11:00 AM" },
            { val: "13:00:00", label: "01:00 PM" },
            { val: "14:00:00", label: "02:00 PM" },
            { val: "15:00:00", label: "03:00 PM" },
            { val: "16:00:00", label: "04:00 PM" }
        ];

        if (bookDateInput) {
            bookDateInput.addEventListener('change', async function() {
                const dateVal = this.value;
                if (!dateVal) return;

                validatePickedDate(dateVal, deptSelect.value);

                bookTimeSelect.disabled = false;
                slotCapacityHint.innerText = "Checking slot capacity...";

                // Clear dropdown and rebuild options
                bookTimeSelect.innerHTML = '<option value="">Select Time Slot...</option>';

                for (let slot of availableTimeSlots) {
                    const timeVal = slot.val;
                    const timeLabel = slot.label;
                    let isFull = false;
                    let bookedCount = 0;
                    let maxCap = 3;

                    try {
                        const res = await fetch(`patient.php?check_slot=1&date=${dateVal}&time=${timeVal}`);
                        const data = await res.json();
                        isFull = data.is_full;
                        bookedCount = data.booked;
                        maxCap = data.max;
                    } catch (e) {
                        console.error(e);
                    }

                    const opt = document.createElement('option');
                    opt.value = timeVal;

                    if (isFull) {
                        opt.disabled = true;
                        opt.text = `${timeLabel} — FULLY BOOKED (${bookedCount}/${maxCap})`;
                        opt.style.color = '#ef4444';
                    } else {
                        opt.disabled = false;
                        opt.text = `${timeLabel} (${bookedCount}/${maxCap} booked)`;
                        opt.style.color = '#15803d';
                    }
                    bookTimeSelect.appendChild(opt);
                }
                slotCapacityHint.innerText = "Time slots are limited";
            });
        }

        function formatTimeLabel(timeStr) {
            const [h, m] = timeStr.split(':');
            let hour = parseInt(h);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12 || 12;
            return `${hour}:${m} ${ampm}`;
        }

        // Payment Modal & Receipt Toggle
        function openPaymentModal(billId) {
            document.getElementById('payment_bill_id').value = billId;
            openModal('paymentModal');
        }

        function toggleReceiptField(method) {
            const uploadGroup = document.getElementById('receiptUploadGroup');
            const receiptInput = document.getElementById('payment_receipt');
            if (method === 'Onsite Payment') {
                uploadGroup.style.display = 'none';
                receiptInput.required = false;
            } else {
                uploadGroup.style.display = 'flex';
                receiptInput.required = true;
            }
        }

        document.getElementById('closePaymentModal').addEventListener('click', () => closeModal('paymentModal'));
        document.getElementById('cancelPaymentModal').addEventListener('click', () => closeModal('paymentModal'));

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

        function promptCancelAppointment(aptId, bookingCode, status) {
            document.getElementById('cancel_apt_id').value = aptId;
            document.getElementById('cancel_display_code').innerText = bookingCode;

            const noticeText = document.getElementById('cancelNoticeText');
            const submitBtn = document.getElementById('confirmCancelSubmitBtn');

            if (status === 'Confirmed') {
                noticeText.innerHTML = `Cancelling Reference: <span class="booking-code-badge">${bookingCode}</span><br>Because this slot was <strong>Confirmed by the physician</strong>, a <strong>₱50.00 reservation fee</strong> will be billed.`;
                submitBtn.innerText = "Confirm Cancellation (₱50 Fee)";
            } else {
                noticeText.innerHTML = `Cancelling Reference: <span class="booking-code-badge">${bookingCode}</span><br>This visit is still <strong>Pending Approval</strong>. It will be cancelled <strong>without any cancellation fee (₱0.00)</strong>.`;
                submitBtn.innerText = "Confirm Cancellation (No Fee)";
            }

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
                            <button type="button" onclick="promptCancelAppointment(${apt.id}, '${code}', '${apt.status}')" class="btn btn--outline" style="padding: 0.35rem 0.65rem; font-size: 0.725rem; color: #dc2626; border-color: #fca5a5;">
                                Cancel
                            </button>
                        `;
                    }

                    let statusBadgeClass = 'pill--ok';
                    if (apt.status === 'Pending') statusBadgeClass = 'pill--pending';
                    else if (isCancelled) statusBadgeClass = 'pill--neutral';

                    card.innerHTML = `
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem;">
                                <span class="booking-code-badge">${code}</span>
                                <span class="pill ${statusBadgeClass}" style="font-size: 0.7rem;">
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

    <script src="../scripts/session_timeout.js"></script>
</body>
</html>