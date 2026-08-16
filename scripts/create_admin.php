<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require dirname(__DIR__) . '/config/database.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php \"Full Name\" email@example.com \"StrongPassword\"\n");
    exit(1);
}

[$script, $name, $email, $password] = $argv;
$name = trim($name);
$email = trim($email);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid name and email address.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Admin password must contain at least 12 characters.\n");
    exit(1);
}

$check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$check->execute([$email]);
if ($check->fetch()) {
    fwrite(STDERR, "A user with this email already exists.\n");
    exit(1);
}

$insert = $pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role, active, created_at)
     VALUES (?, ?, ?, ?, 1, NOW())'
);
$insert->execute([
    $name,
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    'Admin',
]);

echo "Admin account created successfully.\n";
