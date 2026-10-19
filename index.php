<?php
session_start();
require_once 'db.php';






if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header("Location: admin/admin.php");
        exit;
    } elseif ($_SESSION['user_role'] === 'doctor') {
        header("Location: doctor/doctor.php");
        exit;
    } elseif ($_SESSION['user_role'] === 'patient') {
        header("Location: patient/patient.php");
        exit;
    }
}


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");




$google_client_id = '400820330228-fobmbu7882pm3dd393vv0dpe43735rpm.apps.googleusercontent.com';

$auth_error = '';
$auth_success = '';
$open_modal_on_load = ''; 




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_google_register') {
    if (isset($_SESSION['pending_google_user'])) {
        $name  = $_SESSION['pending_google_user']['name'];
        $email = $_SESSION['pending_google_user']['email'];
        $phone = trim($_POST['phoneNumber'] ?? 'Google Account');

        $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $insertStmt = $pdo->prepare("
            INSERT INTO users (full_name, identifier, phone_number, password_hash, role)
            VALUES (:name, :email, :phone, :pass, 'patient')
        ");
        $insertStmt->execute([
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':pass'  => $randomPassword
        ]);

        $newUserId = $pdo->lastInsertId();

        unset($_SESSION['pending_google_user']);

        $_SESSION['user_id']   = $newUserId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_role'] = 'patient';

        header("Location: patient/patient.php");
        exit;
    }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'google_login') {
    $jwt = $_POST['credential'] ?? '';
    $auth_source = $_POST['auth_source'] ?? 'login';

    if (!empty($jwt)) {
        $parts = explode(".", $jwt);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

            if ($payload && isset($payload['email'])) {
                $email = trim($payload['email']);
                $name  = trim($payload['name'] ?? 'Google User');

                $stmt = $pdo->prepare("SELECT * FROM users WHERE identifier = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
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
                    if ($auth_source === 'login') {
                        $_SESSION['pending_google_user'] = [
                            'email' => $email,
                            'name'  => $name
                        ];
                        $auth_error = "No account found for <strong>$email</strong>. You can register an account below if you wish.";
                        $open_modal_on_load = 'googleConfirmModal';
                    } else {
                        $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                        $insertStmt = $pdo->prepare("
                            INSERT INTO users (full_name, identifier, phone_number, password_hash, role)
                            VALUES (:name, :email, 'Google Account', :pass, 'patient')
                        ");
                        $insertStmt->execute([
                            ':name'  => $name,
                            ':email' => $email,
                            ':pass'  => $randomPassword
                        ]);

                        $newUserId = $pdo->lastInsertId();

                        $_SESSION['user_id']   = $newUserId;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_role'] = 'patient';

                        header("Location: patient/patient.php");
                        exit;
                    }
                }
            } else {
                $auth_error = "Could not verify Google account.";
                $open_modal_on_load = 'loginModal';
            }
        }
    }
}




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
    $userIp          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    
    $maxAttempts   = 5;
    $lockoutWindow = 15 * 60; 
    $currentTime   = time();

    
    $checkThrottling = $pdo->prepare("
        SELECT attempts, last_attempt 
        FROM login_attempts 
        WHERE ip_address = :ip AND identifier = :ident 
        LIMIT 1
    ");
    $checkThrottling->execute([
        ':ip'    => $userIp,
        ':ident' => $loginIdentifier
    ]);
    $throttle = $checkThrottling->fetch(PDO::FETCH_ASSOC);

    
    if ($throttle && $throttle['attempts'] >= $maxAttempts && ($currentTime - $throttle['last_attempt']) < $lockoutWindow) {
        $remainingSeconds = $lockoutWindow - ($currentTime - $throttle['last_attempt']);
        $remainingMinutes = ceil($remainingSeconds / 60);

        $auth_error = "Too many failed login attempts. For security reasons, this account is temporarily locked for {$remainingMinutes} minute(s).";
        $open_modal_on_load = 'loginModal';
    } elseif (empty($loginIdentifier) || empty($loginPassword)) {
        $auth_error = "Please enter both identifier and password.";
        $open_modal_on_load = 'loginModal';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE identifier = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $loginIdentifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($loginPassword, $user['password_hash'])) {
            
            $clearAttempts = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip AND identifier = :ident");
            $clearAttempts->execute([
                ':ip'    => $userIp,
                ':ident' => $loginIdentifier
            ]);

            
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
            
            if ($throttle) {
                
                $newAttempts = (($currentTime - $throttle['last_attempt']) > $lockoutWindow) ? 1 : ($throttle['attempts'] + 1);

                $updateAttempts = $pdo->prepare("
                    UPDATE login_attempts 
                    SET attempts = :att, last_attempt = :t 
                    WHERE ip_address = :ip AND identifier = :ident
                ");
                $updateAttempts->execute([
                    ':att'   => $newAttempts,
                    ':t'     => $currentTime,
                    ':ip'    => $userIp,
                    ':ident' => $loginIdentifier
                ]);
                $attemptsUsed = $newAttempts;
            } else {
                $insertAttempt = $pdo->prepare("
                    INSERT INTO login_attempts (ip_address, identifier, attempts, last_attempt) 
                    VALUES (:ip, :ident, 1, :t)
                ");
                $insertAttempt->execute([
                    ':ip'    => $userIp,
                    ':ident' => $loginIdentifier,
                    ':t'     => $currentTime
                ]);
                $attemptsUsed = 1;
            }

            $remainingTries = max(0, $maxAttempts - $attemptsUsed);

            if ($remainingTries > 0) {
                $auth_error = "Invalid Email/Username or Password." . " ({$remainingTries} attempt(s) remaining before 15-min lockout).";
            } else {
                $auth_error = "Too many failed attempts. Your account has been temporarily locked out for 15 minutes.";
            }

            $open_modal_on_load = 'loginModal';
        }
    }
}




$docScheduleStmt = $pdo->prepare("
    SELECT id, full_name, department, phone_number 
    FROM users 
    WHERE role = 'doctor' AND department IS NOT NULL 
    ORDER BY department ASC, full_name ASC
");
$docScheduleStmt->execute();
$publicDoctors = $docScheduleStmt->fetchAll(PDO::FETCH_ASSOC);

function getDoctorShift($dept) {
    switch ($dept) {
        case 'Cardiology':
            return ['days' => 'Mon – Thu', 'hours' => '08:00 AM – 04:00 PM', 'room' => 'Room 302B (Heart Wing)'];
        case 'Pediatrics':
            return ['days' => 'Mon – Fri', 'hours' => '09:00 AM – 03:30 PM', 'room' => 'Room 105 (Children Care)'];
        case 'Dermatology':
            return ['days' => 'Tue – Sat', 'hours' => '10:00 AM – 05:00 PM', 'room' => 'Room 204 (Skin Clinic)'];
        case 'General Medicine':
            return ['days' => 'Mon – Sat', 'hours' => '08:00 AM – 06:00 PM', 'room' => 'OPD Consultation Wing'];
        default:
            return ['days' => 'Mon – Fri', 'hours' => '09:00 AM – 04:00 PM', 'room' => 'General Clinical Wing'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosplinic Management System</title>

    <!--
            
    <link rel="preconnect" href="//fonts.googleapis.com">
    <link rel="preconnect" href="//fonts.gstatic.com" crossorigin>
    <link href="//fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

 
    <link rel="stylesheet" href="styles/index.css">


    <script src="//accounts.google.com/gsi/client" async defer></script>
        
     -->


    <link rel="preconnect" href="//fonts.googleapis.com">
    <link rel="preconnect" href="//fonts.gstatic.com" crossorigin>
    <link href="//fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/index.css">
    <script src="//accounts.google.com/gsi/client" async defer></script>
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
        .alert--error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert--success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .alert--info { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .google-btn-container {
            display: flex;
            justify-content: center;
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .clinic-hours-banner {
            background: #f8fafc;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .clinic-hours-item h5 {
            font-size: 0.825rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .clinic-hours-item p {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
        }

        .doctor-schedule-list {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            max-height: 380px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .doctor-schedule-item {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            transition: var(--transition);
        }

        .doctor-schedule-item:hover {
            box-shadow: var(--shadow-sm);
            border-left-color: var(--accent-teal);
        }

        .doc-info h4 {
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .doc-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin: 0;
        }

        .doc-timing {
            text-align: right;
        }

        .doc-timing strong {
            display: block;
            font-size: 0.875rem;
            color: var(--primary-dark);
        }

        .doc-timing small {
            font-size: 0.75rem;
            color: var(--text-muted);
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
            <h2>Hosplinic</h2>
            <p>Access patient services, check doctor schedules, and manage clinical consultations.</p>
        </div>

        <div class="dashboard__grid">
            
            <div class="card" id="openRegisterModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--blue">
            <!--<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>-->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Patient Registration</h3>
                    <p class="card__desc">Create a new patient account using Google or your credentials to book and manage visits.</p>
                </div>
                <span class="card__link">Register Now &rarr;</span>
            </div>

            <!-- Card 2: Schedule -->
            <div class="card" id="openScheduleModal" role="button" tabindex="0">
                <div>
                    <div class="card__icon card__icon--teal">
            <!--<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>-->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="card__title">Doctors' Availability Schedule & Clinic Hours</h3>
                    <p class="card__desc">Check when our medical specialists are available, view weekly shift timings, and review clinic hours.</p>
                    
                    <div style="margin-top: 1rem;">
                        <span class="pill pill--ok"><?= count($publicDoctors) ?> Specialists on Duty</span>
                    </div>
                </div>
                <span class="card__link">View Availability & Hours &rarr;</span>
            </div>
        </div>
    </main>

    <!-- Hidden Form to submit Google JWT Token to PHP -->
    <form id="googleAuthForm" action="index.php" method="POST" style="display: none;">
        <input type="hidden" name="action" value="google_login">
        <input type="hidden" name="auth_source" id="googleAuthSource" value="login">
        <input type="hidden" name="credential" id="googleCredentialInput">
    </form>

    <!-- LOGIN MODAL -->
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

                <div class="google-btn-container" onclick="setGoogleSource('login')">
                    <div id="googleSignInButtonLogin"></div>
                </div>

                <div class="divider">or login with credentials</div>

                <form action="index.php" method="POST" id="loginForm">
                    <input type="hidden" name="action" value="login">

                    <div class="form-group">
                        <label for="loginIdentifier">Email or Username</label>
                        <input type="text" id="loginIdentifier" name="loginIdentifier" placeholder="Enter your email or username" required>
                    </div>

                    <div class="form-group">
                        <label for="loginPassword">Password</label>
                        <input type="password" id="loginPassword" name="loginPassword" placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn btn--primary" style="width: 100%; margin-top: 1rem;">Log In</button>
                </form>

                <p class="modal-switch-text">
                    Don't have an account? <a href="#" id="switchToRegister">Register</a>
                </p>
            </div>
        </div>
    </div>

    <!-- GOOGLE REGISTRATION CONFIRMATION MODAL -->
    <div class="modal-overlay" id="googleConfirmModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Account Not Found</h3>
                <button class="modal__close" data-close="googleConfirmModal">&times;</button>
            </div>
            <div class="modal__body">
                <?php if (isset($_SESSION['pending_google_user'])): ?>
                    <div class="alert alert--info">
                        <strong>No account exists for:</strong><br>
                        <?= htmlspecialchars($_SESSION['pending_google_user']['email']) ?> (<?= htmlspecialchars($_SESSION['pending_google_user']['name']) ?>)
                    </div>

                    <p style="font-size: 0.9rem; color: var(--text-body); margin-bottom: 1.25rem;">
                        Would you like to create a new patient account using this Google profile?
                    </p>

                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="confirm_google_register">

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label for="gPhone">Phone Number (Optional)</label>
                            <input type="tel" name="phoneNumber" id="gPhone" placeholder="e.g. +1 555-019-2834">
                        </div>

                        <div style="display:flex; gap:0.75rem;">
                            <button type="submit" class="btn btn--primary" style="flex:1;">Yes, Register with Google</button>
                            <button type="button" class="btn btn--outline" data-close="googleConfirmModal">Cancel</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- REGISTER MODAL -->
    <div class="modal-overlay" id="registerModal" aria-hidden="true">
        <div class="modal">
            <div class="modal__header">
                <h3>Create an Account</h3>
                <button class="modal__close" data-close="registerModal">&times;</button>
            </div>
            
            <div class="modal__body">
                <?php if (!empty($auth_error) && $open_modal_on_load === 'registerModal'): ?>
                    <div class="alert alert--error"><?= htmlspecialchars($auth_error) ?></div>
                <?php endif; ?>

                <div class="google-btn-container" onclick="setGoogleSource('register')">
                    <div id="googleSignInButtonRegister"></div>
                </div>

                <div class="divider">or continue with credentials</div>

                <form action="index.php" method="POST" id="registrationForm">
                    <input type="hidden" name="action" value="register">

                    <div class="form-group">
                        <label for="fullName">Full Name</label>
                        <input type="text" id="fullName" name="fullName" placeholder="e.g. Sarah Jenkins" required>
                    </div>

                    <div class="form-group">
                        <label for="identifier">Email or Username</label>
                        <input type="text" id="identifier" name="identifier" placeholder="e.g. sarah.j@example.com" required>
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

                    <div style="display:flex; gap:1rem;">
                        <button type="submit" class="btn btn--primary" style="flex:1;">Create Account</button>
                        
                    </div>
                </form>

                <p class="modal-switch-text">
                    Already have an account? <a href="#" id="switchToLogin">Log in</a>
                </p>
            </div>
        </div>
    </div>

    <!-- MODAL: SCHEDULE & HOURS -->
    <div class="modal-overlay" id="scheduleModal" aria-hidden="true">
        <div class="modal modal--wide">
            <div class="modal__header">
                <h3>Doctor Availability & Clinic Hours</h3>
                <button class="modal__close" data-close="scheduleModal">&times;</button>
            </div>
            <div class="modal__body">

                <div class="clinic-hours-banner">
                    <div class="clinic-hours-item">
                        <h5>Outpatient Clinic</h5>
                        <p>Mon – Sat: 08:00 AM – 06:00 PM</p>
                    </div>
                    <div class="clinic-hours-item">
                        <h5>Diagnostics / Lab</h5>
                        <p>Mon – Sat: 06:30 AM – 07:00 PM</p>
                    </div>
                    <div class="clinic-hours-item">
                        <h5>Emergency & Triage</h5>
                        <p style="color: #dc2626;">24 Hours / 7 Days a Week</p>
                    </div>
                </div>

                <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; color: var(--text-dark);">
                    Specialist Consultation Availability (By Department)
                </h4>

                <div class="doctor-schedule-list">
                    <?php if (count($publicDoctors) > 0): ?>
                        <?php foreach ($publicDoctors as $doc): ?>
                            <?php $shift = getDoctorShift($doc['department']); ?>
                            <div class="doctor-schedule-item">
                                <div class="doc-info">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                                        <span class="pill pill--ok" style="font-size: 0.75rem;">
                                            <?= htmlspecialchars($doc['department']) ?>
                                        </span>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">&#128205; <?= $shift['room'] ?></span>
                                    </div>
                                    <h4><?= htmlspecialchars($doc['full_name']) ?></h4>
                                    <p>Contact: <?= htmlspecialchars($doc['phone_number']) ?></p>
                                </div>

                                <div class="doc-timing">
                                    <strong><?= $shift['days'] ?></strong>
                                    <small><?= $shift['hours'] ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 2rem 0;">
                            No doctors currently registered in the database.
                        </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                        Ready to consult? Register or log in to secure your slot.
                    </p>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="button" class="btn btn--primary" onclick="closeModal('scheduleModal'); openModal('registerModal');">
                            Book an Appointment
                        </button>
                        <button type="button" class="btn btn--outline" data-close="scheduleModal">Close</button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Hosplinic Management System</p>
    </footer>

    <script>
        let currentAuthSource = 'login';
        function setGoogleSource(source) {
            currentAuthSource = source;
            document.getElementById('googleAuthSource').value = source;
        }

        function handleGoogleCredentialResponse(response) {
            document.getElementById('googleCredentialInput').value = response.credential;
            document.getElementById('googleAuthForm').submit();
        }

        window.onload = function () {
            if (typeof google !== 'undefined') {
                google.accounts.id.initialize({
                    client_id: "<?= $google_client_id ?>",
                    callback: handleGoogleCredentialResponse
                });

                google.accounts.id.renderButton(
                    document.getElementById("googleSignInButtonLogin"),
                    { theme: "outline", size: "large", width: "320", text: "continue_with" }
                );

                google.accounts.id.renderButton(
                    document.getElementById("googleSignInButtonRegister"),
                    { theme: "outline", size: "large", width: "320", text: "signup_with" }
                );
            }
        };

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
        document.getElementById('openScheduleModal').addEventListener('click', () => openModal('scheduleModal'));

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
    </script>
</body>
</html>