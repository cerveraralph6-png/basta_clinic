<?php
session_start();
require_once '../db.php';

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'doctor') {
    header("Location: ../index.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];
$doctor_name = htmlspecialchars($_SESSION['user_name'] ?? 'Dr. Michael Chen, MD');

$upload_msg = '';
$upload_error = '';

// ==========================================
// HANDLE MEDICAL DOCUMENT UPLOAD
// ==========================================
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
            // Ensure uploads directory exists
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Generate clean safe filename
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

                // Notify Patient
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

// Fetch list of patients for the upload dropdown
$patientListStmt = $pdo->query("SELECT id, full_name, identifier FROM users WHERE role = 'patient' ORDER BY full_name ASC");
$patients = $patientListStmt->fetchAll(PDO::FETCH_ASSOC);
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
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md, 8px);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .alert--error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert--success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="navbar__brand">
            <img src="../images/logo.png" alt="Hosplinic Logo" class="navbar__logo">
            <h1 class="navbar__title">Hosplinic</h1>
            <span class="doctor-badge">Physician Portal</span>
        </div>
        
        <div class="navbar__actions">
            <div class="doctor-profile">
                <strong><?= $doctor_name ?></strong>
                <small>Cardiology &bull; Active Duty</small>
            </div>
            <a href="../logout.php" class="btn btn--outline">Sign Out</a>
        </div>
    </header>

    <main class="dashboard">
        <div class="dashboard__banner">
            <div>
                <h2>Good Day, <?= $doctor_name ?></h2>
                <p>Manage patient encounters, electronic records, and upload clinical diagnostic files.</p>
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
                    <p class="card__desc">Review checked-in patients, manage consultation wait times, and launch encounters.</p>
                </div>
                <span class="card__link">Open Patient Queue &rarr;</span>
            </div>

            <!-- Card 2: Upload Document & Diagnosis (CONNECTED) -->
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
                </div>
                <span class="card__link">Manage Clinic Hours &rarr;</span>
            </div>

        </div>
    </main>

    <!-- ==========================================
         MODAL: UPLOAD DIAGNOSIS & DOCUMENT (FUNCTIONAL)
         ========================================== -->
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

    <!-- Other Modals (Queue, Rx, Shifts) -->
    <div class="modal-overlay" id="queueModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Today's Patient Queue</h3>
                <button class="modal__close" data-close="queueModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="text-align: center; color: var(--text-muted); padding: 2rem 0;">Room 302B &bull; Consultations on schedule.</p>
                <div class="modal-actions"><button class="btn btn--outline" data-close="queueModal">Close</button></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="rxModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Issue Prescription</h3>
                <button class="modal__close" data-close="rxModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="text-align:center; color: var(--text-muted); padding: 1.5rem 0;">Pharmacy connected.</p>
                <div class="modal-actions"><button class="btn btn--outline" data-close="rxModal">Close</button></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Shift Schedule</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">
                <p style="text-align:center; color: var(--text-muted); padding: 1.5rem 0;">Regular Hours: 08:00 AM – 04:00 PM</p>
                <div class="modal-actions"><button class="btn btn--outline" data-close="scheduleModal">Close</button></div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Physician Portal</p>
    </footer>

    <script>
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