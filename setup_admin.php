<?php
require_once 'db.php';
$username = 'admin@hosplinic';
$password = 'hosplinic@admin';

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

try {

    $stmt = $pdo->prepare("SELECT id FROM users WHERE identifier = :identifier");
    $stmt->execute([':identifier' => $username]);
    $exists = $stmt->fetch();

    if ($exists) {
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash, role = 'admin' WHERE identifier = :identifier");
        $update->execute([':hash' => $hashedPassword, ':identifier' => $username]);
        echo "<h2 style='color: green;'>Success: Master Admin password was reset!</h2>";
    } else {
        $insert = $pdo->prepare("INSERT INTO users (full_name, identifier, phone_number, password_hash, role) 
                                 VALUES ('Master Administrator', :identifier, '0000000000', :hash, 'admin')");
        $insert->execute([':identifier' => $username, ':hash' => $hashedPassword]);
        echo "<h2 style='color: green;'>Success: Master Admin account created!</h2>";
    }

    echo "<p><strong>Username:</strong> $username</p>";
    echo "<p><strong>Password:</strong> $password</p>";
    echo "<p><a href='index.php'>Go to Login Page &rarr;</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color: red;'>Database Error:</h2> " . $e->getMessage();
}
?>