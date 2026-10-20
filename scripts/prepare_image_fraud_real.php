<?php
/**
 * Build Risky variants from clean camera photos for Image Fraud real eval.
 *
 * Reads:  data_real/image_fraud/clean/*.{jpg,jpeg,png,webp}
 * Writes: data_real/image_fraud/risky/*_noexif.jpg and *_tiny.jpg
 *         data_real/image_fraud/_generated/ (same copies for audit)
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/prepare_image_fraud_real.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$base = $root . DIRECTORY_SEPARATOR . 'data_real' . DIRECTORY_SEPARATOR . 'image_fraud';
$clean = $base . DIRECTORY_SEPARATOR . 'clean';
$risky = $base . DIRECTORY_SEPARATOR . 'risky';
$gen = $base . DIRECTORY_SEPARATOR . '_generated';

foreach ([$clean, $risky, $gen] as $d) {
    if (!is_dir($d) && !mkdir($d, 0775, true) && !is_dir($d)) {
        fwrite(STDERR, "Cannot create {$d}\n");
        exit(1);
    }
}

$files = listImages($clean);
if ($files === []) {
    fwrite(STDERR, "No images in {$clean}\n");
    fwrite(STDERR, "Put 10–15 camera JPEGs there first (see data_real/image_fraud/README.md)\n");
    exit(1);
}

echo "Found " . count($files) . " clean image(s)\n";
$made = 0;
foreach ($files as $path) {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $stem = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) $stem) ?: 'img';
    $noExif = $risky . DIRECTORY_SEPARATOR . $stem . '_noexif.jpg';
    $tiny = $risky . DIRECTORY_SEPARATOR . $stem . '_tiny.jpg';

    $jpegBytes = toJpegBytes($path);
    if ($jpegBytes === null) {
        fwrite(STDERR, "SKIP (cannot decode): " . basename($path) . "\n");
        continue;
    }

    $stripped = stripExifFromJpegBytes($jpegBytes);
    file_put_contents($noExif, $stripped);
    copy($noExif, $gen . DIRECTORY_SEPARATOR . basename($noExif));

    $tinyBytes = makeTinyJpeg($stripped, 12000);
    file_put_contents($tiny, $tinyBytes);
    copy($tiny, $gen . DIRECTORY_SEPARATOR . basename($tiny));

    $made += 2;
    echo "  + {$stem}_noexif.jpg (" . filesize($noExif) . " B), {$stem}_tiny.jpg (" . filesize($tiny) . " B)\n";
}

$nClean = count(listImages($clean));
$nRisky = count(listImages($risky));
echo "Done. Generated {$made} risky file(s).\n";
echo "Totals → clean={$nClean}, risky={$nRisky}, all=" . ($nClean + $nRisky) . "\n";
if ($nClean + $nRisky < 20) {
    echo "Tip: add more camera photos to clean/ (or real screenshots to risky/) to reach 20–30.\n";
} else {
    echo "Ready → C:\\xampp\\php\\php.exe tests\\test_image_fraud_real.php\n";
}

/**
 * @return list<string>
 */
function listImages(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (['jpg', 'JPG', 'jpeg', 'JPEG', 'png', 'PNG', 'webp', 'WEBP'] as $ext) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $f) {
            $out[] = $f;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function toJpegBytes(string $path): ?string
{
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($mime === 'image/jpeg' || in_array($ext, ['jpg', 'jpeg'], true)) {
        $data = file_get_contents($path);
        return $data === false ? null : $data;
    }
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return null;
    }
    ob_start();
    imagejpeg($im, null, 90);
    imagedestroy($im);
    $out = ob_get_clean();
    return is_string($out) ? $out : null;
}

function stripExifFromJpegBytes(string $data): string
{
    if (strlen($data) < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
        return $data;
    }
    $out = "\xFF\xD8";
    $offset = 2;
    $len = strlen($data);
    while ($offset + 4 <= $len) {
        if ($data[$offset] !== "\xFF") {
            break;
        }
        $marker = $data[$offset + 1];
        if ($marker === "\xDA") {
            $out .= substr($data, $offset);
            return $out;
        }
        $segLen = unpack('n', substr($data, $offset + 2, 2))[1];
        if ($segLen < 2) {
            break;
        }
        $segment = substr($data, $offset, 2 + $segLen);
        $isExif = ($marker === "\xE1" && substr($segment, 4, 6) === "Exif\x00\x00");
        $isApp13 = ($marker === "\xED");
        if (!$isExif && !$isApp13) {
            $out .= $segment;
        }
        $offset += 2 + $segLen;
    }
    return $data;
}

function makeTinyJpeg(string $jpegBytes, int $maxBytes): string
{
    if (strlen($jpegBytes) <= $maxBytes) {
        return $jpegBytes;
    }
    if (!function_exists('imagecreatefromstring')) {
        return substr($jpegBytes, 0, $maxBytes);
    }
    $im = @imagecreatefromstring($jpegBytes);
    if ($im === false) {
        return substr($jpegBytes, 0, $maxBytes);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = 0.35;
    $nw = max(64, (int) round($w * $scale));
    $nh = max(64, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $quality = 55;
    $best = null;
    for ($q = $quality; $q >= 25; $q -= 5) {
        ob_start();
        imagejpeg($dst, null, $q);
        $buf = ob_get_clean();
        if (!is_string($buf)) {
            continue;
        }
        $best = $buf;
        if (strlen($buf) <= $maxBytes) {
            imagedestroy($dst);
            return $buf;
        }
    }
    imagedestroy($dst);
    if ($best !== null) {
        return strlen($best) > $maxBytes ? substr($best, 0, $maxBytes) : $best;
    }
    return substr($jpegBytes, 0, $maxBytes);
}
