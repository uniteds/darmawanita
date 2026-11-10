<?php

declare(strict_types=1);

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

function attendanceReportHtml(array $entries, array $filters, DateTimeImmutable $now): string
{
    $from = $filters['dari'];
    $to = $filters['sampai'];
    $period = match (true) {
        $from === '' && $to === '' => 'Semua tanggal',
        $from === '' => 'Sampai ' . $to,
        $to === '' => 'Mulai ' . $from,
        $from === $to => $from,
        default => $from . ' s.d. ' . $to,
    };

    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar Hadir Sosialisasi HPV</title>
    <style>
        @page { margin: 30pt 36pt 40pt; }
        body { margin: 0; font: 9pt "DejaVu Sans", sans-serif; color: #30252a; }
        .letterhead { border-bottom: 3pt double #691b36; padding: 0 0 10pt; text-align: center; }
        .organization { font-size: 15pt; font-weight: bold; letter-spacing: 1.2pt; }
        .city { margin-top: 3pt; font-size: 10pt; font-weight: bold; letter-spacing: 2pt; }
        .event { margin: 11pt 0 12pt; text-align: center; }
        .event h1 { margin: 0 0 3pt; color: #691b36; font-size: 21pt; }
        .event p { margin: 2pt 0 0; }
        .metadata { width: 100%; margin: 0 0 13pt; border-collapse: collapse; table-layout: fixed; }
        .metadata td { padding: 6pt 8pt; background: #faf3f5; border: .5pt solid #e8dce1; vertical-align: top; word-wrap: break-word; }
        .metadata strong { display: block; color: #691b36; margin-bottom: 2pt; font-size: 8pt; }
        .attendance { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .attendance thead { display: table-header-group; }
        .attendance tr { page-break-inside: avoid; }
        .attendance th, .attendance td { padding: 6pt; border: .5pt solid #d7cbd0; text-align: left; vertical-align: top; word-wrap: break-word; }
        .attendance th { background: #eee0e6; color: #4b2131; }
        .attendance tbody tr:nth-child(even) td { background: #fcf8fa; }
        .attendance .number { width: 4.5%; text-align: center; }
        .nama_lengkap { width: 22%; }
        .utusan_opd { width: 25.5%; }
        .no_whatsapp { width: 14%; }
        .nama_suami { width: 19%; }
        .waktu_hadir { width: 15%; }
        .attendance .empty { text-align: center; padding: 15pt; }
    </style>
</head>
<body>
    <div class="letterhead">
        <div class="organization">DHARMA WANITA PERSATUAN</div>
        <div class="city">KOTA PADANG</div>
    </div>
    <div class="event">
        <h1>DAFTAR HADIR</h1>
        <p><strong>Sosialisasi HPV</strong></p>
        <p><?= escape(ATTENDANCE_DATE_LABEL) ?></p>
    </div>

    <table class="metadata">
        <tr>
            <td><strong>Periode laporan</strong><?= escape($period) ?></td>
            <td><strong>Utusan OPD</strong><?= escape($filters['opd'] !== '' ? $filters['opd'] : 'Semua OPD') ?></td>
            <td><strong>Jumlah hadir</strong><?= number_format(count($entries), 0, ',', '.') ?> peserta</td>
            <td><strong>Dicetak</strong><?= escape($now->format('d/m/Y H:i')) ?> WIB</td>
        </tr>
    </table>

    <table class="attendance">
        <thead>
            <tr>
                <th class="number">No.</th>
                <?php foreach (attendanceFields() as $key => $field): ?>
                    <th class="<?= escape($key) ?>"><?= escape($field['label']) ?></th>
                <?php endforeach; ?>
                <th class="waktu_hadir">Waktu hadir (WIB)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$entries): ?>
                <tr><td class="empty" colspan="6">Belum ada kehadiran untuk filter ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $index => $entry): ?>
                <tr>
                    <td class="number"><?= $index + 1 ?></td>
                    <?php foreach ($entry as $value): ?>
                        <td><?= escape($value) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
    <?php

    return ob_get_clean();
}

function downloadAttendancePdf(PDOStatement $rows, array $filters): never
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload) || !extension_loaded('dom')) {
        throw new RuntimeException('PDF belum tersedia. Aktifkan ekstensi DOM dan jalankan composer install --no-dev di server.');
    }

    require_once $autoload;
    $entries = $rows->fetchAll(PDO::FETCH_ASSOC);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));

    try {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]);
        $pdf->loadHtml(attendanceReportHtml($entries, $filters, $now), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $pdf->getCanvas()->page_script(static function (int $page, int $count, Canvas $canvas, FontMetrics $metrics): void {
            $font = $metrics->getFont('DejaVu Sans');
            $right = $canvas->get_width() - 36;
            $y = $canvas->get_height() - 28;
            $color = [0.35, 0.27, 0.31];
            $label = 'Halaman ' . $page . ' dari ' . $count;
            $canvas->line(36, $y - 5, $right, $y - 5, [0.82, 0.75, 0.79], 0.6);
            $canvas->text(36, $y, 'DWP Kota Padang | Sosialisasi HPV', $font, 8, $color);
            $canvas->text($right - $metrics->getTextWidth($label, $font, 8), $y, $label, $font, 8, $color);
        });
        $content = $pdf->output();
    } catch (Throwable $error) {
        error_log('PDF absensi gagal dibuat: ' . get_class($error));
        throw new RuntimeException('Laporan PDF belum dapat dibuat. Silakan coba lagi.', 0, $error);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="laporan-kehadiran-' . $now->format('Y-m-d') . '.pdf"');
    echo $content;
    exit;
}
