<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app.php';
require dirname(__DIR__) . '/certificate.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_GET['contoh'] ?? null) === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    readfile(CERTIFICATE_TEMPLATE);
    exit;
}

startAppSession();
$_SESSION['absensi_secret'] ??= bin2hex(random_bytes(32));
$secret = $_SESSION['absensi_secret'];
session_write_close();
header('Cache-Control: no-store');

$fields = attendanceFields();
$token = newFormToken($secret);
$values = $errors = [];
$message = null;
$name = null;
$receipt = stringValue($_GET, 'bukti');
$certificate = stringValue($_GET, 'sertifikat');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = stringValue($_POST, 'token');
    $key = formKey($submittedToken, $secret);
    [$values, $errors] = validateAttendance($_POST);

    if ($key === null) {
        http_response_code(400);
        $message = 'Sesi formulir sudah berakhir. Silakan kirim ulang data di bawah ini.';
    } else {
        $token = $submittedToken;

        if ($errors) {
            http_response_code(422);
            $message = 'Periksa kembali data yang ditandai.';
        } else {
            try {
                saveAttendance($key, $values);
                header('Location: ?bukti=' . $token, true, 303);
                exit;
            } catch (PDOException $error) {
                error_log($error->getMessage());
                http_response_code(503);
                $message = 'Absensi belum dapat disimpan. Silakan coba lagi.';
            }
        }
    }
} elseif (array_key_exists('bukti', $_GET)) {
    try {
        $key = formKey($receipt, $secret);
        $name = $key === null ? null : participantName($key);

        if ($name === null) {
            http_response_code(404);
            $message = 'Bukti absensi tidak tersedia pada sesi ini.';
        } elseif ($certificate === 'lihat' || $certificate === 'unduh') {
            $image = createCertificate($name);
            header('Content-Type: image/png');

            if ($certificate === 'unduh') {
                header('Content-Disposition: attachment; filename="sertifikat-sosialisasi-hpv.png"');
            }

            imagepng($image);
            exit;
        }
    } catch (PDOException | RuntimeException $error) {
        error_log($error->getMessage());
        http_response_code(503);

        if ($certificate !== '') {
            header('Content-Type: text/plain; charset=utf-8');
            exit('Sertifikat belum dapat dimuat. Silakan coba lagi.');
        }

        $message = 'Bukti absensi belum dapat dimuat. Silakan muat ulang halaman ini.';
    }
}

$success = $name !== null;
$showForm = !$success && ($_SERVER['REQUEST_METHOD'] === 'POST' || !array_key_exists('bukti', $_GET));
$certificateUrl = '?bukti=' . $receipt . '&sertifikat=';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fffaf7">
    <title><?= $success ? 'Absensi Berhasil' : 'Absensi Kegiatan' ?> · DWP Kota Padang</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="page">
    <header class="brand">
        <svg class="brand-mark" width="44" height="44" viewBox="0 0 44 44" aria-hidden="true">
            <rect width="44" height="44" rx="13" fill="currentColor"/>
            <path d="M22 33v-8m0 0c-7 0-11-4-11-10 6 0 11 4 11 10Zm0 0c7 0 11-4 11-10-6 0-11 4-11 10Zm0-8c-4-4-3-7 0-10 3 3 4 6 0 10Z" fill="none" stroke="#fff4ed" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div><strong>Dharma Wanita Persatuan</strong><span>Kota Padang</span></div>
    </header>

    <main class="layout">
        <section class="event" aria-labelledby="event-title">
            <div class="event-details">
                <p class="eyebrow">Absensi kegiatan</p>
                <h1 id="event-title">Sosialisasi <br>HPV</h1>
                <div class="event-date">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/></svg>
                    <time datetime="2026-11-11">Rabu, 11 November 2026</time>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="card-title">
            <?php if ($success): ?>
                <div class="success-mark" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
                </div>
                <h2 id="card-title">Absensi berhasil</h2>
                <p>Terima kasih, <strong><?= escape($name) ?></strong>. Kehadiran Anda sudah tercatat dan sertifikat siap diunduh.</p>
                <a class="button" href="<?= escape($certificateUrl . 'unduh') ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 16v4h16v-4"/></svg>
                    Unduh sertifikat (PNG)
                </a>
                <a class="text-link" href="./">Isi absensi baru</a>
            <?php elseif ($showForm): ?>
                <h2 id="card-title">Daftar hadir</h2>
                <p>Lengkapi data kehadiran Anda. Semua kolom wajib diisi.</p>
                <?php if ($message !== null): ?>
                    <p class="notice" role="alert"><?= escape($message) ?></p>
                <?php endif; ?>
                <form method="post" action="./">
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <?php foreach ($fields as $fieldName => $field): ?>
                        <?php $phone = $fieldName === 'no_whatsapp'; ?>
                        <div class="field">
                            <label for="<?= $fieldName ?>"><?= escape($field['label']) ?></label>
                            <input id="<?= $fieldName ?>" name="<?= $fieldName ?>" type="<?= $phone ? 'tel' : 'text' ?>"
                                   maxlength="<?= $field['max'] ?>" autocomplete="<?= escape($field['autocomplete']) ?>"
                                   value="<?= escape($values[$fieldName] ?? '') ?>" required
                                   <?php if ($phone): ?>placeholder="Contoh 081234567890"<?php endif; ?>
                                   <?php if (isset($errors[$fieldName])): ?>aria-invalid="true" aria-describedby="<?= $fieldName ?>-error"
                                   <?php elseif ($fieldName === 'nama_lengkap'): ?>aria-describedby="nama-lengkap-info"<?php endif; ?>>
                            <?php if (isset($errors[$fieldName])): ?>
                                <span class="field-error" id="<?= $fieldName ?>-error"><?= escape($errors[$fieldName]) ?></span>
                            <?php elseif ($fieldName === 'nama_lengkap'): ?>
                                <span class="hint" id="nama-lengkap-info">Nama ini akan tercetak di sertifikat.</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button class="button" type="submit">Simpan absensi
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg>
                    </button>
                </form>
            <?php else: ?>
                <h2 id="card-title">Bukti absensi</h2>
                <p><?= escape($message ?? '') ?></p>
                <a class="button" href="./">Buka formulir absensi</a>
            <?php endif; ?>
        </section>

        <figure class="certificate">
            <figcaption><?= $success ? 'Sertifikat Anda' : 'Contoh sertifikat peserta' ?></figcaption>
            <img width="1280" height="904"
                 src="<?= $success ? escape($certificateUrl . 'lihat') : '?contoh=1' ?>"
                 alt="<?= $success ? escape('Sertifikat peserta atas nama ' . $name) : 'Template sertifikat peserta Sosialisasi HPV' ?>">
        </figure>
    </main>

    <footer>Dharma Wanita Persatuan Kota Padang</footer>
</div>
</body>
</html>
