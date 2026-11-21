<?php
/**
 * Crea (o promuove) un amministratore.
 *   php cli/create_admin.php --email=admin@example.org --nickname=admin
 * La password viene chiesta in modo interattivo.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\Security;

$o = getopt('', ['email:', 'nickname:', 'password:']);
$email = strtolower(trim((string) ($o['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php cli/create_admin.php --email=... --nickname=...\n");
    exit(1);
}
$existing = Database::one('SELECT id FROM listenin_users WHERE email = :e', ['e' => $email]);
if ($existing) {
    Database::run("UPDATE listenin_users SET role = 'admin', status = 'active', email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id", ['id' => $existing['id']]);
    echo "Utente esistente promosso ad amministratore.\n";
    exit(0);
}
$nickname = trim((string) ($o['nickname'] ?? 'admin'));
$password = (string) ($o['password'] ?? '');
if ($password === '') {
    echo 'Password (min ' . Config::get('security.password_min_length', 10) . ' caratteri): ';
    if (DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty echo');
    }
    echo "\n";
}
if (mb_strlen($password) < (int) Config::get('security.password_min_length', 10)) {
    fwrite(STDERR, "Password troppo corta.\n");
    exit(1);
}
Database::run(
    "INSERT INTO listenin_users (email, email_verified_at, nickname, password_hash, role, status, terms_accepted_at, disclaimer_version, disclaimer_accepted_at)
     VALUES (:e, NOW(), :n, :p, 'admin', 'active', NOW(), :v, NOW())",
    ['e' => $email, 'n' => $nickname, 'p' => Security::hashPassword($password), 'v' => (string) Config::get('legal.disclaimer_version')]
);
echo "Amministratore creato.\n";
