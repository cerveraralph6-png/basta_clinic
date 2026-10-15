<?php
session_start();
require_once '../db.php';


if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'doctor') {
    header("Location: ../index.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];


$docUserStmt = $pdo->prepare("SELECT full_name, department, unavailable_count FROM users WHERE id = :id");
$docUserStmt->execute([':id' => $doctor_id]);
$docUser = $docUserStmt->fetch(PDO::FETCH_ASSOC);

$doctor_name = htmlspecialchars($docUser['full_name'] ?? $_SESSION['user_name'] ?? 'Dr. Michael Chen, MD');
$doctor_dept = htmlspecialchars($docUser['department'] ?? 'General Medicine');
$unavailable_count = intval($docUser['unavailable_count'] ?? 0);

$upload_msg = '';
$upload_error = '';




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_appointment') {
    $confirm_apt_id = intval($_POST['appointment_id'] ?? 0);

    $confirmStmt = $pdo->prepare("
        UPDATE appointments 
        SET status = 'Confirmed' 
        WHERE id = :id AND doctor_id = :doc_id
    ");
    $confirmStmt->execute([':id' => $confirm_apt_id, ':doc_id' => $doctor_id]);

    $aptData = $pdo->prepare("SELECT user_id, booking_code FROM appointments WHERE id = :id");
    $aptData->execute([':id' => $confirm_apt_id]);
    $aptRow = $aptData->fetch(PDO::FETCH_ASSOC);

    if ($aptRow) {
        $code = $aptRow['booking_code'] ?? ('#' . $confirm_apt_id);
        $notif = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (:uid, 'Appointment Confirmed', :msg, 'confirmed')
        ");
        $notif->execute([
            ':uid' => $aptRow['user_id'],
            ':msg' => "Dr. $doctor_name confirmed your consultation [$code]. We look forward to seeing you!"
        ]);

        $upload_msg = "Appointment [$code] was approved and confirmed!";
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pass_patient') {
    $apt_id = intval($_POST['appointment_id'] ?? 0);
    $unavail_reason = trim($_POST['unavail_reason'] ?? 'Doctor declared unavailable');

    $aptStmt = $pdo->prepare("SELECT * FROM appointments WHERE id = :id AND doctor_id = :doc_id");
    $aptStmt->execute([':id' => $apt_id, ':doc_id' => $doctor_id]);
    $appointment = $aptStmt->fetch(PDO::FETCH_ASSOC);

    if ($appointment) {
        try {
            $pdo->beginTransaction();

            $dept = $appointment['department'];
            $code = $appointment['booking_code'] ?? ('#' . $apt_id);

            
            $newDocStmt = $pdo->prepare("
                SELECT id, full_name FROM users 
                WHERE role = 'doctor' AND department = :dept AND id != :cur_id 
                LIMIT 1
            ");
            $newDocStmt->execute([':dept' => $dept, ':cur_id' => $doctor_id]);
            $newDoc = $newDocStmt->fetch(PDO::FETCH_ASSOC);

            
            if (!$newDoc) {
                $anyDocStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'doctor' AND id != :cur_id LIMIT 1");
                $anyDocStmt->execute([':cur_id' => $doctor_id]);
                $newDoc = $anyDocStmt->fetch(PDO::FETCH_ASSOC);
            }

            $new_doctor_id = $newDoc ? $newDoc['id'] : null;
            $new_doctor_name = $newDoc ? $newDoc['full_name'] : "On-Duty Clinical Staff ($dept)";

            
            $reassignStmt = $pdo->prepare("
                UPDATE appointments 
                SET doctor_id = :nid, doctor_name = :nname, status = 'Pending' 
                WHERE id = :id
            ");
            $reassignStmt->execute([
                ':nid'   => $new_doctor_id,
                ':nname' => $new_doctor_name,
                ':id'    => $apt_id
            ]);

            
            $incStmt = $pdo->prepare("UPDATE users SET unavailable_count = unavailable_count + 1 WHERE id = :id");
            $incStmt->execute([':id' => $doctor_id]);
            $unavailable_count++; 

            
            $logStmt = $pdo->prepare("
                INSERT INTO doctor_unavailability_logs (doctor_id, appointment_id, reassigned_doctor_id, reason)
                VALUES (:did, :aid, :rid, :reason)
            ");
            $logStmt->execute([
                ':did'    => $doctor_id,
                ':aid'    => $apt_id,
                ':rid'    => $new_doctor_id,
                ':reason' => $unavail_reason
            ]);

            
            $adminAlertStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                SELECT id, 'Doctor Unavailability Alert', :msg, 'comment'
                FROM users WHERE role = 'admin'
            ");
            $adminAlertMsg = "Incident Alert: $doctor_name declared unavailable for booking [$code]. Reassigned to $new_doctor_name. (Total Doctor Strikes: $unavailable_count). Reason: $unavail_reason";
            $adminAlertStmt->execute([':msg' => $adminAlertMsg]);

            
            $patNotif = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (:pid, 'Physician Reassigned', :msg, 'reminder')
            ");
            $patNotif->execute([
                ':pid' => $appointment['user_id'],
                ':msg' => "Dr. $doctor_name was unavailable for booking [$code]. You have been successfully reassigned to $new_doctor_name."
            ]);

            
            if ($new_doctor_id) {
                $newDocNotif = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (:did, 'Reassigned Patient Received', :msg, 'reminder')
                ");
                $newDocNotif->execute([
                    ':did' => $new_doctor_id,
                    ':msg' => "Patient consultation [$code] was transferred to your queue from $doctor_name."
                ]);
            }

            $pdo->commit();
            $upload_msg = "Patient [$code] was passed to $new_doctor_name. Your unavailability incident was recorded (Total: $unavailable_count).";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $upload_error = "Error transferring patient: " . $e->getMessage();
        }
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_record') {
    $patient_id      = intval($_POST['patient_id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $diagnosis_notes = trim($_POST['diagnosis_notes'] ?? '');

    if ($patient_id <= 0 || empty($title) || !isset($_FILES['medical_file']) || $_FILES['medical_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = "Please fill in all fields and select a valid document to upload.";
    } else {
        $file = $_FILES['medical_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed)) {
            $upload_error = "Only PDF, DOCX, and Image files (JPG/PNG) are allowed.";
        } else {
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'record_' . uniqid() . '.' . $ext;
            $destination = $uploadDir . $newFileName;
            $dbPath = 'uploads/' . $newFileName;
            $fileSize = round($file['size'] / 1024, 1) . ' KB';

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $stmt = $pdo->prepare("
                    INSERT INTO medical_records (patient_id, doctor_id, title, diagnosis_notes, file_name, file_path, file_size)
                    VALUES (:pid, :did, :title, :notes, :fname, :fpath, :fsize)
                ");
                $stmt->execute([
                    ':pid'   => $patient_id,
                    ':did'   => $doctor_id,
                    ':title' => $title,
                    ':notes' => $diagnosis_notes,
                    ':fname' => $file['name'],
                    ':fpath' => $dbPath,
                    ':fsize' => $fileSize
                ]);

                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (:pid, 'New Medical Document Available', :msg, 'confirmed')
                ");
                $notifStmt->execute([
                    ':pid' => $patient_id,
                    ':msg' => "$doctor_name uploaded a new record: $title. You can now download it under Diagnosis & Records."
                ]);

                $upload_msg = "Document '$title' uploaded successfully and sent to patient!";
            } else {
                $upload_error = "Failed to save file to server. Check upload folder permissions.";
            }
        }
    }
}


$patientListStmt = $pdo->query("SELECT id, full_name, identifier FROM users WHERE role = 'patient' ORDER BY full_name ASC");
$patients = $patientListStmt->fetchAll(PDO::FETCH_ASSOC);


$docNotifStmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = :doctor_id 
    ORDER BY created_at DESC LIMIT 6
");
$docNotifStmt->execute([':doctor_id' => $doctor_id]);
$doctorNotifications = $docNotifStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadDocCount = 0;
foreach ($doctorNotifications as $dn) {
    if ($dn['is_read'] == 0) $unreadDocCount++;
}


$queueStmt = $pdo->prepare("
    SELECT a.*, u.full_name AS patient_name, u.phone_number 
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    WHERE a.doctor_id = :doctor_id AND a.status != 'Cancelled'
    ORDER BY a.appointment_datetime ASC
");
$queueStmt->execute([':doctor_id' => $doctor_id]);
$doctorQueue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);


$nextPatient = null;
$now = date('Y-m-d H:i:s');
foreach ($doctorQueue as $q) {
    if ($q['appointment_datetime'] >= $now) {
        $nextPatient = $q;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Physician Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/doctor.css">


    <style>
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius-md, 8px); font-size: 0.875rem; margin-bottom: 1.5rem; }
        .alert--error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert--success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        
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
        .pill--danger { background-color: #fee2e2; color: #dc2626; }

       
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
        }
        .notif-icon--cancel { background: #fee2e2; color: #dc2626; }
        .notif-icon--info { background: #e0f2fe; color: #0284c7; }
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
            <span class="doctor-badge">Physician Portal</span>
        </div>
        
        <div class="navbar__actions" style="display: flex; align-items: center; gap: 1.25rem;">
            
            <!-- DOCTOR NOTIFICATION BELL -->
            <div class="notif-wrapper">
                <button type="button" class="notif-btn" id="docNotifBtn" aria-label="Notifications">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unreadDocCount > 0): ?>
                        <span class="notif-badge"><?= $unreadDocCount ?></span>
                    <?php endif; ?>
                </button>

                <div class="notif-dropdown" id="docNotifDropdown">
                    <div class="notif-header">
                        <h4>Clinic Alerts</h4>
                        <span style="font-size: 0.75rem; color: #dc2626; font-weight: 600;"><?= $unreadDocCount ?> unread</span>
                    </div>

                    <div class="notif-list">
                        <?php if (count($doctorNotifications) > 0): ?>
                            <?php foreach ($doctorNotifications as $dNotif): ?>
                                <div class="notif-item <?= $dNotif['is_read'] == 0 ? 'unread' : '' ?>">
                                    <div class="notif-icon <?= strpos($dNotif['title'], 'Cancel') !== false ? 'notif-icon--cancel' : 'notif-icon--info' ?>">
                                        <?php if (strpos($dNotif['title'], 'Cancel') !== false): ?>
                                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                            </svg>
                                        <?php else: ?>
                                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notif-content">
                                        <h5><?= htmlspecialchars($dNotif['title']) ?></h5>
                                        <p><?= htmlspecialchars($dNotif['message']) ?></p>
                                        <small><?= date('M d, h:i A', strtotime($dNotif['created_at'])) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                                No clinic alerts or cancellations.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="doctor-profile">
                <strong><?= $doctor_name ?></strong>
                <small><?= $doctor_dept ?> &bull; Active Duty</small>
            </div>
            <a href="../logout.php" class="btn btn--outline">Sign Out</a>
        </div>
    </header>

    <main class="dashboard">
        <div class="dashboard__banner">
            <div>
                <h2>Good Day, <?= $doctor_name ?></h2>
                <p>You have <strong><?= count($doctorQueue) ?> consultation(s)</strong> assigned to your clinic schedule.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <div class="banner-status">
                    <span class="status-indicator"></span>
                    <?php if ($nextPatient): ?>
                        <span>Next: <strong><?= htmlspecialchars($nextPatient['patient_name']) ?></strong> (<?= date('h:i A', strtotime($nextPatient['appointment_datetime'])) ?>)</span>
                    <?php else: ?>
                        <span>No upcoming patients.</span>
                    <?php endif; ?>
                </div>

                <!-- UNAVAILABILITY STRIKE COUNTER BADGE -->
                <div style="background: <?= $unavailable_count > 0 ? '#fee2e2' : '#f1f5f9' ?>; border: 1px solid <?= $unavailable_count > 0 ? '#fca5a5' : '#cbd5e1' ?>; padding: 0.55rem 0.85rem; border-radius: var(--radius-md); font-size: 0.8rem; color: <?= $unavailable_count > 0 ? '#991b1b' : '#475569' ?>;">
                    &#9888; <strong>Unavailability Counter:</strong> <?= $unavailable_count ?> Incident(s)
                </div>
            </div>
        </div>

        <?php if (!empty($upload_msg)): ?>
            <div class="alert alert--success"><?= htmlspecialchars($upload_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($upload_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($upload_error) ?></div>
        <?php endif; ?>

        <div class="dashboard__grid">

            <!-- Card 1: Today's Queue -->
            <div class="card" id="openQueueModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Today's Patient Queue</h3>
                    <p class="card__desc">Review checked-in patients, approve consultation slots, or pass patients if unavailable.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok"><?= count($doctorQueue) ?> Assigned Patient(s)</span>
                    </div>
                </div>
                <span class="card__link">Open Patient Queue &rarr;</span>
            </div>

            <!-- Card 2: Upload Document & Diagnosis -->
            <div class="card" id="openUploadModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                    </div>
                    <h3 class="card__title">Upload Diagnosis / Lab</h3>
                    <p class="card__desc">Upload diagnostic lab results, imaging scans, and formal diagnosis letters for patient download.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">+ Attach Patient Files</span>
                    </div>
                </div>
                <span class="card__link">Upload Medical Record &rarr;</span>
            </div>

            <!-- Card 3: e-Prescriptions -->
            <div class="card" id="openRxModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--purple">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                        </svg>
                    </div>
                    <h3 class="card__title">e-Prescriptions</h3>
                    <p class="card__desc">Issue digital prescriptions directly to the hospital pharmacy.</p>
                </div>
                <span class="card__link">Issue Prescription &rarr;</span>
            </div>

            <!-- Card 4: Working Hours -->
            <div class="card" id="openScheduleModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--amber">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Availability & Shifts</h3>
                    <p class="card__desc">Adjust your clinical consultation hours, mark on-call duty, or block calendar time.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--neutral">Dept: <?= $doctor_dept ?></span>
                    </div>
                </div>
                <span class="card__link">Manage Clinic Hours &rarr;</span>
            </div>

        </div>
    </main>

    <!-- ==========================================
         MODAL 1: REAL PATIENT CONSULTATION QUEUE
         ========================================== -->
    <div class="modal-overlay" id="queueModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>My Patient Queue (<?= $doctor_dept ?>)</h3>
                <button class="modal__close" data-close="queueModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="list-container">
                    <?php if (count($doctorQueue) > 0): ?>
                        <?php foreach ($doctorQueue as $queue): ?>
                            <div class="list-item" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1rem; border-bottom:1px solid var(--border-subtle); flex-wrap:wrap;">
                                <div>
                                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                                        <span class="booking-code-badge"><?= htmlspecialchars($queue['booking_code'] ?? '#'.$queue['id']) ?></span>
                                        <span class="pill <?= $queue['status'] === 'Confirmed' ? 'pill--ok' : 'pill--pending' ?>">
                                            <?= htmlspecialchars($queue['status']) ?>
                                        </span>
                                    </div>
                                    <h4 style="margin: 0.2rem 0;"><?= htmlspecialchars($queue['patient_name']) ?> &bull; <?= htmlspecialchars($queue['phone_number']) ?></h4>
                                    <p style="font-size:0.8rem; color:var(--text-muted); margin:0;">
                                        Time: <strong><?= date('M d, Y h:i A', strtotime($queue['appointment_datetime'])) ?></strong>
                                    </p>
                                    <?php if (!empty($queue['reason'])): ?>
                                        <p style="font-size:0.775rem; color:var(--text-body); margin-top:0.25rem;">
                                            Reason: <em><?= htmlspecialchars($queue['reason']) ?></em>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!empty($queue['attachment_path'])): ?>
                                        <div style="margin-top: 0.4rem;">
                                            <a href="../<?= htmlspecialchars($queue['attachment_path']) ?>" target="_blank" class="btn btn--outline" style="padding: 0.25rem 0.6rem; font-size: 0.725rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                                                &#128206; View Attached Patient File (<?= htmlspecialchars($queue['attachment_name']) ?>)
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <?php if ($queue['status'] === 'Pending'): ?>
                                        <!-- 1. Approve Button -->
                                        <form action="doctor.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="confirm_appointment">
                                            <input type="hidden" name="appointment_id" value="<?= $queue['id'] ?>">
                                            <button type="submit" class="btn btn--primary" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; white-space: nowrap;">
                                                &#10003; Approve
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="pill pill--ok" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                                            &#10003; Approved
                                        </span>
                                    <?php endif; ?>

                                    <!-- 2. UNAVAILABLE / PASS BUTTON -->
                                    <button 
                                        type="button" 
                                        onclick="promptDoctorUnavailable(<?= $queue['id'] ?>, '<?= htmlspecialchars($queue['booking_code'] ?? '#'.$queue['id']) ?>', '<?= htmlspecialchars(addslashes($queue['patient_name'])) ?>')" 
                                        class="btn btn--outline" 
                                        style="padding: 0.45rem 0.75rem; font-size: 0.8rem; color: #dc2626; border-color: #fca5a5; white-space: nowrap;"
                                    >
                                        Unavailable / Pass
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 2.5rem 0; font-size: 0.9rem;">
                            No patients currently in your consultation queue.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button class="btn btn--outline" data-close="queueModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         MODAL: DOCTOR UNAVAILABLE CONFIRMATION
         ========================================== -->
    <div class="modal-overlay" id="unavailModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Doctor Unavailability & Patient Reassignment</h3>
                <button class="modal__close" data-close="unavailModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="doctor.php" method="POST" class="doctor-form">
                    <input type="hidden" name="action" value="pass_patient">
                    <input type="hidden" name="appointment_id" id="unavail_apt_id" value="">

                    <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.825rem; color: #92400e; margin-bottom: 1rem;">
                        <strong>&#9888; Official Clinic Audit Notice:</strong>
                        <p style="margin: 0.35rem 0 0 0;">
                            Patient: <strong id="unavail_patient_name">Patient</strong> (<span class="booking-code-badge" id="unavail_booking_code">HSP-0000</span>)<br>
                            Declaring unavailability will <strong>increment your strike counter</strong> and automatically <strong>dispatch an incident report to Clinic Administration</strong>.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="unavail_reason">Reason for Unavailability (Mandatory for Audit)</label>
                        <select name="unavail_reason" id="unavail_reason" required>
                            <option value="Emergency Procedure / Surgery">Emergency Procedure / Surgery</option>
                            <option value="Physician Medical Leave / Sickness">Physician Medical Leave / Sickness</option>
                            <option value="Clinic Schedule Overrun">Clinic Schedule Overrun</option>
                            <option value="Personal Emergency">Personal Emergency</option>
                        </select>
                    </div>

                    <div class="modal-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn--primary" style="background:#dc2626; flex:1;">
                            Confirm & Pass Patient
                        </button>
                        <button type="button" class="btn btn--outline" data-close="unavailModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: UPLOAD DIAGNOSIS / MEDICAL RECORD -->
    <div class="modal-overlay" id="uploadModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Upload Patient Medical Document</h3>
                <button class="modal__close" data-close="uploadModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="doctor.php" method="POST" enctype="multipart/form-data" class="doctor-form">
                    <input type="hidden" name="action" value="upload_record">

                    <div class="form-group">
                        <label for="patient_id">Select Patient</label>
                        <select name="patient_id" id="patient_id" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['full_name']) ?> (<?= htmlspecialchars($p['identifier']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="title">Document Title / Clinical Test Name</label>
                        <input type="text" name="title" id="title" placeholder="e.g. 12-Lead ECG Analysis Report" required>
                    </div>

                    <div class="form-group">
                        <label for="diagnosis_notes">Clinical Findings & Doctor Notes</label>
                        <textarea name="diagnosis_notes" id="diagnosis_notes" rows="3" placeholder="Enter diagnosis summary, patient recommendations, or treatment instructions..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="medical_file">Attach File (PDF, DOCX, JPG, PNG)</label>
                        <input type="file" name="medical_file" id="medical_file" required>
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Upload & Send to Patient</button>
                        <button type="button" class="btn btn--outline" data-close="uploadModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 3: PRESCRIPTION -->
    <div class="modal-overlay" id="rxModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Issue Prescription</h3>
                <button class="modal__close" data-close="rxModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="text-align:center; color: var(--text-muted); padding: 1.5rem 0;">Hospital Pharmacy interface active.</p>
                <div class="modal-actions"><button class="btn btn--outline" data-close="rxModal">Close</button></div>
            </div>
        </div>
    </div>

    <!-- MODAL 4: SHIFT SCHEDULE -->
    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Shift Schedule</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="text-align:center; color: var(--text-muted); padding: 1.5rem 0;">Regular Hours: 08:00 AM – 04:00 PM (<?= $doctor_dept ?>)</p>
                <div class="modal-actions"><button class="btn btn--outline" data-close="scheduleModal">Close</button></div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Physician Portal</p>
    </footer>

    <!-- Interactive Scripts -->
    <script>
        const docNotifBtn = document.getElementById('docNotifBtn');
        const docNotifDropdown = document.getElementById('docNotifDropdown');

        if (docNotifBtn && docNotifDropdown) {
            docNotifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                docNotifDropdown.classList.toggle('active');
            });

            document.addEventListener('click', (e) => {
                if (!docNotifDropdown.contains(e.target) && e.target !== docNotifBtn) {
                    docNotifDropdown.classList.remove('active');
                }
            });
        }

        
        function promptDoctorUnavailable(aptId, bookingCode, patientName) {
            document.getElementById('unavail_apt_id').value = aptId;
            document.getElementById('unavail_booking_code').innerText = bookingCode;
            document.getElementById('unavail_patient_name').innerText = patientName;
            openModal('unavailModal');
        }

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

        document.getElementById('openUploadModal').addEventListener('click', () => openModal('uploadModal'));
        document.getElementById('openQueueModal').addEventListener('click', () => openModal('queueModal'));
        document.getElementById('openRxModal').addEventListener('click', () => openModal('rxModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => openModal('scheduleModal'));

        document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close'))));
        document.querySelectorAll('.modal-overlay').forEach(overlay => overlay.addEventListener('click', (e) => { if(e.target === overlay) closeModal(overlay.id); }));
    </script>
</body>
</html>