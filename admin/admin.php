<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$admin_name = htmlspecialchars($_SESSION['user_name'] ?? 'Administrator');
$staff_msg = '';
$staff_error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_staff') {
    $fullName    = trim($_POST['fullName'] ?? '');
    $identifier  = trim($_POST['identifier'] ?? '');
    $phoneNumber = trim($_POST['phoneNumber'] ?? '');
    $role        = $_POST['role'] ?? 'doctor';
    $password    = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirmPassword'] ?? '';

    if (empty($fullName) || empty($identifier) || empty($phoneNumber) || empty($password)) {
        $staff_error = "All fields are required.";
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
            
            $insertStmt = $pdo->prepare("
                INSERT INTO users (full_name, identifier, phone_number, password_hash, role)
                VALUES (:full_name, :identifier, :phone_number, :password_hash, :role)
            ");
            
            $created = $insertStmt->execute([
                ':full_name'     => $fullName,
                ':identifier'   => $identifier,
                ':phone_number'  => $phoneNumber,
                ':password_hash' => $hashedPass,
                ':role'          => $role
            ]);

            if ($created) {
                $staff_msg = ucfirst($role) . " account for '$fullName' created successfully!";
            } else {
                $staff_error = "Failed to create account. Please try again.";
            }
        }
    }
}
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
        .alert--error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .alert--success {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .staff-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .staff-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .staff-form label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .staff-form input, .staff-form select {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-subtle);
            font-family: inherit;
            font-size: 0.9rem;
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
        
        <div class="navbar__actions">
            <a href="../index.php" class="btn btn--outline">Main Site</a>
            <a href="../logout.php" class="btn btn--primary">Logout</a>
        </div>
    </header>

    <main class="dashboard">
        <div class="dashboard__intro" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; text-align:left;">
            <div>
                <h2>Administrative Operations</h2>
                <p>Manage facility inventory, view clinical analytics, or register medical staff.</p>
            </div>

            <button class="btn btn--primary" id="openStaffModal">+ Register Doctor / Admin</button>
        </div>

        <?php if (!empty($staff_msg)): ?>
            <div class="alert alert--success"><?= htmlspecialchars($staff_msg) ?></div>
        <?php endif; ?>
        <?php if (!empty($staff_error)): ?>
            <div class="alert alert--error"><?= htmlspecialchars($staff_error) ?></div>
        <?php endif; ?>

        <div class="dashboard__grid">

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

            <div class="card" id="openAnalyticsModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Reporting & Analytics</h3>
                    <p class="card__desc">Track weekly patient volume, review operational efficiency, and export monthly clinic performance.</p>
                    
                    <div class="pill-group">
                        <span class="pill pill--ok">+14.2% Monthly Growth</span>
                        <span class="pill pill--neutral">96% Satisfaction</span>
                    </div>
                </div>
                <span class="card__link">View Analytics &rarr;</span>
            </div>
        </div>
    </main>

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

                    <div class="form-group">
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="fullName" placeholder="e.g. Dr. Michael Chen" required>
                    </div>

                    <div class="form-group">
                        <label for="identifier">Username / Email</label>
                        <input type="text" id="identifier" name="identifier" placeholder="e.g. dr.chen@hosplinic or chen_md" required>
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

    <div class="modal-overlay" id="inventoryModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Medical & Pharmacy Stock</h3>
                <button class="modal__close" data-close="inventoryModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Amoxicillin 500mg</strong></td>
                                <td>Antibiotic</td>
                                <td>12 boxes</td>
                                <td><span class="pill pill--danger">Critical Low</span></td>
                            </tr>
                            <tr>
                                <td><strong>Surgical Gloves (M)</strong></td>
                                <td>PPE</td>
                                <td>45 boxes</td>
                                <td><span class="pill pill--ok">Optimal</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer-actions">
                    <button class="btn btn--outline" data-close="inventoryModal">Close</button>
                </div>
            </div>
        </div>
    </div>

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
                <div class="modal-footer-actions">
                    <button class="btn btn--outline" data-close="analyticsModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System &bull; Admin Portal</p>
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


        document.getElementById('openStaffModal').addEventListener('click', () => openModal('staffModal'));
        document.getElementById('openInventoryModal').addEventListener('click', () => openModal('inventoryModal'));
        document.getElementById('openAnalyticsModal').addEventListener('click', () => openModal('analyticsModal'));


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