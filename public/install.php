<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app.php';
require $root . '/certificate.php';

function prepareDatabase(array $settings, string $schema): void
{
    try {
        $db = connectMysql($settings);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1049) {
            throw $error;
        }

        $server = connectMysql($settings, false);
        // The form validates the identifier before it reaches this statement.
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $settings['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db = connectMysql($settings);
    }

    $checkTable = 'SELECT id, kunci_form, nama_lengkap, utusan_opd, no_whatsapp, nama_suami, waktu_hadir FROM absensi LIMIT 0';

    try {
        $db->query($checkTable);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1146) {
            throw $error;
        }

        $db->exec($schema);
        $db->query($checkTable);
    }
}

function installApplication(string $root, array $settings): void
{
    $config = $root . '/config.local.php';
    $lock = @fopen($root . '/.install.lock', 'c');

    if ($lock === false) {
        throw new RuntimeException('Folder proyek harus bisa ditulis oleh PHP untuk menyimpan konfigurasi.');
    }

    $temp = false;

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Instalasi sedang berjalan. Coba buka ulang halaman ini.');
        }

        clearstatcache(true, $config);
        if (file_exists($config) || is_link($config)) {
            throw new RuntimeException('Konfigurasi sudah tersimpan.');
        }

        $schema = @file_get_contents($root . '/schema.sql');
        if ($schema === false || trim($schema) === '') {
            throw new RuntimeException('Berkas schema.sql tidak dapat dibaca.');
        }

        $temp = @tempnam($root, '.config-install-');
        if ($temp === false || realpath(dirname($temp)) !== realpath($root)) {
            throw new RuntimeException('Berkas konfigurasi tidak dapat dibuat di folder proyek.');
        }

        prepareDatabase($settings, $schema);

        $contents = '<?php' . "\n\nreturn " . var_export($settings, true) . ";\n";
        if (@file_put_contents($temp, $contents) !== strlen($contents) || !@rename($temp, $config)) {
            throw new RuntimeException('Konfigurasi gagal disimpan. Periksa izin tulis folder proyek.');
        }
    } finally {
        if ($temp !== false && is_file($temp)) {
            unlink($temp);
        }
        fclose($lock);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$configPath = $root . '/config.local.php';
$installed = file_exists($configPath) || is_link($configPath);
$local = isLocalDevRequest();
$setupKey = $installed || $local ? '' : trim((string) @file_get_contents($root . '/install.key'));
$allowed = $local || (isHttps() && preg_match('/\A[a-f0-9]{64}\z/', $setupKey));
$message = null;
$showForm = false;
$settings = require $root . '/config.example.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if ($installed) {
    if ($method === 'POST') {
        http_response_code(409);
    }
} elseif (!$allowed) {
    http_response_code(403);
    $message = 'Buka installer dari localhost atau aktifkan kunci instalasi melalui HTTPS.';
} elseif (PHP_VERSION_ID < 80100 || !extension_loaded('pdo_mysql') || !extension_loaded('mbstring')
    || !function_exists('imagecreatefromjpeg') || !function_exists('imagepng') || !function_exists('imagettfbbox')) {
    http_response_code(503);
    $message = 'Installer memerlukan PHP 8.1 atau lebih baru, pdo_mysql, mbstring, dan GD dengan dukungan JPEG, PNG, serta FreeType.';
} else {
    startAppSession();
    $_SESSION['install_token'] ??= bin2hex(random_bytes(32));
    $token = $_SESSION['install_token'];
    session_write_close();
    header('Cache-Control: no-store');
    $showForm = true;

    if ($method === 'POST') {
        foreach (['host', 'port', 'name', 'user', 'certificate_font'] as $field) {
            $settings[$field] = trim(stringValue($_POST, $field));
        }

        try {
            if (!hash_equals($token, stringValue($_POST, 'token'))) {
                throw new InvalidArgumentException('Sesi instalasi sudah berakhir. Silakan kirim ulang formulir.', 400);
            }
            if (!$local && !hash_equals($setupKey, stringValue($_POST, 'install_key'))) {
                throw new InvalidArgumentException('Kunci instalasi tidak cocok.', 403);
            }

            $host = $settings['host'];
            $validHost = preg_match('/\A[a-z0-9][a-z0-9.-]{0,252}\z/i', $host) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            $port = filter_var($settings['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if (!$validHost || $port === false) {
                throw new InvalidArgumentException('Masukkan host MySQL dan port antara 1–65535.', 422);
            }
            if (!preg_match('/\A[a-z0-9_-]{1,64}\z/i', $settings['name']) || $settings['user'] === '') {
                throw new InvalidArgumentException('Isi pengguna MySQL dan nama database yang berisi huruf, angka, garis bawah, atau tanda hubung.', 422);
            }

            $password = stringValue($_POST, 'password');
            if (str_contains($settings['user'], "\0") || str_contains($password, "\0")) {
                throw new InvalidArgumentException('Pengguna atau kata sandi MySQL memuat karakter yang tidak valid.', 422);
            }

            $customFont = $settings['certificate_font'];
            try {
                $font = $customFont === '' ? certificateFont() : @realpath($customFont);
            } catch (RuntimeException | ValueError $error) {
                $font = false;
            }
            if ($font === false || !is_file($font) || @imagettfbbox(12, 0, $font, 'Sertifikat') === false) {
                throw new InvalidArgumentException('Masukkan path ke berkas font .ttf yang dapat dibaca oleh PHP.', 422);
            }
            if (@imagecreatefromjpeg(CERTIFICATE_TEMPLATE) === false) {
                throw new RuntimeException('Template sertifikat.jpeg tidak dapat dibuka.');
            }

            installApplication($root, array_replace($settings, [
                'port' => $port,
                'password' => $password,
                'certificate_font' => $customFont === '' ? null : $font,
            ]));
            header('Location: install.php', true, 303);
            exit;
        } catch (InvalidArgumentException $error) {
            http_response_code($error->getCode());
            $message = $error->getMessage();
        } catch (PDOException $error) {
            $code = (int) ($error->errorInfo[1] ?? 0);
            http_response_code(422);
            $message = match ($code) {
                1045, 1698 => 'Periksa pengguna dan kata sandi MySQL.',
                1044, 1142, 1227 => 'Periksa izin akun MySQL pada database tujuan. Jika database belum ada, buat dulu atau gunakan akun dengan izin membuat database.',
                2002, 2003, 2005 => 'MySQL tidak dapat dihubungi. Periksa host, port, dan status server MySQL.',
                1054 => 'Struktur tabel absensi yang ada berbeda dengan schema.sql. Pilih database aplikasi yang sesuai.',
                default => 'MySQL gagal menyiapkan database. Periksa koneksi, nama database, dan izin akun.',
            };
            error_log('Instalasi absensi gagal dengan kode MySQL ' . $code);
        } catch (RuntimeException $error) {
            http_response_code(503);
            $message = $error->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalasi Absensi · DWP Kota Padang</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page installer">
    <header>
        <a class="brand" href="./" aria-label="Dharma Wanita Persatuan Kota Padang, halaman utama absensi">
            <div><strong>Dharma Wanita Persatuan</strong><span>Kota Padang</span></div>
        </a>
    </header>
    <section class="card" aria-labelledby="install-title">
        <h1 id="install-title"><?= $installed ? 'Aplikasi sudah dikonfigurasi' : 'Instalasi absensi' ?></h1>
        <?php if ($installed): ?>
            <p>Konfigurasi MySQL sudah tersimpan.</p>
            <a class="button" href="./">Buka formulir absensi</a>
        <?php else: ?>
            <?php if ($message !== null): ?>
                <p class="notice" role="alert"><?= escape($message) ?></p>
            <?php endif; ?>
            <?php if ($showForm): ?>
                <p>Masukkan pengaturan MySQL untuk menyiapkan tabel absensi dan menyimpan konfigurasi.</p>
                <form method="post" action="install.php" autocomplete="off">
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <div class="installer-connection">
                        <div class="field">
                            <label for="host">Host MySQL</label>
                            <input id="host" name="host" value="<?= escape($settings['host']) ?>" required maxlength="253">
                        </div>
                        <div class="field">
                            <label for="port">Port</label>
                            <input id="port" name="port" type="number" value="<?= escape((string) $settings['port']) ?>" required min="1" max="65535">
                        </div>
                    </div>
                    <div class="field">
                        <label for="name">Nama database</label>
                        <input id="name" name="name" value="<?= escape($settings['name']) ?>" required maxlength="64" aria-describedby="database-hint">
                        <span class="hint" id="database-hint">Database yang belum ada akan dibuat jika akun MySQL memiliki izin.</span>
                    </div>
                    <div class="field">
                        <label for="user">Pengguna MySQL</label>
                        <input id="user" name="user" value="<?= escape($settings['user']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="password">Kata sandi MySQL</label>
                        <input id="password" name="password" type="password">
                    </div>
                    <div class="field">
                        <label for="certificate_font">Path font sertifikat (opsional)</label>
                        <input id="certificate_font" name="certificate_font" value="<?= escape($settings['certificate_font'] ?? '') ?>" aria-describedby="font-hint">
                        <span class="hint" id="font-hint">Kosongkan untuk memakai font bawaan server.</span>
                    </div>
                    <?php if (!$local): ?>
                        <div class="field">
                            <label for="install_key">Kunci instalasi</label>
                            <input id="install_key" name="install_key" type="password" required maxlength="64">
                        </div>
                    <?php endif; ?>
                    <button class="button" type="submit">Jalankan instalasi</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
