<?php
session_start();
require_once '../db.php';


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

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
$rx_msg = '';
$rx_error = '';
$auto_open_rx_code = '';

/**
 * Native, standalone PDF Generator for Hosplinic e-Prescriptions
 * Does not require external Composer libraries.
 */
class PrescriptionPDF
{
    private function escapePdfText($str) {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace('(', '\\(', $str);
        $str = str_replace(')', '\\)', $str);
        return $str;
    }

    public function generate($filePath, $data, $sigJpegData, $sigWidth, $sigHeight)
    {
        $w = 595.28; 
        $h = 841.89; 

        $commands = [];

        $commands[] = "0.75 w 0.2 0.4 0.6 RG";
        $commands[] = "30 30 535.28 781.89 re S";
        
        $commands[] = "0.5 w 0.7 0.7 0.7 RG";
        $commands[] = "35 35 525.28 771.89 re S";

        $commands[] = "0.12 0.35 0.65 rg 40 735 515.28 60 re f";

        $commands[] = "BT /F2 20 Tf 1 1 1 rg 55 765 Td (HOSPLINIC MEDICAL CENTER) Tj ET";
        $commands[] = "BT /F1 9 Tf 0.9 0.9 0.9 rg 55 747 Td (Official Clinical Digital Prescription - Electronic Health Record Service) Tj ET";

        $commands[] = "0.95 0.96 0.98 rg 40 660 515.28 65 re f";
        $commands[] = "0.85 0.85 0.85 RG 40 660 515.28 65 re S";

        $commands[] = "BT /F2 11 Tf 0.1 0.1 0.1 rg 55 705 Td (" . $this->escapePdfText("Attending Physician: " . $data['doctor_name']) . ") Tj ET";
        $commands[] = "BT /F1 9 Tf 0.3 0.3 0.3 rg 55 690 Td (" . $this->escapePdfText("Specialty / Department: " . $data['department']) . ") Tj ET";
        $commands[] = "BT /F1 9 Tf 0.3 0.3 0.3 rg 55 673 Td (" . $this->escapePdfText("Issuance Date: " . date('F d, Y - h:i A')) . ") Tj ET";

        $commands[] = "BT /F2 10 Tf 0.1 0.1 0.1 rg 340 705 Td (" . $this->escapePdfText("Booking Code: " . $data['booking_code']) . ") Tj ET";
        $commands[] = "BT /F1 9 Tf 0.3 0.3 0.3 rg 340 690 Td (" . $this->escapePdfText("Patient: " . $data['patient_name']) . ") Tj ET";
        $commands[] = "BT /F1 9 Tf 0.3 0.3 0.3 rg 340 673 Td (Status: Verified Digital Rx) Tj ET";

        $commands[] = "BT /F2 40 Tf 0.12 0.35 0.65 rg 55 595 Td (Rx) Tj ET";
        $commands[] = "0.8 0.8 0.8 RG 55 580 485 0 m 55 580 l S";

        $commands[] = "BT /F2 13 Tf 0.1 0.15 0.25 rg 55 550 Td (" . $this->escapePdfText("Medication: " . $data['medication']) . ") Tj ET";
        $commands[] = "BT /F2 10 Tf 0.25 0.25 0.25 rg 55 515 Td (Directions & Dosage Instructions:) Tj ET";

        $lines = explode("\n", wordwrap($data['instructions'], 75, "\n", true));
        $y = 495;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $commands[] = "BT /F1 10 Tf 0.15 0.15 0.15 rg 65 " . $y . " Td (" . $this->escapePdfText($trimmed) . ") Tj ET";
                $y -= 16;
            }
        }

        $commands[] = "1 0.98 0.92 rg 55 240 485 35 re f";
        $commands[] = "0.95 0.8 0.5 RG 55 240 485 35 re S";
        $commands[] = "BT /F1 8 Tf 0.5 0.35 0.1 rg 65 258 Td (Notice: This is a digitally verified e-Prescription. Dispense only through licensed pharmacies.) Tj ET";
        $commands[] = "BT /F1 8 Tf 0.5 0.35 0.1 rg 65 246 Td (One-time digital authorization applied by attending physician.) Tj ET";

        $commands[] = "0.8 0.8 0.8 RG 320 160 220 0 m 540 160 l S";
        $commands[] = "BT /F2 10 Tf 0.1 0.1 0.1 rg 340 144 Td (" . $this->escapePdfText("Dr. " . $data['doctor_name']) . ") Tj ET";
        $commands[] = "BT /F1 8.5 Tf 0.4 0.4 0.4 rg 340 131 Td (" . $this->escapePdfText($data['department'] . " - Attending Physician") . ") Tj ET";
        $commands[] = "BT /F1 7.5 Tf 0.55 0.55 0.55 rg 340 118 Td (Digital One-Time Authenticated Signature) Tj ET";

        if ($sigJpegData) {
            $imgWidth = 140;
            $imgHeight = round($imgWidth * ($sigHeight / max($sigWidth, 1)));
            if ($imgHeight > 55) $imgHeight = 55;
            $sigX = 350;
            $sigY = 163;
            $commands[] = "q " . $imgWidth . " 0 0 " . $imgHeight . " " . $sigX . " " . $sigY . " cm /SigImg Do Q";
        }

        $commands[] = "BT /F1 8 Tf 0.6 0.6 0.6 rg 170 45 Td (Hosplinic Management System - Secure Healthcare Record) Tj ET";

        $contentStream = implode("\n", $commands);
        $contentLen = strlen($contentStream);

        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        
        $resXObj = $sigJpegData ? "/XObject << /SigImg 8 0 R >>" : "";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Contents 4 0 R /Resources << /Font << /F1 6 0 R /F2 7 0 R >> $resXObj >> >>";
        $objs[4] = "<< /Length $contentLen >>\nstream\n" . $contentStream . "\nendstream";
        $objs[5] = "";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objs[7] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        if ($sigJpegData) {
            $sigLen = strlen($sigJpegData);
            $objs[8] = "<< /Type /XObject /Subtype /Image /Width $sigWidth /Height $sigHeight /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $sigLen >>\nstream\n" . $sigJpegData . "\nendstream";
        }

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $id => $obj) {
            if (empty($obj)) continue;
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n" . $obj . "\nendobj\n";
        }

        $xrefStart = strlen($out);
        $maxId = max(array_keys($objs));
        $out .= "xref\n0 " . ($maxId + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($offsets[$i])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }

        $out .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $out .= "startxref\n" . $xrefStart . "\n%%EOF";

        return file_put_contents($filePath, $out) !== false;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_appointment') {
    $confirm_apt_id = intval($_POST['appointment_id'] ?? 0);

    $confirmStmt = $pdo->prepare("UPDATE appointments SET status = 'Confirmed' WHERE id = :id AND doctor_id = :doc_id");
    $confirmStmt->execute([':id' => $confirm_apt_id, ':doc_id' => $doctor_id]);

    $aptData = $pdo->prepare("SELECT user_id, booking_code FROM appointments WHERE id = :id");
    $aptData->execute([':id' => $confirm_apt_id]);
    $aptRow = $aptData->fetch(PDO::FETCH_ASSOC);

    if ($aptRow) {
        $code = $aptRow['booking_code'] ?? ('#' . $confirm_apt_id);
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:uid, 'Appointment Confirmed', :msg, 'confirmed')");
        $notif->execute([
            ':uid' => $aptRow['user_id'],
            ':msg' => "Dr. $doctor_name confirmed your consultation [$code]. We look forward to seeing you!"
        ]);
        $upload_msg = "Appointment [$code] was approved and confirmed!";
        $upload_msg = "Appointment [$code] was approved and confirmed!";
        $auto_open_queue_modal = true;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_appointment') {
    $complete_apt_id = intval($_POST['appointment_id'] ?? 0);

    $finishStmt = $pdo->prepare("
        UPDATE appointments 
        SET status = 'Completed' 
        WHERE id = :id AND doctor_id = :doc_id
    ");
    $finishStmt->execute([':id' => $complete_apt_id, ':doc_id' => $doctor_id]);

    $aptData = $pdo->prepare("SELECT user_id, booking_code FROM appointments WHERE id = :id");
    $aptData->execute([':id' => $complete_apt_id]);
    $aptRow = $aptData->fetch(PDO::FETCH_ASSOC);

    if ($aptRow) {
        $code = $aptRow['booking_code'] ?? ('#' . $complete_apt_id);
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:uid, 'Consultation Completed', :msg, 'confirmed')");
        $notif->execute([
            ':uid' => $aptRow['user_id'],
            ':msg' => "Dr. $doctor_name has concluded your consultation [$code]. Your medical files and summaries are now ready in your records."
        ]);
        $upload_msg = "Consultation [$code] was marked as Completed and moved to patient history.";
        $auto_open_queue_modal = true;
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

            $newDocStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'doctor' AND department = :dept AND id != :cur_id LIMIT 1");
            $newDocStmt->execute([':dept' => $dept, ':cur_id' => $doctor_id]);
            $newDoc = $newDocStmt->fetch(PDO::FETCH_ASSOC);

            if (!$newDoc) {
                $anyDocStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'doctor' AND id != :cur_id LIMIT 1");
                $anyDocStmt->execute([':cur_id' => $doctor_id]);
                $newDoc = $anyDocStmt->fetch(PDO::FETCH_ASSOC);
            }

            $new_doctor_id = $newDoc ? $newDoc['id'] : null;
            $new_doctor_name = $newDoc ? $newDoc['full_name'] : "On-Duty Clinical Staff ($dept)";

            $reassignStmt = $pdo->prepare("UPDATE appointments SET doctor_id = :nid, doctor_name = :nname, status = 'Pending' WHERE id = :id");
            $reassignStmt->execute([
                ':nid'   => $new_doctor_id,
                ':nname' => $new_doctor_name,
                ':id'    => $apt_id
            ]);

            $incStmt = $pdo->prepare("UPDATE users SET unavailable_count = unavailable_count + 1 WHERE id = :id");
            $incStmt->execute([':id' => $doctor_id]);
            $unavailable_count++;

            $logStmt = $pdo->prepare("INSERT INTO doctor_unavailability_logs (doctor_id, appointment_id, reassigned_doctor_id, reason) VALUES (:did, :aid, :rid, :reason)");
            $logStmt->execute([
                ':did'    => $doctor_id,
                ':aid'    => $apt_id,
                ':rid'    => $new_doctor_id,
                ':reason' => $unavail_reason
            ]);

            $adminAlertStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) SELECT id, 'Doctor Unavailability Alert', :msg, 'comment' FROM users WHERE role = 'admin'");
            $adminAlertMsg = "Incident Alert: $doctor_name declared unavailable for booking [$code]. Reassigned to $new_doctor_name. (Total Strikes: $unavailable_count). Reason: $unavail_reason";
            $adminAlertStmt->execute([':msg' => $adminAlertMsg]);

            $patNotif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:pid, 'Physician Reassigned', :msg, 'reminder')");
            $patNotif->execute([
                ':pid' => $appointment['user_id'],
                ':msg' => "Dr. $doctor_name was unavailable for booking [$code]. You have been successfully reassigned to $new_doctor_name."
            ]);

            $pdo->commit();
            $upload_msg = "Patient [$code] was passed to $new_doctor_name. Incident recorded.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $upload_error = "Error transferring patient: " . $e->getMessage();
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_record') {
    $booking_code    = trim($_POST['booking_code'] ?? '');
    $title           = trim($_POST['title'] ?? '');
    $diagnosis_notes = trim($_POST['diagnosis_notes'] ?? '');
    $proceed_to_rx   = isset($_POST['proceed_to_rx']);

    $aptLookup = $pdo->prepare("SELECT user_id, id FROM appointments WHERE booking_code = :bcode LIMIT 1");
    $aptLookup->execute([':bcode' => $booking_code]);
    $aptFound = $aptLookup->fetch(PDO::FETCH_ASSOC);

    if (!$aptFound) {
        $upload_error = "Invalid or non-existent Booking Reference Code ($booking_code).";
    } elseif (empty($title) || !isset($_FILES['medical_file']) || $_FILES['medical_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = "Please provide a document title and attach a valid file.";
    } else {
        $patient_id = $aptFound['user_id'];
        $file = $_FILES['medical_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed)) {
            $upload_error = "Only PDF, DOCX, and Image files (JPG/PNG) are allowed.";
        } else {
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

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

                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:pid, 'New Medical Document Available', :msg, 'confirmed')");
                $notifStmt->execute([
                    ':pid' => $patient_id,
                    ':msg' => "$doctor_name uploaded a new record for [$booking_code]: $title. Download it under Diagnosis & Records."
                ]);

                $upload_msg = "Document '$title' successfully uploaded for patient ($booking_code)!";

                if ($proceed_to_rx) {
                    $auto_open_rx_code = $booking_code;
                }
            } else {
                $upload_error = "Failed to save file to server.";
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_prescription') {
    $booking_code        = trim($_POST['rx_booking_code'] ?? '');
    $medication_name     = trim($_POST['medication_name'] ?? '');
    $dosage_instructions = trim($_POST['dosage_instructions'] ?? '');
    $signature_data      = trim($_POST['signature_data'] ?? '');

    $aptLookup = $pdo->prepare("
        SELECT a.id, a.user_id, u.full_name AS patient_name 
        FROM appointments a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.booking_code = :bcode LIMIT 1
    ");
    $aptLookup->execute([':bcode' => $booking_code]);
    $aptFound = $aptLookup->fetch(PDO::FETCH_ASSOC);

    if (!$aptFound) {
        $rx_error = "Invalid Booking Reference Code ($booking_code).";
    } elseif (empty($medication_name) || empty($dosage_instructions)) {
        $rx_error = "Medication name and dosage instructions are required.";
    } elseif (empty($signature_data) || strpos($signature_data, 'data:image/') === false) {
        $rx_error = "Please provide your one-time digital signature before issuing the prescription.";
    } else {
        $patient_id = $aptFound['user_id'];
        $patient_name = $aptFound['patient_name'];
        $appointment_id = $aptFound['id'];

        try {
            $pdo->beginTransaction();

            $rxStmt = $pdo->prepare("
                INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, medication_name, dosage_instructions)
                VALUES (:pid, :did, :aid, :mname, :instructions)
            ");
            $rxStmt->execute([
                ':pid'          => $patient_id,
                ':did'          => $doctor_id,
                ':aid'          => $appointment_id,
                ':mname'        => $medication_name,
                ':instructions' => $dosage_instructions
            ]);

            
            $completeAptStmt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'Completed' 
                WHERE id = :aid AND doctor_id = :did
            ");
            $completeAptStmt->execute([':aid' => $appointment_id, ':did' => $doctor_id]);

            $sigJpegData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signature_data));
            $sigW = 460;
            $sigH = 130;

            if (function_exists('getimagesizefromstring') && !empty($sigJpegData)) {
                $imgInfo = @getimagesizefromstring($sigJpegData);
                if ($imgInfo && !empty($imgInfo[0]) && !empty($imgInfo[1])) {
                    $sigW = $imgInfo[0];
                    $sigH = $imgInfo[1];
                }
            }

            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = 'prescription_' . uniqid() . '.pdf';
            $filePath = $uploadDir . $fileName;
            $dbFilePath = 'uploads/' . $fileName;

            $pdfGen = new PrescriptionPDF();
            $pdfSuccess = $pdfGen->generate($filePath, [
                'doctor_name'  => $doctor_name,
                'department'   => $doctor_dept,
                'patient_name' => $patient_name,
                'booking_code' => $booking_code,
                'medication'   => $medication_name,
                'instructions' => $dosage_instructions
            ], $sigJpegData, $sigW, $sigH);

            if (!$pdfSuccess) {
                throw new Exception("Unable to compile e-Prescription PDF.");
            }

            $fileSize = round(filesize($filePath) / 1024, 1) . ' KB';
            $rxTitle = "e-Prescription: " . $medication_name;
            $rxNotes = "Digitally signed by $doctor_name on " . date('Y-m-d H:i') . ".\nRx: $medication_name\nInstructions: $dosage_instructions";

            $recStmt = $pdo->prepare("
                INSERT INTO medical_records (patient_id, doctor_id, title, diagnosis_notes, file_name, file_path, file_size)
                VALUES (:pid, :did, :title, :notes, :fname, :fpath, :fsize)
            ");
            $recStmt->execute([
                ':pid'   => $patient_id,
                ':did'   => $doctor_id,
                ':title' => $rxTitle,
                ':notes' => $rxNotes,
                ':fname' => $fileName,
                ':fpath' => $dbFilePath,
                ':fsize' => $fileSize
            ]);

            $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (:pid, 'New Signed e-Prescription Available', :msg, 'confirmed')");
            $notif->execute([
                ':pid' => $patient_id,
                ':msg' => "Dr. $doctor_name issued an official signed PDF prescription for [$booking_code]: $medication_name. You can view or download it under Diagnosis & Records."
            ]);

            $pdo->commit();
            $rx_msg = "e-Prescription PDF with your one-time digital signature was generated and sent to patient ($booking_code)!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $rx_error = "Error generating prescription: " . $e->getMessage();
        }
    }
}


$docNotifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :doctor_id ORDER BY created_at DESC LIMIT 6");
$docNotifStmt->execute([':doctor_id' => $doctor_id]);
$doctorNotifications = $docNotifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadDocCount = count(array_filter($doctorNotifications, fn($dn) => $dn['is_read'] == 0));


$queueStmt = $pdo->prepare("
    SELECT a.*, u.full_name AS patient_name, u.phone_number 
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    
    WHERE a.doctor_id = :doctor_id AND a.status NOT IN ('Cancelled', 'Completed')
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




$historyStmt = $pdo->prepare("
    SELECT a.*, 
           u.full_name AS patient_name, 
           u.phone_number,
           u.identifier AS patient_email
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    
    WHERE a.doctor_id = :doctor_id AND a.status = 'Completed'
    ORDER BY a.appointment_datetime DESC
");
$historyStmt->execute([':doctor_id' => $doctor_id]);
$completedAppointments = $historyStmt->fetchAll(PDO::FETCH_ASSOC);


foreach ($completedAppointments as &$apt) {
    $recQuery = $pdo->prepare("
        SELECT id, title, diagnosis_notes, file_name, file_path, file_size, created_at 
        FROM medical_records 
        WHERE patient_id = :pid AND doctor_id = :did 
        ORDER BY created_at DESC
    ");
    $recQuery->execute([':pid' => $apt['user_id'], ':did' => $doctor_id]);
    $apt['medical_records'] = $recQuery->fetchAll(PDO::FETCH_ASSOC);

    $rxQuery = $pdo->prepare("
        SELECT medication_name, dosage_instructions, created_at 
        FROM prescriptions 
        WHERE appointment_id = :aid AND doctor_id = :did
    ");
    $rxQuery->execute([':aid' => $apt['id'], ':did' => $doctor_id]);
    $apt['prescriptions'] = $rxQuery->fetchAll(PDO::FETCH_ASSOC);
}
unset($apt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic | Physician Portal</title>
    <link rel="stylesheet" href="../styles/doctor.css">

    <style>
    .alert { 
        padding: 0.75rem 1rem; 
        border-radius: var(--radius-md, 8px); 
        font-size: 0.875rem; 
        margin-bottom: 1.5rem; 
        opacity: 1;
        transition: opacity 0.6s ease, transform 0.6s ease, margin-bottom 0.6s ease, padding 0.6s ease;
    }

    .alert--fade-out {
        opacity: 0;
        transform: translateY(-8px);
        margin-bottom: 0 !important;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        height: 0 !important;
        overflow: hidden;
        pointer-events: none;
    }
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

        .sig-container {
            border: 1.5px dashed #cbd5e1;
            border-radius: var(--radius-md, 8px);
            background: #ffffff;
            position: relative;
            margin-top: 0.35rem;
            overflow: hidden;
        }
        #sigCanvas {
            display: block;
            width: 100%;
            height: 130px;
            cursor: crosshair;
            touch-action: none;
        }
        .sig-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.35rem 0.65rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            font-size: 0.75rem;
            color: #64748b;
        }

        .btn-step {
            background: #0284c7;
            color: #ffffff;
            border: none;
            padding: 0.45rem 0.85rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: 0.15s ease-in-out;
            white-space: nowrap;
        }
        .btn-step:hover { background: #0369a1; }

        .btn-rx-quick {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-rx-quick:hover { background: #6d28d9; }

       
        .search-box-wrapper {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 0.6rem 0.9rem;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            outline: none;
        }
        .search-input:focus { border-color: var(--primary); }
        .filter-select {
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            background: #ffffff;
            outline: none;
        }
        .history-card {
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: 0.85rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            transition: var(--transition);
            flex-wrap: wrap;
            gap: 1rem;
        }
        .history-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }
        .doc-attachment-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.65rem;
            background: #f1f5f9;
            color: #0f172a;
            border-radius: 6px;
            font-size: 0.75rem;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            margin-top: 0.25rem;
        }
        .doc-attachment-badge:hover {
            background: #e2e8f0;
        }
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
                                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
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
                <strong>Dr. <?= $doctor_name ?></strong>
                <small><?= $doctor_dept ?> &bull; Active Duty</small>
            </div>
            <a href="../logout.php" class="btn btn--outline">Sign Out</a>
        </div>
    </header>

    <main class="dashboard">
        <div class="dashboard__banner">
            <div>
                <h2>Good Day, Dr. <?= $doctor_name ?></h2>
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

        <?php if (!empty($rx_msg)): ?>
            <div class="alert alert--success"><?= htmlspecialchars($rx_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($rx_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($rx_error) ?></div>
        <?php endif; ?>

        <div class="dashboard__grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">

            <!-- Card 1: Today's Queue -->
            <div class="card" id="openQueueModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Today's Patient Queue</h3>
                    <p class="card__desc">Review checked-in patients, diagnose, issue prescriptions, or pass patients if unavailable.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok"><?= count($doctorQueue) ?> Assigned Patient(s)</span>
                    </div>
                </div>
                <span class="card__link">Open Patient Queue &rarr;</span>
            </div>

            <!-- Card 2: NEW COMPLETED PATIENTS & RECORDS -->
            <div class="card" id="openHistoryModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon" style="background:#e0e7ff; color:#4338ca;">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    </div>
                    <h3 class="card__title">Completed Patients & Records</h3>
                    <p class="card__desc">Search completed patient histories, view uploaded lab results, and review past e-prescriptions.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok"><?= count($completedAppointments) ?> Completed Consultations</span>
                    </div>
                </div>
                <span class="card__link">View Patient Records &rarr;</span>
            </div>

            <!-- Card 3: Upload Document & Diagnosis -->
            <div class="card" id="openUploadModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                    </div>
                    <h3 class="card__title">Upload Diagnosis / Lab</h3>
                    <p class="card__desc">Upload diagnostic lab results, imaging scans, and formal diagnosis letters using Booking Codes.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">+ Attach Patient Files</span>
                    </div>
                </div>
                <span class="card__link">Upload Medical Record &rarr;</span>
            </div>

            <!-- Card 4: e-Prescriptions (PDF with Signature) -->
            <div class="card" id="openRxModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--purple">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                        </svg>
                    </div>
                    <h3 class="card__title">e-Prescriptions (Signed PDF)</h3>
                    <p class="card__desc">Issue digitally signed PDF prescriptions using your one-time signature. Instantly filed to patient portal.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">Generates Signed PDF</span>
                    </div>
                </div>
                <span class="card__link">Issue Prescription &rarr;</span>
            </div>

            <!-- Card 5: Working Hours -->
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

    <!-- MODAL 1: REAL PATIENT CONSULTATION QUEUE -->
    <div class="modal-overlay" id="queueModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>My Patient Queue (<?= $doctor_dept ?>)</h3>
                <button class="modal__close" data-close="queueModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="list-container">
                    <?php if (count($doctorQueue) > 0): ?>
                        <?php foreach ($doctorQueue as $queue): 
                            $bCode = htmlspecialchars($queue['booking_code'] ?? ('#' . $queue['id']));
                            $pName = htmlspecialchars(addslashes($queue['patient_name']));
                        ?>
                            <div class="list-item" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1.1rem; border-bottom:1px solid var(--border-subtle); flex-wrap:wrap;">
                                <div style="flex: 1; min-width: 250px;">
                                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                                        <span class="booking-code-badge"><?= $bCode ?></span>
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

                                <div style="display: flex; gap: 0.45rem; align-items: center; flex-wrap: wrap;">
                                    <?php if ($queue['status'] === 'Confirmed'): ?>
                                        <!-- Shown ONLY after approval -->
                                        <button 
                                            type="button" 
                                            onclick="startPatientWorkflow('<?= $bCode ?>', '<?= $pName ?>')" 
                                            class="btn-step"
                                            title="Upload Medical Records & Proceed to Prescription"
                                        >
                                            &#128203; Diagnose & Prescribe
                                        </button>

                                        <button 
                                            type="button" 
                                            onclick="directToRx('<?= $bCode ?>')" 
                                            class="btn-rx-quick"
                                            title="Directly issue prescription"
                                        >
                                            &#9776; Rx Only
                                        </button>

                                        <!-- Complete & Clear from Queue Button -->
                                        <form action="doctor.php" method="POST" style="margin: 0;" onsubmit="return confirm('Conclude consultation and archive this patient?');">
                                            <input type="hidden" name="action" value="complete_appointment">
                                            <input type="hidden" name="appointment_id" value="<?= $queue['id'] ?>">
                                            <button type="submit" class="btn btn--outline" style="padding: 0.45rem 0.75rem; font-size: 0.8rem; color: #15803d; border-color: #86efac; font-weight: 600; white-space: nowrap;">
                                                &#10003; Mark Completed
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Shown when Pending: Doctor MUST approve first -->
                                        <form action="doctor.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="confirm_appointment">
                                            <input type="hidden" name="appointment_id" value="<?= $queue['id'] ?>">
                                            <button type="submit" class="btn btn--primary" style="padding: 0.45rem 0.85rem; font-size: 0.8rem; font-weight: 600; white-space: nowrap;">
                                                &#10003; Approve Consultation
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button 
                                        type="button" 
                                        onclick="promptDoctorUnavailable(<?= $queue['id'] ?>, '<?= $bCode ?>', '<?= $pName ?>')" 
                                        class="btn btn--outline" 
                                        style="padding: 0.45rem 0.7rem; font-size: 0.8rem; color: #dc2626; border-color: #fca5a5; white-space: nowrap;"
                                    >
                                        Pass
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

    <!-- MODAL 2: COMPLETED PATIENTS HISTORY & RECORDS (WITH INSTANT SEARCH & SORT) -->
    <div class="modal-overlay" id="historyModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Completed Consultations &amp; Patient Files</h3>
                <button class="modal__close" data-close="historyModal">&times;</button>
            </div>
            <div class="modal__body">
                
                <!-- Search & Sort Bar -->
                <div class="search-box-wrapper">
                    <input 
                        type="text" 
                        id="historySearchInput" 
                        class="search-input" 
                        placeholder="Search by Patient Name, Booking Code, Symptoms..."
                        onkeyup="filterHistoryCards()"
                    >
                    <select id="historySortSelect" class="filter-select" onchange="sortHistoryCards()">
                        <option value="newest">Sort by: Date (Newest First)</option>
                        <option value="oldest">Sort by: Date (Oldest First)</option>
                        <option value="name">Sort by: Patient Name (A–Z)</option>
                    </select>
                </div>

                <!-- List of Completed Patients -->
                <div id="historyListContainer" style="max-height: 480px; overflow-y: auto; padding-right: 0.25rem;">
                    <?php if (count($completedAppointments) > 0): ?>
                        <?php foreach ($completedAppointments as $idx => $comp): 
                            $bCode = htmlspecialchars($comp['booking_code'] ?? '#'.$comp['id']);
                            $pName = htmlspecialchars($comp['patient_name']);
                            $recordsCount = count($comp['medical_records']);
                            $rxCount = count($comp['prescriptions']);
                        ?>
                            <div class="history-card" 
                                 data-name="<?= strtolower($comp['patient_name']) ?>" 
                                 data-code="<?= strtolower($bCode) ?>" 
                                 data-reason="<?= strtolower($comp['reason'] ?? '') ?>"
                                 data-date="<?= strtotime($comp['appointment_datetime']) ?>">
                                
                                <div style="flex: 1; min-width: 260px;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                                        <span class="booking-code-badge"><?= $bCode ?></span>
                                        <span class="pill pill--ok" style="font-size: 0.725rem;">&#10003; Completed</span>
                                    </div>
                                    <h4 style="margin: 0.2rem 0; font-size: 1rem; color: var(--text-dark);"><?= $pName ?> &bull; <?= htmlspecialchars($comp['phone_number']) ?></h4>
                                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">
                                        Consultation Date: <strong><?= date('M d, Y - h:i A', strtotime($comp['appointment_datetime'])) ?></strong>
                                    </p>
                                    <?php if (!empty($comp['reason'])): ?>
                                        <p style="font-size: 0.775rem; color: var(--text-body); margin-top: 0.25rem;">
                                            Initial Chief Complaint: <em><?= htmlspecialchars($comp['reason']) ?></em>
                                        </p>
                                    <?php endif; ?>

                                    <!-- Quick Pill Summary -->
                                    <div style="margin-top: 0.4rem; display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                        <span class="pill pill--neutral" style="font-size: 0.725rem;">
                                            📁 <?= $recordsCount ?> Medical Document(s)
                                        </span>
                                        <span class="pill pill--neutral" style="font-size: 0.725rem;">
                                            💊 <?= $rxCount ?> e-Prescription(s)
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <button 
                                        type="button" 
                                        class="btn btn--primary" 
                                        style="padding: 0.5rem 0.9rem; font-size: 0.8rem; white-space: nowrap;"
                                        onclick="viewHistoryDetails(<?= htmlspecialchars(json_encode($comp), ENT_QUOTES, 'UTF-8') ?>)"
                                    >
                                        &#128065; View Records &amp; Details
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div id="noHistoryNotice" style="text-align: center; color: var(--text-muted); padding: 3rem 0; font-size: 0.9rem;">
                            No completed patient consultations recorded yet.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-actions" style="margin-top: 1.25rem;">
                    <button class="btn btn--outline" data-close="historyModal">Close History</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2.1: VIEW COMPLETED PATIENT DOSSIER (DETAIL MODAL) -->
    <div class="modal-overlay" id="historyDetailsModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Patient Consultation Dossier</h3>
                <button class="modal__close" data-close="historyDetailsModal">&times;</button>
            </div>
            <div class="modal__body" id="historyDetailsBody">
                <!-- Dynamically populated via JS viewHistoryDetails() -->
            </div>
            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button class="btn btn--outline" data-close="historyDetailsModal">Back to History</button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: UPLOAD DIAGNOSIS / MEDICAL RECORD -->
    <div class="modal-overlay" id="uploadModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Upload Patient Medical Document <span style="font-size:0.75rem; color:#0284c7; font-weight:normal;">(Step 1 of 2)</span></h3>
                <button class="modal__close" data-close="uploadModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="doctor.php" method="POST" enctype="multipart/form-data" class="doctor-form">
                    <input type="hidden" name="action" value="upload_record">

                    <div class="form-group">
                        <label for="booking_code">Patient Booking Reference Code</label>
                        <input type="text" name="booking_code" id="booking_code" placeholder="e.g. HSP-2610-7492" required>
                        <span class="auto-hint">Auto-filled from selected queue consultation.</span>
                    </div>

                    <div class="form-group">
                        <label for="title">Document Title / Clinical Test Name</label>
                        <input type="text" name="title" id="title" placeholder="e.g. Consultation Diagnosis & Lab Request" required>
                    </div>

                    <div class="form-group">
                        <label for="diagnosis_notes">Clinical Findings & Doctor Notes</label>
                        <textarea name="diagnosis_notes" id="diagnosis_notes" rows="3" placeholder="Enter diagnosis summary, patient recommendations, or treatment instructions..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="medical_file">Attach File (PDF, DOCX, JPG, PNG)</label>
                        <input type="file" name="medical_file" id="medical_file" required>
                    </div>

                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; padding: 0.65rem 0.85rem; border-radius: 6px; font-size: 0.8rem; color: #0369a1; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                        <span>&#8505; After upload, you will proceed to the <strong>e-Prescription</strong> screen.</span>
                        <input type="hidden" name="proceed_to_rx" value="1">
                        <a href="javascript:void(0)" onclick="skipToRxFromUpload()" style="font-size: 0.75rem; color: #7c3aed; font-weight: 700; text-decoration: underline;">Skip to Rx &rarr;</a>
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Upload Document & Proceed to Rx &rarr;</button>
                        <button type="button" class="btn btn--outline" data-close="uploadModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 4: E-PRESCRIPTION (WITH ONE-TIME SIGNATURE & PDF) -->
    <div class="modal-overlay" id="rxModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Issue Signed e-Prescription (PDF) <span style="font-size:0.75rem; color:#7c3aed; font-weight:normal;">(Step 2 of 2)</span></h3>
                <button class="modal__close" data-close="rxModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="doctor.php" method="POST" class="doctor-form" id="rxForm">
                    <input type="hidden" name="action" value="issue_prescription">
                    <input type="hidden" name="signature_data" id="signature_data">

                    <div class="form-group">
                        <label for="rx_booking_code">Patient Booking Reference Code</label>
                        <input type="text" name="rx_booking_code" id="rx_booking_code" placeholder="e.g. HSP-2610-7492" required>
                        <span class="auto-hint">Auto-filled. Prescription PDF will be filed directly into the patient's records.</span>
                    </div>

                    <div class="form-group">
                        <label for="medication_name">Medication Name & Strength</label>
                        <input type="text" name="medication_name" id="medication_name" placeholder="e.g. Amoxicillin 500mg capsules" required>
                    </div>

                    <div class="form-group">
                        <label for="dosage_instructions">Dosage & Frequency Instructions</label>
                        <textarea name="dosage_instructions" id="dosage_instructions" rows="3" placeholder="e.g. Take 1 capsule 3 times daily after meals for 7 days." required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Doctor's Digital Signature <span style="color:#dc2626;">* (One-Time Authorization)</span></label>
                        <div class="sig-container">
                            <canvas id="sigCanvas" width="460" height="130"></canvas>
                            <div class="sig-toolbar">
                                <span>Draw your signature using mouse, stylus, or touch</span>
                                <button type="button" id="clearSigBtn" style="background: none; border: none; color: #dc2626; cursor: pointer; font-weight: 600;">&#8634; Clear Signature</button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions" style="margin-top: 1.25rem;">
                        <button type="submit" class="btn btn--primary" id="rxSubmitBtn" style="flex:1;">Sign & Send PDF Prescription</button>
                        <button type="button" class="btn btn--outline" data-close="rxModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 5: SHIFT SCHEDULE -->
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

    <!-- MODAL 6: DOCTOR UNAVAILABLE -->
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
                            Declaring unavailability will <strong>increment your strike counter</strong> and notify Administration.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="unavail_reason">Reason for Unavailability</label>
                        <select name="unavail_reason" id="unavail_reason" required>
                            <option value="Emergency Procedure / Surgery">Emergency Procedure / Surgery</option>
                            <option value="Physician Medical Leave / Sickness">Physician Medical Leave / Sickness</option>
                            <option value="Clinic Schedule Overrun">Clinic Schedule Overrun</option>
                            <option value="Personal Emergency">Personal Emergency</option>
                        </select>
                    </div>

                    <div class="modal-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn--primary" style="background:#dc2626; flex:1;">Confirm & Pass Patient</button>
                        <button type="button" class="btn btn--outline" data-close="unavailModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Physician Portal</p>
    </footer>

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
            closeModal('queueModal');
            document.getElementById('unavail_apt_id').value = aptId;
            document.getElementById('unavail_booking_code').innerText = bookingCode;
            document.getElementById('unavail_patient_name').innerText = patientName;
            openModal('unavailModal');
        }

        function startPatientWorkflow(bookingCode, patientName) {
            closeModal('queueModal');
            document.getElementById('booking_code').value = bookingCode;
            document.getElementById('rx_booking_code').value = bookingCode;
            openModal('uploadModal');
        }

        function directToRx(bookingCode) {
            closeModal('queueModal');
            document.getElementById('rx_booking_code').value = bookingCode;
            openModal('rxModal');
        }

        function skipToRxFromUpload() {
            const bcode = document.getElementById('booking_code').value;
            closeModal('uploadModal');
            directToRx(bcode);
        }

        
        
        
        function filterHistoryCards() {
            const query = document.getElementById('historySearchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.history-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const code = card.getAttribute('data-code');
                const reason = card.getAttribute('data-reason');

                if (name.includes(query) || code.includes(query) || reason.includes(query)) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            let noResNotice = document.getElementById('noSearchResults');
            if (visibleCount === 0) {
                if (!noResNotice) {
                    noResNotice = document.createElement('div');
                    noResNotice.id = 'noSearchResults';
                    noResNotice.style.cssText = 'text-align: center; color: var(--text-muted); padding: 2rem 0; font-size: 0.9rem;';
                    noResNotice.innerText = 'No matching patient records found.';
                    document.getElementById('historyListContainer').appendChild(noResNotice);
                }
            } else if (noResNotice) {
                noResNotice.remove();
            }
        }

        function sortHistoryCards() {
            const sortType = document.getElementById('historySortSelect').value;
            const container = document.getElementById('historyListContainer');
            const cards = Array.from(container.querySelectorAll('.history-card'));

            cards.sort((a, b) => {
                if (sortType === 'newest') {
                    return parseInt(b.getAttribute('data-date')) - parseInt(a.getAttribute('data-date'));
                } else if (sortType === 'oldest') {
                    return parseInt(a.getAttribute('data-date')) - parseInt(b.getAttribute('data-date'));
                } else if (sortType === 'name') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                }
            });

            cards.forEach(card => container.appendChild(card));
        }

        
        
        
        function viewHistoryDetails(data) {
            const body = document.getElementById('historyDetailsBody');
            const bCode = data.booking_code || ('#' + data.id);

            let recordsHtml = '';
            if (data.medical_records && data.medical_records.length > 0) {
                data.medical_records.forEach(rec => {
                    recordsHtml += `
                        <div style="background: #ffffff; border: 1px solid var(--border-subtle); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="font-size: 0.85rem; color: var(--text-dark);">${rec.title}</strong>
                                <p style="font-size: 0.775rem; color: var(--text-muted); margin: 0.15rem 0;">${rec.diagnosis_notes || 'No doctor notes attached.'}</p>
                                <small style="font-size: 0.7rem; color: #64748b;">${rec.file_name} (${rec.file_size})</small>
                            </div>
                            <a href="../${rec.file_path}" target="_blank" class="btn btn--outline" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; white-space: nowrap;">
                                📥 Download File
                            </a>
                        </div>
                    `;
                });
            } else {
                recordsHtml = '<p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">No diagnostic files or lab reports uploaded for this visit.</p>';
            }

            let rxHtml = '';
            if (data.prescriptions && data.prescriptions.length > 0) {
                data.prescriptions.forEach(rx => {
                    rxHtml += `
                        <div style="background: #fdf4ff; border: 1px solid #f0abfc; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 0.5rem;">
                            <strong style="color: #86198f; font-size: 0.875rem;">💊 ${rx.medication_name}</strong>
                            <p style="font-size: 0.8rem; color: #701a75; margin: 0.25rem 0 0 0;"><strong>Instructions:</strong> ${rx.dosage_instructions}</p>
                        </div>
                    `;
                });
            } else {
                rxHtml = '<p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">No e-prescriptions issued for this visit.</p>';
            }

            let initialAttachmentHtml = '';
            if (data.attachment_path) {
                initialAttachmentHtml = `
                    <div style="margin-top: 0.5rem;">
                        <a href="../${data.attachment_path}" target="_blank" class="doc-attachment-badge">
                            📎 Patient Uploaded Symptom File (${data.attachment_name || 'Attachment'})
                        </a>
                    </div>
                `;
            }

            body.innerHTML = `
                <div style="background: #f8fafc; border: 1px solid var(--border-subtle); padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <h3 style="margin: 0; color: var(--text-dark);">${data.patient_name}</h3>
                            <p style="margin: 0.2rem 0; font-size: 0.85rem; color: var(--text-muted);">
                                Phone: <strong>${data.phone_number}</strong> &bull; Email: ${data.patient_email || 'Not specified'}
                            </p>
                        </div>
                        <span class="booking-code-badge">${bCode}</span>
                    </div>

                    <div style="margin-top: 0.75rem; border-top: 1px solid #e2e8f0; padding-top: 0.75rem; font-size: 0.825rem;">
                        <div><strong>Consultation Date & Time:</strong> ${data.appointment_datetime}</div>
                        <div><strong>Chief Complaint / Initial Symptoms:</strong> <em>${data.reason || 'None stated'}</em></div>
                        ${initialAttachmentHtml}
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--text-dark);">Attached Medical Documents & Lab Findings</h4>
                    ${recordsHtml}
                </div>

                <div>
                    <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--text-dark);">Issued e-Prescriptions</h4>
                    ${rxHtml}
                </div>
            `;

            openModal('historyDetailsModal');
        }

        
        
        
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';

                if (id === 'rxModal') {
                    setTimeout(initSigPad, 100);
                }
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
        document.getElementById('openHistoryModal').addEventListener('click', () => openModal('historyModal'));
        document.getElementById('openRxModal').addEventListener('click', () => openModal('rxModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => openModal('scheduleModal'));

        document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close'))));
        document.querySelectorAll('.modal-overlay').forEach(overlay => overlay.addEventListener('click', (e) => { if(e.target === overlay) closeModal(overlay.id); }));

        
        const canvas = document.getElementById('sigCanvas');
        const ctx = canvas ? canvas.getContext('2d') : null;
        let isDrawing = false;
        let hasSigned = false;

        function initSigPad() {
            if (!canvas || !ctx) return;
            canvas.width = 460;
            canvas.height = 130;
            
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.strokeStyle = "#0f172a";
            ctx.lineWidth = 2.5;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            hasSigned = false;
        }

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return {
                x: (clientX - rect.left) * (canvas.width / rect.width),
                y: (clientY - rect.top) * (canvas.height / rect.height)
            };
        }

        function startDrawing(e) {
            isDrawing = true;
            hasSigned = true;
            const pos = getPos(e);
            ctx.beginPath();
            ctx.moveTo(pos.x, pos.y);
            e.preventDefault();
        }

        function draw(e) {
            if (!isDrawing) return;
            const pos = getPos(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.stroke();
            e.preventDefault();
        }

        function stopDrawing() {
            if (isDrawing) {
                ctx.closePath();
                isDrawing = false;
                document.getElementById('signature_data').value = canvas.toDataURL('image/jpeg', 0.92);
            }
        }

        function clearSig() {
            if (!ctx) return;
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            hasSigned = false;
            document.getElementById('signature_data').value = '';
        }

        if (canvas) {
            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('mousemove', draw);
            window.addEventListener('mouseup', stopDrawing);

            canvas.addEventListener('touchstart', startDrawing, { passive: false });
            canvas.addEventListener('touchmove', draw, { passive: false });
            canvas.addEventListener('touchend', stopDrawing);

            document.getElementById('clearSigBtn').addEventListener('click', clearSig);
        }

        const rxForm = document.getElementById('rxForm');
        if (rxForm) {
            rxForm.addEventListener('submit', (e) => {
                if (!hasSigned) {
                    e.preventDefault();
                    alert("Please provide your digital signature on the pad before issuing the prescription.");
                    return false;
                }
                document.getElementById('signature_data').value = canvas.toDataURL('image/jpeg', 0.92);
            });
        }

        <?php if (!empty($auto_open_rx_code)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                document.getElementById('rx_booking_code').value = '<?= $auto_open_rx_code ?>';
                openModal('rxModal');
            });
        <?php endif; ?>

        <?php if (!empty($auto_open_queue_modal)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                openModal('queueModal');
            });
        <?php endif; ?>


        document.addEventListener('DOMContentLoaded', () => {
            const alerts = document.querySelectorAll('.alert');
            if (alerts.length > 0) {
                setTimeout(() => {
                    alerts.forEach(alert => {
                        alert.classList.add('alert--fade-out');
                        
                        setTimeout(() => alert.remove(), 600);
                    });
                }, 3500); 
            }
        });
    </script>
    <script src="../scripts/session_timeout.js"></script>
    <script src="../scripts/session_checker.js"></script>
</body>
</html>