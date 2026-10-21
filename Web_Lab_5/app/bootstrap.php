<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
session_name('transport_session');
session_start(['cookie_httponly'=>true, 'cookie_samesite'=>'Lax',
    'cookie_secure'=>str_starts_with(getenv('APP_URL') ?: '', 'https://'), 'use_strict_mode'=>true]);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');
final class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = 'mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';dbname='.(getenv('DB_NAME') ?: 'transport_clients').';charset=utf8mb4';
        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'transport', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function run(string $sql, array $values = []): PDOStatement {
    $q = db()->prepare($sql); $q->execute($values); return $q;
}
function transaction(callable $action): mixed {
    db()->beginTransaction();
    try { $result=$action(); db()->commit(); return $result; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key, bool $trim = true): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) throw new AppError('Неприпустимий формат поля.');
    return $trim ? trim($v) : $v;
}
function field(string $key, string $label, int $min, int $max): string {
    $v=input($key); $n=preg_match_all('/./us',$v);
    if ($n===false || $n<$min || $n>$max) throw new AppError("$label: від $min до $max символів.");
    return $v;
}
function integer(string $key, int $min=1, int $max=1000000): int {
    $v=input($key);
    if (!preg_match('/^[0-9]{1,9}$/D',$v) || (int)$v<$min || (int)$v>$max) throw new AppError("Поле $key: потрібне ціле число від $min до $max.");
    return (int)$v;
}
function csrf(): string {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';
}
function check_csrf(): void {
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],input('csrf'))) throw new AppError('Сеанс форми завершився. Оновіть сторінку.',403);
}
function client(): ?array {
    return isset($_SESSION['client_id']) ? (run('SELECT * FROM clients WHERE id=?',[$_SESSION['client_id']])->fetch() ?: null) : null;
}
function require_client(bool $verified=false): array {
    $c=client();
    if (!$c) throw new AppError('Увійдіть до особистого кабінету.',401);
    if ($verified && !$c['email_verified_at']) throw new AppError('Спочатку підтвердьте свою email адресу.',403);
    return $c;
}
function flash(string $message): void { $_SESSION['flash']=$message; }
function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
function local_date(string $utc): string { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i'); }
function order_number(int $id): string { return 'TR-'.str_pad((string)$id,6,'0',STR_PAD_LEFT); }
function status_label(string $s): string { return ['awaiting_email'=>'Очікує email','confirmed'=>'Підтверджено','cancelled'=>'Скасовано'][$s] ?? $s; }
