<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app.php';
require dirname(__DIR__) . '/attendance-report.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$settings = appSettings();
$adminUser = $settings['admin_username'] ?? 'admin';
$passwordHash = stringValue($settings, 'admin_password_hash');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$download = stringValue($_GET, 'unduh') === 'csv';
$signedIn = $showLogin = $dataReady = false;
$message = null;

if (!isHttps() && !isLocalDevRequest()) {
    http_response_code(403);
    $message = 'Buka panel admin melalui HTTPS.';
} elseif ($adminUser === '' || password_get_info($passwordHash)['algo'] === null) {
    http_response_code(503);
    $message = 'Panel admin belum diaktifkan. Atur akun admin di konfigurasi server.';
} else {
    session_name('DWPADMIN');
    session_cache_limiter('');
    startAppSession();
    $_SESSION['admin_token'] ??= bin2hex(random_bytes(32));
    $token = $_SESSION['admin_token'];
    $account = hash('sha256', $adminUser . "\0" . $passwordHash);
    $signedIn = hash_equals($account, stringValue($_SESSION, 'admin_account'))
        && ($_SESSION['admin_activity'] ?? 0) >= time() - 1200;

    if ($signedIn) {
        $_SESSION['admin_activity'] = time();
    } else {
        unset($_SESSION['admin_account'], $_SESSION['admin_activity']);
    }

    if ($method === 'POST') {
        $action = stringValue($_POST, 'aksi');

        if (!hash_equals($token, stringValue($_POST, 'token'))) {
            http_response_code(400);
            $message = 'Sesi formulir sudah berakhir. Silakan coba lagi.';
        } elseif ($action === 'keluar' && $signedIn) {
            $_SESSION = [];
            session_regenerate_id(true);
            session_write_close();
            header('Location: admin.php', true, 303);
            exit;
        } elseif ($action === 'masuk' && !$signedIn) {
            $password = stringValue($_POST, 'password');

            if (hash_equals($adminUser, stringValue($_POST, 'username'))
                && strlen($password) <= 72 && password_verify($password, $passwordHash)
                && session_regenerate_id(true)) {
                $_SESSION['admin_account'] = $account;
                $_SESSION['admin_activity'] = time();
                $_SESSION['admin_token'] = bin2hex(random_bytes(32));
                session_write_close();
                header('Location: admin.php', true, 303);
                exit;
            }

            http_response_code(422);
            $message = 'Nama pengguna atau kata sandi salah.';
        }
    }

    session_write_close();
    $showLogin = !$signedIn;
}

if ($download && !$signedIn) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Masuk ke panel admin untuk mengunduh laporan.');
}

if ($signedIn) {
    $filters = [
        'dari' => stringValue($_GET, 'dari'),
        'sampai' => stringValue($_GET, 'sampai'),
        'opd' => stringValue($_GET, 'opd'),
    ];
    $activeFilters = array_filter($filters, static fn (string $value): bool => $value !== '');
    $opds = [];

    try {
        foreach (array_keys($filters) as $field) {
            if (isset($_GET[$field]) && !is_string($_GET[$field])) {
                throw new InvalidArgumentException('Masukkan filter yang valid.');
            }
        }

        [$where, $params] = reportCriteria($filters);
        $db = connectMysql($settings);

        if ($download) {
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            downloadAttendanceReport(reportRows($db, $where, $params));
        }

        $opds = $db->query('SELECT DISTINCT utusan_opd FROM absensi ORDER BY utusan_opd')->fetchAll(PDO::FETCH_COLUMN);
        $summary = $db->prepare('SELECT utusan_opd, COUNT(*) AS total FROM absensi' . $where
            . ' GROUP BY utusan_opd ORDER BY total DESC, utusan_opd');
        $summary->execute($params);
        $groups = $summary->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_column($groups, 'total'));
        $perPage = 25;
        $pages = max(1, (int) ceil($total / $perPage));
        $requestedPage = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $page = min($requestedPage, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = reportRows($db, $where, $params, $perPage, $offset)->fetchAll(PDO::FETCH_ASSOC);
        $dataReady = true;
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        $message = $error->getMessage();
    } catch (PDOException $error) {
        http_response_code(503);
        error_log('Panel absensi gagal membaca data, SQLSTATE ' . $error->getCode());
        $message = 'Data absensi belum dapat dimuat. Silakan coba lagi.';
    }

    if ($download) {
        header('Content-Type: text/plain; charset=utf-8');
        exit($message ?? 'Laporan belum dapat diunduh.');
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fffaf7">
    <title>Admin Absensi · DWP Kota Padang</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page <?= $signedIn ? 'admin-page' : 'installer' ?>">
    <header class="admin-header">
        <div class="brand"><div><strong>Dharma Wanita Persatuan</strong><span>Kota Padang</span></div></div>
        <?php if ($signedIn): ?>
            <form method="post" action="admin.php">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <button class="button admin-secondary" type="submit" name="aksi" value="keluar">Keluar</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if (!$signedIn): ?>
        <section class="card" aria-labelledby="login-title">
            <h1 id="login-title">Panel admin</h1>
            <?php if ($message !== null): ?>
                <p class="notice" role="alert"><?= escape($message) ?></p>
            <?php endif; ?>
            <?php if ($showLogin): ?>
                <p>Masuk untuk melihat statistik dan laporan kehadiran Sosialisasi HPV.</p>
                <form method="post" action="admin.php">
                    <input type="hidden" name="token" value="<?= escape($token) ?>">
                    <div class="field">
                        <label for="username">Nama pengguna</label>
                        <input id="username" name="username" autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label for="password">Kata sandi</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="button" type="submit" name="aksi" value="masuk">Masuk</button>
                </form>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="event admin-intro" aria-labelledby="report-title">
            <p class="eyebrow">Panel admin · Sosialisasi HPV</p>
            <h1 id="report-title">Laporan kehadiran</h1>
            <p>Rekap peserta dan utusan OPD. Seluruh waktu ditampilkan dalam WIB.</p>
        </section>

        <section class="card admin-section" aria-labelledby="filters-title">
            <h2 id="filters-title">Filter laporan</h2>
            <?php if ($message !== null): ?>
                <p class="notice" role="alert"><?= escape($message) ?></p>
            <?php endif; ?>
            <form class="admin-filters" method="get" action="admin.php">
                <div class="field">
                    <label for="dari">Tanggal awal</label>
                    <input id="dari" name="dari" type="date" value="<?= escape($filters['dari']) ?>">
                </div>
                <div class="field">
                    <label for="sampai">Tanggal akhir</label>
                    <input id="sampai" name="sampai" type="date" value="<?= escape($filters['sampai']) ?>">
                </div>
                <div class="field">
                    <label for="opd">Utusan OPD</label>
                    <select id="opd" name="opd">
                        <option value="">Semua OPD</option>
                        <?php if ($filters['opd'] !== '' && !in_array($filters['opd'], $opds, true)): ?>
                            <option selected value="<?= escape($filters['opd']) ?>"><?= escape($filters['opd']) ?></option>
                        <?php endif; ?>
                        <?php foreach ($opds as $opd): ?>
                            <option value="<?= escape($opd) ?>"<?= $filters['opd'] === $opd ? ' selected' : '' ?>><?= escape($opd) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-actions">
                    <button class="button" type="submit">Terapkan</button>
                    <a href="admin.php" class="button admin-secondary">Reset</a>
                </div>
            </form>
        </section>

        <?php if ($dataReady): ?>
            <dl class="admin-metrics" aria-label="Ringkasan kehadiran">
                <div class="card"><dt>Total kehadiran</dt><dd><?= number_format($total, 0, ',', '.') ?></dd></div>
                <div class="card"><dt>Utusan OPD</dt><dd><?= number_format(count($groups), 0, ',', '.') ?></dd></div>
            </dl>

            <section class="card admin-section" aria-labelledby="attendance-title">
                <div class="admin-section-header">
                    <h2 id="attendance-title">Daftar kehadiran</h2>
                    <a class="button" href="?<?= escape(http_build_query(['unduh' => 'csv'] + $activeFilters)) ?>">Unduh CSV</a>
                </div>
                <?php if (!$rows): ?>
                    <p>Belum ada kehadiran untuk filter ini.</p>
                <?php else: ?>
                    <div class="admin-table-wrap" tabindex="0" role="region" aria-labelledby="attendance-title">
                        <table class="admin-table">
                            <thead><tr>
                                <th scope="col">No.</th>
                                <?php foreach (attendanceFields() as $field): ?>
                                    <th scope="col"><?= escape($field['label']) ?></th>
                                <?php endforeach; ?>
                                <th scope="col">Waktu hadir (WIB)</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $index => $row): ?>
                                    <tr>
                                        <td><?= $offset + $index + 1 ?></td>
                                        <th scope="row"><?= escape($row['nama_lengkap']) ?></th>
                                        <td><?= escape($row['utusan_opd']) ?></td>
                                        <td class="nowrap"><?= escape($row['no_whatsapp']) ?></td>
                                        <td><?= escape($row['nama_suami']) ?></td>
                                        <td class="nowrap"><?= escape($row['waktu_hadir']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($pages > 1): ?>
                        <nav class="admin-pagination" aria-label="Halaman daftar kehadiran">
                            <?php if ($page > 1): ?>
                                <a href="?<?= escape(http_build_query(['halaman' => $page - 1] + $activeFilters)) ?>">Sebelumnya</a>
                            <?php endif; ?>
                            <span>Halaman <?= $page ?> dari <?= $pages ?></span>
                            <?php if ($page < $pages): ?>
                                <a href="?<?= escape(http_build_query(['halaman' => $page + 1] + $activeFilters)) ?>">Berikutnya</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($groups): ?>
                <section class="card admin-section" aria-labelledby="opd-title">
                    <h2 id="opd-title">Kehadiran per OPD</h2>
                    <table class="admin-table admin-group-table">
                        <thead><tr><th scope="col">Utusan OPD</th><th scope="col">Jumlah hadir</th></tr></thead>
                        <tbody>
                            <?php foreach ($groups as $group): ?>
                                <tr><th scope="row"><?= escape($group['utusan_opd']) ?></th><td><?= number_format((int) $group['total'], 0, ',', '.') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
