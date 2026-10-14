<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$patient_full_name = htmlspecialchars($_SESSION['user_name'] ?? 'Patient');
$patient_first_name = explode(' ', trim($patient_full_name))[0];
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
</head>
<body>

    <header class="navbar">
        <div class="navbar__brand">
            <img src="../images/logo.png" alt="Hosplinic Logo" class="navbar__logo">
            <h1 class="navbar__title">Hosplinic</h1>
            <span class="patient-badge">Patient Portal</span>
        </div>
        
        <div class="navbar__actions">

            <span class="patient-greeting">Hi, <?= $patient_full_name ?></span>

            <a href="../logout.php" class="btn btn--outline">Logout</a>
        </div>
    </header>


    <main class="dashboard">

        <div class="dashboard__banner">
            <div>

                <h2>Welcome Back, <?= $patient_first_name ?></h2>
                <p>Manage your health records, view doctor appointments, and check statements.</p>
            </div>
            <div class="banner-notice">
                <span>Next Visit: <strong>Tomorrow, 10:30 AM</strong> (Dr. Michael Chen)</span>
            </div>
        </div>


        <div class="dashboard__grid">


            <div class="card" id="openBookModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <h3 class="card__title">Add Appointment</h3>
                    <p class="card__desc">Choose a medical department, pick a specialist, and book an in-person or virtual consultation.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">Available Slots Today</span>
                    </div>
                </div>
                <span class="card__link">Book New Appointment &rarr;</span>
            </div>


            <div class="card" id="openScheduleModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">My Appointments</h3>
                    <p class="card__desc">Review your upcoming consultations, check queue status, or reschedule existing bookings.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--info">1 Upcoming Booking</span>
                    </div>
                </div>
                <span class="card__link">View Full Schedule &rarr;</span>
            </div>

            <div class="card" id="openBillingModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--purple">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Billing & Statements</h3>
                    <p class="card__desc">Review treatment invoices, check medical insurance coverage, and settle copay balances online.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">$0.00 Outstanding</span>
                    </div>
                </div>
                <span class="card__link">View Billing History &rarr;</span>
            </div>


            <div class="card" id="openDiagnosisModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--amber">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Diagnosis & Records</h3>
                    <p class="card__desc">Access verified doctor notes, official lab test results, prescriptions, and diagnostic summaries.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--neutral">Updated 3 days ago</span>
                    </div>
                </div>
                <span class="card__link">View Medical Records &rarr;</span>
            </div>

        </div>
    </main>

    <div class="modal-overlay" id="bookModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Book an Appointment</h3>
                <button class="modal__close" data-close="bookModal">&times;</button>
            </div>
            <div class="modal__body">
                <form action="#" method="POST" class="patient-form">
                    <div class="form-group">
                        <label for="deptSelect">Department</label>
                        <select id="deptSelect" required>
                            <option value="">Select a department...</option>
                            <option value="cardio">Cardiology</option>
                            <option value="pedia">Pediatrics</option>
                            <option value="derma">Dermatology</option>
                            <option value="general">General Medicine</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="doctorSelect">Preferred Specialist</label>
                        <select id="doctorSelect" required>
                            <option value="">Select a doctor...</option>
                            <option value="chen">Dr. Michael Chen</option>
                            <option value="connor">Dr. Sarah Connor</option>
                            <option value="vance">Dr. Emily Vance</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="bookDateTime">Date & Preferred Time</label>
                        <input type="datetime-local" id="bookDateTime" required>
                    </div>

                    <div class="form-group">
                        <label for="bookNotes">Reason for Visit</label>
                        <textarea id="bookNotes" rows="2" placeholder="Briefly describe your symptoms..."></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Confirm Booking</button>
                        <button type="button" class="btn btn--outline" data-close="bookModal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>My Scheduled Appointments</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="list-container">
                    <div class="list-item">
                        <div>
                            <h4>Dr. Michael Chen &bull; Cardiology</h4>
                            <p>Tomorrow at 10:30 AM &bull; Room 302B (Main Wing)</p>
                        </div>
                        <span class="pill pill--ok">Confirmed</span>
                    </div>

                    <div class="list-item">
                        <div>
                            <h4>Dr. Emily Vance &bull; Dermatology</h4>
                            <p>Completed on Oct 14, 2025 &bull; Routine Skin Screening</p>
                        </div>
                        <span class="pill pill--neutral">Completed</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="btn btn--outline" data-close="scheduleModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="billingModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Billing & Insurance Summary</h3>
                <button class="modal__close" data-close="billingModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="billing-summary-box">
                    <div>
                        <small>Total Outstanding Balance</small>
                        <strong class="billing-balance">$0.00</strong>
                    </div>
                    <span class="pill pill--ok">All Invoices Paid</span>
                </div>

                <h4 style="margin: 1.25rem 0 0.5rem 0; font-size: 0.95rem;">Recent Invoices</h4>
                <div class="list-container">
                    <div class="list-item">
                        <div>
                            <h4>Invoice #INV-2025-089</h4>
                            <p>Dermatology Consultation &bull; Oct 14, 2025</p>
                        </div>
                        <div style="text-align: right;">
                            <strong>$45.00</strong>
                            <p><small style="color: #15803d;">Paid via Visa</small></p>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button class="btn btn--outline" data-close="billingModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="diagnosisModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Clinical Diagnosis & Medical Notes</h3>
                <button class="modal__close" data-close="diagnosisModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="record-card">
                    <div class="record-card__header">
                        <div>
                            <h4>General Wellness & Skin Screening</h4>
                            <small>Attending: Dr. Emily Vance &bull; Oct 14, 2025</small>
                        </div>
                        <span class="pill pill--ok">Normal</span>
                    </div>
                    <div class="record-card__content">
                        <p><strong>Primary Diagnosis:</strong> Mild Seasonal Contact Dermatitis. No malignant lesions noted.</p>
                        <p><strong>Treatment Plan:</strong> Prescribed Topical Hydrocortisone 1% cream once daily as needed.</p>
                    </div>
                </div>

                <div class="record-card" style="margin-top: 1rem;">
                    <div class="record-card__header">
                        <div>
                            <h4>Comprehensive Metabolic Blood Panel</h4>
                            <small>Lab Pathology &bull; Sep 28, 2025</small>
                        </div>
                        <span class="pill pill--neutral">Archived</span>
                    </div>
                    <div class="record-card__content">
                        <p><strong>Summary:</strong> Electrolytes, glucose, and renal biomarkers are all within optimal baseline levels.</p>
                    </div>
                </div>

                <div class="modal-actions">
                    <button class="btn btn--primary">Download Records (PDF)</button>
                    <button class="btn btn--outline" data-close="diagnosisModal">Close</button>
                </div>
            </div>
        </div>
    </div>


    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Patient Care Portal</p>
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


        document.getElementById('openBookModal').addEventListener('click', () => openModal('bookModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => openModal('scheduleModal'));
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
    </script>
</body>
</html>