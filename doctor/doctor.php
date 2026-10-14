<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'doctor') {
    header("Location: ../index.php");
    exit;
}

$doctor_name = htmlspecialchars($_SESSION['user_name'] ?? 'Dr. Michael Chen, MD');
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
                <p>You have <strong>8 consultations</strong> scheduled for today. Clinic Room 302B is active.</p>
            </div>
            <div class="banner-status">
                <span class="status-indicator"></span>
                <span>Next Patient: <strong>Sarah Jenkins (10:30 AM)</strong> &bull; Checked In</span>
            </div>
        </div>

        <div class="dashboard__grid">

            <div class="card" id="openQueueModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Today's Patient Queue</h3>
                    <p class="card__desc">Review checked-in patients, manage consultation wait times, and launch clinical encounters.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">3 In Waiting Room</span>
                        <span class="pill pill--neutral">5 Upcoming</span>
                    </div>
                </div>
                <span class="card__link">Open Patient Queue &rarr;</span>
            </div>

            <div class="card" id="openEmrModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Patient EMR Records</h3>
                    <p class="card__desc">Lookup comprehensive medical histories, allergies, past diagnoses, and vital sign logs.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--info">Full Database Access</span>
                    </div>
                </div>
                <span class="card__link">Search Patient Records &rarr;</span>
            </div>

            <div class="card" id="openRxModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--purple">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                        </svg>
                    </div>
                    <h3 class="card__title">e-Prescriptions & Labs</h3>
                    <p class="card__desc">Issue digital prescriptions directly to the hospital pharmacy and request diagnostic bloodwork.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">Pharmacy Connected</span>
                    </div>
                </div>
                <span class="card__link">Issue Rx or Lab Order &rarr;</span>
            </div>

            <div class="card" id="openScheduleModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--amber">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Availability & Shifts</h3>
                    <p class="card__desc">Adjust your clinical consultation hours, mark on-call duty, or block calendar time for surgery.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--neutral">Shift: 08:00 AM - 04:00 PM</span>
                    </div>
                </div>
                <span class="card__link">Manage Clinic Hours &rarr;</span>
            </div>

        </div>
    </main>

    <div class="modal-overlay" id="queueModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Today's Patient Queue (Room 302B)</h3>
                <button class="modal__close" data-close="queueModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="list-container">
                    <div class="list-item list-item--active">
                        <div>
                            <h4>Sarah Jenkins &bull; ID #P-4091</h4>
                            <p>10:30 AM &bull; Reason: Routine Blood Pressure & Arrhythmia Follow-up</p>
                        </div>
                        <div style="display:flex; gap: 0.5rem; align-items:center;">
                            <span class="pill pill--ok">Checked In</span>
                            <button class="btn btn--primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">Start Encounter</button>
                        </div>
                    </div>

                    <div class="list-item">
                        <div>
                            <h4>David Vance &bull; ID #P-3882</h4>
                            <p>11:15 AM &bull; Reason: Post-Surgery ECG Assessment</p>
                        </div>
                        <span class="pill pill--warn">Waiting (15m)</span>
                    </div>

                    <div class="list-item">
                        <div>
                            <h4>Maria Gomez &bull; ID #P-4105</h4>
                            <p>01:00 PM &bull; Reason: New Patient Consultation</p>
                        </div>
                        <span class="pill pill--neutral">Upcoming</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="btn btn--outline" data-close="queueModal">Close Queue</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="emrModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Electronic Medical Records (EMR)</h3>
                <button class="modal__close" data-close="emrModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="search-box">
                    <input type="text" placeholder="Search by Patient Name, ID, or National ID...">
                    <button class="btn btn--primary">Search</button>
                </div>

                <div class="record-preview">
                    <div class="record-preview__header">
                        <h4>Sarah Jenkins (Female, 34 yrs)</h4>
                        <span class="pill pill--ok">Verified Record</span>
                    </div>
                    <p><strong>Known Allergies:</strong> Penicillin (Moderate), Latex (Mild)</p>
                    <p><strong>Chronic Conditions:</strong> Hypertension (Controlled via ACE-inhibitor)</p>
                    <p><strong>Last Recorded BP:</strong> 122/78 mmHg &bull; Oct 10, 2025</p>
                </div>

                <div class="modal-actions">
                    <button class="btn btn--primary">View Full Case History</button>
                    <button class="btn btn--outline" data-close="emrModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="rxModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Issue e-Prescription / Lab Order</h3>
                <button class="modal__close" data-close="rxModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="#" method="POST" class="doctor-form">
                    <div class="form-group">
                        <label for="rxPatient">Patient Name / ID</label>
                        <input type="text" id="rxPatient" value="Sarah Jenkins (#P-4091)" required>
                    </div>

                    <div class="form-group">
                        <label for="rxType">Order Type</label>
                        <select id="rxType" required>
                            <option value="rx">Pharmaceutical Prescription</option>
                            <option value="lab">Diagnostic Lab Bloodwork</option>
                            <option value="imaging">Radiology / Imaging (X-Ray / MRI)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="rxMedication">Medication / Lab Test Name</label>
                        <input type="text" id="rxMedication" placeholder="e.g. Lisinopril 10mg or Lipid Panel" required>
                    </div>

                    <div class="form-group">
                        <label for="rxInstructions">Dosage & Instructions</label>
                        <textarea id="rxInstructions" rows="2" placeholder="Take 1 tablet daily with water in the morning..."></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Transmit to Pharmacy</button>
                        <button type="button" class="btn btn--outline" data-close="rxModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==========================================
         MODAL 4: AVAILABILITY SETTINGS
         ========================================== -->
    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Doctor Shift & Availability</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="availability-list">
                    <div class="availability-row">
                        <div>
                            <strong>Monday – Thursday</strong>
                            <small>Regular Outpatient Consultations</small>
                        </div>
                        <span class="pill pill--ok">08:00 AM – 04:00 PM</span>
                    </div>

                    <div class="availability-row">
                        <div>
                            <strong>Friday</strong>
                            <small>Inpatient Rounds & Echo Labs</small>
                        </div>
                        <span class="pill pill--info">09:00 AM – 01:00 PM</span>
                    </div>

                    <div class="availability-row">
                        <div>
                            <strong>Weekends</strong>
                            <small>Emergency On-Call Duty</small>
                        </div>
                        <span class="pill pill--neutral">On-Call Only</span>
                    </div>
                </div>

                <div class="modal-actions">
                    <button class="btn btn--primary" style="flex:1;">Request Shift Change</button>
                    <button class="btn btn--outline" data-close="scheduleModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Physician Clinical Portal</p>
    </footer>

    <!-- Modal Interaction Script -->
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

        // Open triggers
        document.getElementById('openQueueModal').addEventListener('click', () => openModal('queueModal'));
        document.getElementById('openEmrModal').addEventListener('click', () => openModal('emrModal'));
        document.getElementById('openRxModal').addEventListener('click', () => openModal('rxModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => openModal('scheduleModal'));

        // Close triggers
        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
        });

        // Close when clicking outside of modal
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });

        // Close on 'Escape' key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => closeModal(modal.id));
            }
        });
    </script>
</body>
</html>