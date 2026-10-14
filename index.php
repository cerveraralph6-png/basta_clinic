<?php
session_start();
require_once 'db.php';

$auth_error = '';
$auth_success = '';
$open_modal_on_load = ''; 


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $fullName        = trim($_POST['fullName'] ?? '');
    $identifier      = trim($_POST['identifier'] ?? '');
    $phoneNumber     = trim($_POST['phoneNumber'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (empty($fullName) || empty($identifier) || empty($phoneNumber) || empty($password)) {
        $auth_error = "Please fill in all required fields.";
        $open_modal_on_load = 'registerModal';
    } elseif ($password !== $confirmPassword) {
        $auth_error = "Passwords do not match.";
        $open_modal_on_load = 'registerModal';
    } elseif (strlen($password) < 6) {
        $auth_error = "Password must be at least 6 characters.";
        $open_modal_on_load = 'registerModal';
    } else {

        $stmt = $pdo->prepare("SELECT id FROM users WHERE identifier = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);
        
        if ($stmt->fetch()) {
            $auth_error = "This Email or Username is already registered.";
            $open_modal_on_load = 'registerModal';
        } else {

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $insertStmt = $pdo->prepare("
                INSERT INTO users (full_name, identifier, phone_number, password_hash, role)
                VALUES (:full_name, :identifier, :phone_number, :password_hash, 'patient')
            ");
            
            $inserted = $insertStmt->execute([
                ':full_name'     => $fullName,
                ':identifier'   => $identifier,
                ':phone_number'  => $phoneNumber,
                ':password_hash' => $hashedPassword
            ]);

            if ($inserted) {
                $auth_success = "Registration successful! You can now log in.";
                $open_modal_on_load = 'loginModal'; 
            } else {
                $auth_error = "Registration failed. Please try again.";
                $open_modal_on_load = 'registerModal';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginIdentifier = trim($_POST['loginIdentifier'] ?? '');
    $loginPassword   = $_POST['loginPassword'] ?? '';

    if (empty($loginIdentifier) || empty($loginPassword)) {
        $auth_error = "Please enter both identifier and password.";
        $open_modal_on_load = 'loginModal';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE identifier = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $loginIdentifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($loginPassword, $user['password_hash'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];

            if ($user['role'] === 'admin') {
                header("Location: admin/admin.php");
                exit;
            } elseif ($user['role'] === 'doctor') {
                header("Location: doctor/doctor.php");
                exit;
            } else {
                header("Location: patient/patient.php");
                exit;
            }
        } else {
            $auth_error = "Invalid Email/Username or Password.";
            $open_modal_on_load = 'loginModal';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/index.css">
    
    <style>
        .modal-switch-text {
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted, #64748b);
            margin-top: 1.25rem;
        }
        .modal-switch-text a {
            color: var(--primary, #0284c7);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-switch-text a:hover { text-decoration: underline; }
        
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
    </style>
</head>
<body>

    <header class="navbar">
        <div class="navbar__brand">
            <img src="images/logo.png" alt="logo" class="navbar__logo">
            <h1 class="navbar__title">Hosplinic</h1>
        </div>
        
        <div class="navbar__actions">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span style="font-weight: 600; font-size: 0.9rem; margin-right: 0.5rem;">
                    Hello, <?= htmlspecialchars($_SESSION['user_name']) ?>
                </span>
                <a href="logout.php" class="btn btn--outline" style="padding: 0.45rem 1rem;">Logout</a>
            <?php else: ?>
                <button class="btn btn--primary" id="openLoginModal">Login</button>
            <?php endif; ?>
        </div>
    </header>

    <main class="dashboard">
        <div class="dashboard__intro">
            <h2>Hospital Portal</h2>
            <p>Select an option below to manage clinic operations</p>
        </div>

        <div class="dashboard__grid">
            <div class="card" id="openRegisterModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Register Account</h3>
                    <p class="card__desc">Create a new patient or staff profile with Google or your credentials to access the system.</p>
                </div>
                <span class="card__link">Register Now &rarr;</span>
            </div>

            <div class="card" id="openScheduleModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Appointment Schedule</h3>
                    <p class="card__desc">Browse the full monthly clinic calendar, inspect booked slots, and check doctor availability.</p>
                </div>
                <span class="card__link">View Calendar &rarr;</span>
            </div>
        </div>
    </main>

    <div class="modal-overlay" id="loginModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Welcome Back</h3>
                <button class="modal__close" data-close="loginModal">&times;</button>
            </div>
            
            <div class="modal__body">
                <?php if (!empty($auth_success)): ?>
                    <div class="alert alert--success"><?= htmlspecialchars($auth_success) ?></div>
                <?php endif; ?>

                <?php if (!empty($auth_error) && $open_modal_on_load === 'loginModal'): ?>
                    <div class="alert alert--error"><?= htmlspecialchars($auth_error) ?></div>
                <?php endif; ?>

                <button type="button" class="btn btn--google">
                    <svg width="18" height="18" viewBox="0 0 18 18">
                        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
                        <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707 0-.59.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/>
                        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
                    </svg>
                    Continue with Gmail
                </button>

                <div class="divider">or login with credentials</div>

                <form action="index.php" method="POST" id="loginForm">
                    <input type="hidden" name="action" value="login">

                    <div class="form-group">
                        <label for="loginIdentifier">Email or Username</label>
                        <input 
                            type="text" 
                            id="loginIdentifier" 
                            name="loginIdentifier" 
                            placeholder="Enter your email or username" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="loginPassword">Password</label>
                        <input 
                            type="password" 
                            id="loginPassword" 
                            name="loginPassword" 
                            placeholder="••••••••" 
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn--primary" style="width: 100%; margin-top: 1rem;">Log In</button>
                </form>

                <p class="modal-switch-text">
                    Don't have an account? <a href="#" id="switchToRegister">Register</a>
                </p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal" aria-hidden="true" >
        <div class="modal">


            <div class="modal__header">
                <h3>Create an Account</h3>
                <button class="modal__close" data-close="registerModal">&times;</button>
            </div>
            
            <div class="modal__body">

                <?php if (!empty($auth_error) && $open_modal_on_load === 'registerModal'): ?>
                    <div class="alert alert--error"><?= htmlspecialchars($auth_error) ?></div>
                <?php endif; ?>

                <button type="button" class="btn btn--google">
                    <svg width="18" height="18" viewBox="0 0 18 18">
                        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
                        <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707 0-.59.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/>
                        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
                    </svg>
                    Sign in with Google
                </button>

                <div class="divider">or continue with credentials</div>

                <form action="index.php" method="POST" id="registrationForm">
                    <input type="hidden" name="action" value="register">

                    <div class="form-group">
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="fullName" placeholder="e.g. Sarah Jenkins" required>
                    </div>

                    <div class="form-group">
                        <label for="identifier">Email or Username</label>
                        <input 
                            type="text" 
                            id="identifier" 
                            name="identifier" 
                            placeholder="e.g. sarah.j@example.com or sarah_jenkins" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="phoneNumber">Phone Number</label>
                        <input type="tel" id="phoneNumber" name="phoneNumber" placeholder="e.g. +1 555-019-2834" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="regPassword">Password</label>
                            <input type="password" id="regPassword" name="password" placeholder="••••••••" required>
                        </div>

                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password</label>
                            <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                        </div>
                    </div>

                    <div style="display:flex; gap:1rem; margin-top:1.5rem;">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Create Account</button>
                        <button type="button" class="btn btn--outline" data-close="registerModal" style="flex:1;">Cancel</button>
                    </div>
                </form>

                <p class="modal-switch-text">
                    Already have an account? <a href="#" id="switchToLogin">Log in</a>
                </p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Appointment Calendar</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">
                <div class="calendar-layout">
                    <div>
                        <div class="calendar-header">
                            <h4 id="calendarMonthYear">October 2025</h4>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="calendar-nav-btn" id="prevMonthBtn">&lt;</button>
                                <button class="calendar-nav-btn" id="nextMonthBtn">&gt;</button>
                            </div>
                        </div>
                        <div class="calendar-weekdays">
                            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                        </div>
                        <div class="calendar-days" id="calendarDays"></div>
                    </div>
                    <div class="calendar-agenda">
                        <h5>Daily Consultations</h5>
                        <p class="agenda-date" id="selectedDateText">Select a day</p>
                        <div class="agenda-list" id="agendaList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System</p>
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

        const loginBtn = document.getElementById('openLoginModal');
        if (loginBtn) loginBtn.addEventListener('click', () => openModal('loginModal'));

        document.getElementById('openRegisterModal').addEventListener('click', () => openModal('registerModal'));
        document.getElementById('openScheduleModal').addEventListener('click', () => {
            openModal('scheduleModal');
            renderCalendar();
        });

        document.getElementById('switchToRegister').addEventListener('click', (e) => {
            e.preventDefault();
            closeModal('loginModal');
            openModal('registerModal');
        });

        document.getElementById('switchToLogin').addEventListener('click', (e) => {
            e.preventDefault();
            closeModal('registerModal');
            openModal('loginModal');
        });

        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });

        <?php if (!empty($open_modal_on_load)): ?>
            openModal('<?= $open_modal_on_load ?>');
        <?php endif; ?>

        let currentDate = new Date();
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            document.getElementById('calendarMonthYear').innerText = `${monthNames[month]} ${year}`;
            const firstDay = new Date(year, month, 1).getDay();
            const lastDay = new Date(year, month + 1, 0).getDate();
            const calendarDays = document.getElementById('calendarDays');
            calendarDays.innerHTML = '';

            for (let i = 0; i < firstDay; i++) {
                calendarDays.appendChild(Object.assign(document.createElement('div'), {className: 'calendar-day empty'}));
            }

            for (let d = 1; d <= lastDay; d++) {
                const el = Object.assign(document.createElement('div'), {className: 'calendar-day', innerText: d});
                calendarDays.appendChild(el);
            }
        }

        document.getElementById('prevMonthBtn').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
        document.getElementById('nextMonthBtn').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });
    </script>
</body>
</html>