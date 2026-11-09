<?php

declare(strict_types=1);

function appSettings(): array
{
    $local = __DIR__ . '/config.local.php';

    return is_file($local) ? require $local : require __DIR__ . '/config.example.php';
}

function database(): PDO
{
    return connectMysql(appSettings());
}

function connectMysql(array $settings, bool $selectDatabase = true): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $settings['host'], $settings['port']);

    if ($selectDatabase) {
        $dsn .= ';dbname=' . $settings['name'];
    }

    return new PDO($dsn, $settings['user'], $settings['password'], [
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
    ]);
}

function isHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function isLocalDevRequest(): bool
{
    return PHP_SAPI === 'cli-server'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && preg_match('/\A(?:127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?\z/i', $_SERVER['HTTP_HOST'] ?? '');
}

function startAppSession(): void
{
    session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => true,
        'cookie_secure' => isHttps(),
        'cookie_samesite' => 'Lax',
    ]);
}

function stringValue(array $input, string $name): string
{
    return is_string($input[$name] ?? null) ? $input[$name] : '';
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function newFormToken(string $secret): string
{
    $key = bin2hex(random_bytes(32));

    return $key . '.' . hash_hmac('sha256', $key, $secret);
}

function formKey(string $token, string $secret): ?string
{
    if (!preg_match('/\A([a-f0-9]{64})\.([a-f0-9]{64})\z/', $token, $parts)) {
        return null;
    }

    return hash_equals(hash_hmac('sha256', $parts[1], $secret), $parts[2]) ? $parts[1] : null;
}

function attendanceFields(): array
{
    return [
        'nama_lengkap' => ['label' => 'Nama Lengkap', 'max' => 120, 'autocomplete' => 'name'],
        'utusan_opd' => ['label' => 'Utusan OPD', 'max' => 150, 'autocomplete' => 'organization'],
        'no_whatsapp' => ['label' => 'No. WhatsApp', 'max' => 30, 'autocomplete' => 'tel'],
        'nama_suami' => ['label' => 'Nama Suami', 'max' => 120, 'autocomplete' => 'off'],
    ];
}

function validateAttendance(array $input): array
{
    $values = $errors = [];

    foreach (attendanceFields() as $name => $field) {
        $value = stringValue($input, $name);
        $value = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? '');
        $length = mb_strlen($value, 'UTF-8');
        $values[$name] = mb_substr($value, 0, $field['max'], 'UTF-8');

        if ($value === '') {
            $errors[$name] = $field['label'] . ' wajib diisi.';
        } elseif ($length > $field['max'] || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            $errors[$name] = 'Gunakan maksimal ' . $field['max'] . ' karakter dalam satu baris.';
        } elseif ($name === 'no_whatsapp') {
            $number = str_replace([' ', '.', '-', '(', ')'], '', $value);

            if (!preg_match('/\A\+?[0-9]{8,15}\z/', $number)) {
                $errors[$name] = 'Masukkan nomor WhatsApp dengan 8–15 digit.';
            } else {
                $values[$name] = $number;
            }
        }
    }

    return [$values, $errors];
}

function saveAttendance(string $key, array $values): void
{
    $statement = database()->prepare(
        'INSERT INTO absensi (kunci_form, nama_lengkap, utusan_opd, no_whatsapp, nama_suami)
         VALUES (UNHEX(:kunci), :nama_lengkap, :utusan_opd, :no_whatsapp, :nama_suami)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $statement->execute(['kunci' => $key] + $values);
}

function participantName(string $key): ?string
{
    $statement = database()->prepare('SELECT nama_lengkap FROM absensi WHERE kunci_form = UNHEX(?)');
    $statement->execute([$key]);
    $name = $statement->fetchColumn();

    return $name === false ? null : $name;
}
