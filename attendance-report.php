<?php

declare(strict_types=1);

function reportCriteria(array $filters): array
{
    foreach (['dari', 'sampai'] as $key) {
        $value = $filters[$key];
        if ($value === '') {
            continue;
        }

        if (!preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Masukkan tanggal yang valid.');
        }
    }

    if ($filters['dari'] !== '' && $filters['sampai'] !== '' && $filters['dari'] > $filters['sampai']) {
        throw new InvalidArgumentException('Tanggal akhir harus sama dengan atau sesudah tanggal awal.');
    }

    if (!mb_check_encoding($filters['opd'], 'UTF-8') || mb_strlen($filters['opd'], 'UTF-8') > 150) {
        throw new InvalidArgumentException('Pilih utusan OPD yang valid.');
    }

    $conditions = $params = [];

    if ($filters['dari'] !== '') {
        $conditions[] = 'waktu_hadir >= :dari';
        $params['dari'] = $filters['dari'] . ' 00:00:00';
    }
    if ($filters['sampai'] !== '') {
        $conditions[] = 'waktu_hadir <= :sampai';
        $params['sampai'] = $filters['sampai'] . ' 23:59:59';
    }
    if ($filters['opd'] !== '') {
        $conditions[] = 'utusan_opd = :opd';
        $params['opd'] = $filters['opd'];
    }

    return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function reportRows(PDO $db, string $where, array $params, ?int $limit = null, int $offset = 0): PDOStatement
{
    $pageLimit = $limit === null ? '' : sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
    $statement = $db->prepare('SELECT nama_lengkap, utusan_opd, no_whatsapp, nama_suami, waktu_hadir FROM absensi'
        . $where . ' ORDER BY waktu_hadir DESC, id DESC' . $pageLimit);
    $statement->execute($params);

    return $statement;
}

function reportCsvCell(string $value): string
{
    // Tulis awalan yang bisa dianggap rumus sebagai teks.
    return preg_match('/\A(?:[\t\r\n]|[\p{Z}\s\p{Cf}]*[=+\-@＝＋－＠])/u', $value) ? "'" . $value : $value;
}

function downloadAttendanceReport(PDOStatement $rows): never
{
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $output = fopen('php://output', 'wb');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-kehadiran-' . $today->format('Y-m-d') . '.csv"');

    fwrite($output, "\xEF\xBB\xBF");
    $columns = array_column(attendanceFields(), 'label');
    $columns[] = 'Waktu hadir (WIB)';
    fputcsv($output, $columns, ';', '"', '', "\r\n");

    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $row['no_whatsapp'] = $row['no_whatsapp'] === '' ? '' : "'" . $row['no_whatsapp'];
        fputcsv($output, array_map('reportCsvCell', $row), ';', '"', '', "\r\n");
    }

    fclose($output);
    exit;
}
