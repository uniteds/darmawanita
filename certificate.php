<?php

declare(strict_types=1);

const CERTIFICATE_TEMPLATE = __DIR__ . '/sertifikat.jpeg';

function certificateFont(): string
{
    $local = __DIR__ . '/config.local.php';
    $settings = is_file($local) ? require $local : [];
    $windows = getenv('WINDIR') ?: 'C:/Windows';
    $candidates = [
        $settings['certificate_font'] ?? null,
        $windows . '/Fonts/georgiab.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSerif-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSerif-Bold.ttf',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate && is_readable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Font sertifikat tidak ditemukan. Atur certificate_font di config.local.php.');
}

function textBounds(string $text, string $font, float $size): array
{
    $box = imagettfbbox($size, 0, $font, $text);

    if ($box === false) {
        throw new RuntimeException('Font sertifikat tidak dapat dibaca.');
    }

    return [min($box[0], $box[2]), min($box[5], $box[7]), max($box[2], $box[4]), max($box[1], $box[3])];
}

function splitCertificateName(string $name): array
{
    $middle = intdiv(mb_strlen($name, 'UTF-8'), 2);
    $before = mb_strrpos(mb_substr($name, 0, $middle + 1, 'UTF-8'), ' ', 0, 'UTF-8');
    $after = mb_strpos($name, ' ', $middle, 'UTF-8');
    $split = $before === false ? $after : $before;

    if ($after !== false && ($split === false || $after - $middle < $middle - $split)) {
        $split = $after;
    }

    return [
        rtrim(mb_substr($name, 0, $split === false ? $middle : $split, 'UTF-8')),
        ltrim(mb_substr($name, $split === false ? $middle : $split, null, 'UTF-8')),
    ];
}

function createCertificate(string $name): GdImage
{
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagettfbbox')) {
        throw new RuntimeException('Sertifikat memerlukan GD dengan dukungan JPEG dan FreeType.');
    }

    $font = certificateFont();
    $image = imagecreatefromjpeg(CERTIFICATE_TEMPLATE);

    if ($image === false) {
        throw new RuntimeException('Template sertifikat tidak dapat dibuka.');
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $size = $width * 37 / 1280;
    $available = $width * 0.61;
    [$left, , $right] = textBounds($name, $font, $size);
    $fit = $size * min(1, $available / max(1, $right - $left));
    $lines = [$name];

    if ($fit < $width * 24 / 1280 && mb_strlen($name, 'UTF-8') > 1) {
        $lines = splitCertificateName($name);
        $size = $width * 26 / 1280;
        $widest = 1;

        foreach ($lines as $line) {
            [$left, , $right] = textBounds($line, $font, $size);
            $widest = max($widest, $right - $left);
        }

        $fit = $size * min(1, $available / $widest);
    }

    $color = imagecolorallocate($image, 101, 13, 37);
    $center = $height * 0.442;
    $step = $height * 0.044;

    foreach ($lines as $index => $line) {
        [$left, $top, $right, $bottom] = textBounds($line, $font, $fit);
        $x = (int) round(($width - $left - $right) / 2);
        $y = (int) round($center + $step * ($index - (count($lines) - 1) / 2) - ($top + $bottom) / 2);
        imagettftext($image, $fit, 0, $x, $y, $color, $font, $line);
    }

    return $image;
}
