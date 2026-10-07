<?php
declare(strict_types=1);

/**
 * Fjernelse af skjulte metadata (EXIF inkl. GPS-position, XMP, IPTC,
 * kommentarer, tekst-chunks) fra uploadede billeder.
 *
 * Metoden er "tabsfri": filen omskrives på byte-niveau, så selve
 * billeddata ikke omkodes (ingen kvalitetsforringelse). Kun de
 * segmenter/chunks, der bærer metadata, udelades. Undtagelser, der
 * bevidst BEVARES fordi de påvirker visningen:
 *   - JPEG: farveprofil (ICC), JFIF-header, Adobe-farvemarkør
 *   - JPEG: EXIF-orientering, men KUN som et minimalt EXIF-segment
 *     uden andre oplysninger — ellers ville fotos taget på højkant
 *     blive vist på siden
 *   - PNG: farveprofil, gamma, pixeldensitet m.m. (kun tekst-chunks,
 *     EXIF og tidsstempel fjernes)
 *   - WebP: farveprofil og animation; EXIF/XMP fjernes (orientering
 *     bevares, som for JPEG)
 *
 * GIF-filer røres ikke (de indeholder ikke EXIF/GPS).
 */

/** JPEG-markører, der kan indeholde metadata, og hvad der bevares. */
const IMAGE_JPEG_KEEP_APP0_PREFIX  = "JFIF\0";
const IMAGE_JPEG_KEEP_APP2_PREFIX  = "ICC_PROFILE\0";
const IMAGE_JPEG_KEEP_APP14_PREFIX = "Adobe";

/**
 * Minimalt EXIF-blok (TIFF-format) der kun indeholder orienteringen.
 * Bruges som indhold i JPEG-APP1 (efter "Exif\0\0") og WebP-EXIF-chunk.
 */
function image_minimal_exif_tiff(int $orientation): string
{
    return "MM\0\x2A" . pack('N', 8)        // big-endian TIFF-header, IFD0 ligger på offset 8
        . pack('n', 1)                      // ét felt i IFD0
        . pack('nnN', 0x0112, 3, 1)         // tag Orientation, type SHORT, antal 1
        . pack('n', $orientation) . "\0\0"  // værdi (venstrestillet i 4 bytes)
        . pack('N', 0);                     // ingen IFD1
}

/**
 * Læser EXIF-orienteringen (1-8) ud af en TIFF-struktur (indholdet af
 * et EXIF-segment uden "Exif\0\0"-præfikset). Returnerer 1 hvis den
 * ikke findes eller ikke kan læses.
 */
function image_orientation_from_tiff(string $tiff): int
{
    $len = strlen($tiff);
    if ($len < 14) {
        return 1;
    }

    $order = substr($tiff, 0, 2);
    if ($order === 'II') {
        $u16 = static fn(string $s, int $o): int => unpack('v', substr($s, $o, 2))[1];
        $u32 = static fn(string $s, int $o): int => unpack('V', substr($s, $o, 4))[1];
    } elseif ($order === 'MM') {
        $u16 = static fn(string $s, int $o): int => unpack('n', substr($s, $o, 2))[1];
        $u32 = static fn(string $s, int $o): int => unpack('N', substr($s, $o, 4))[1];
    } else {
        return 1;
    }

    if ($u16($tiff, 2) !== 42) {
        return 1;
    }

    $ifd = $u32($tiff, 4);
    if ($ifd < 8 || $ifd + 2 > $len) {
        return 1;
    }

    $count = $u16($tiff, $ifd);
    for ($i = 0; $i < $count; $i++) {
        $entry = $ifd + 2 + $i * 12;
        if ($entry + 12 > $len) {
            return 1;
        }
        if ($u16($tiff, $entry) === 0x0112 && $u16($tiff, $entry + 2) === 3) {
            $value = $u16($tiff, $entry + 8);
            return ($value >= 1 && $value <= 8) ? $value : 1;
        }
    }

    return 1;
}

/**
 * Renser JPEG-bytes for metadata. Returnerer de nye bytes, eller null
 * hvis filen ikke har en JPEG-struktur, vi kan omskrive sikkert.
 */
function image_strip_jpeg_bytes(string $data): ?string
{
    $len = strlen($data);
    if ($len < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
        return null;
    }

    $app0 = '';       // JFIF-header skal ligge lige efter SOI
    $body = '';       // alle øvrige bevarede segmenter + billeddata
    $orientation = 1;
    $pos = 2;
    $sawScan = false;

    while ($pos < $len) {
        if ($data[$pos] !== "\xFF") {
            return null;
        }
        while ($pos < $len && $data[$pos] === "\xFF") { // fyldbytes
            $pos++;
        }
        if ($pos >= $len) {
            return null;
        }
        $marker = ord($data[$pos]);
        $pos++;

        if ($marker === 0xD9) {                      // EOI — alt efter (fx vedhæftede ekstra billeder/data) droppes
            if (!$sawScan) {
                return null;
            }
            $body .= "\xFF\xD9";
            $exif = '';
            if ($orientation !== 1) {
                $tiff = image_minimal_exif_tiff($orientation);
                $exif = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff;
            }
            return "\xFF\xD8" . $app0 . $exif . $body;
        }

        if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { // markører uden længde
            $body .= "\xFF" . chr($marker);
            continue;
        }

        if ($pos + 2 > $len) {
            return null;
        }
        $segLen = (ord($data[$pos]) << 8) | ord($data[$pos + 1]); // inkl. de 2 længde-bytes
        if ($segLen < 2 || $pos + $segLen > $len) {
            return null;
        }
        $lengthAndPayload = substr($data, $pos, $segLen);
        $payload = substr($lengthAndPayload, 2);
        $pos += $segLen;

        if ($marker === 0xDA) {                      // SOS: header + entropikodet data
            $sawScan = true;
            $body .= "\xFF\xDA" . $lengthAndPayload;

            // Kopiér billeddata uændret, indtil næste RIGTIGE markør
            // (ikke FF00-udfyldning og ikke RSTn). Håndterer også
            // progressive JPEG-filer med flere scans.
            if (preg_match('/\xFF[^\x00\xD0-\xD7]/', $data, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $next = $m[0][1];
            $body .= substr($data, $pos, $next - $pos);
            $pos = $next;
            continue;
        }

        if ($marker >= 0xE0 && $marker <= 0xEF) {    // APP0-APP15
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0") && $orientation === 1) {
                $orientation = image_orientation_from_tiff(substr($payload, 6));
            }

            $keep = ($marker === 0xE0 && str_starts_with($payload, IMAGE_JPEG_KEEP_APP0_PREFIX))
                || ($marker === 0xE2 && str_starts_with($payload, IMAGE_JPEG_KEEP_APP2_PREFIX))
                || ($marker === 0xEE && str_starts_with($payload, IMAGE_JPEG_KEEP_APP14_PREFIX));

            if ($keep) {
                $segment = "\xFF" . chr($marker) . $lengthAndPayload;
                if ($marker === 0xE0) {
                    $app0 = $segment;
                } else {
                    $body .= $segment;
                }
            }
            continue;
        }

        if ($marker === 0xFE) {                      // COM (kommentar)
            continue;
        }

        $body .= "\xFF" . chr($marker) . $lengthAndPayload; // DQT, DHT, SOF, DRI osv.
    }

    return null; // nåede aldrig EOI
}

/**
 * Renser PNG-bytes: fjerner tekst-chunks (tEXt/zTXt/iTXt), EXIF (eXIf)
 * og tidsstempel (tIME). Alle øvrige chunks kopieres uændret.
 */
function image_strip_png_bytes(string $data): ?string
{
    $signature = "\x89PNG\r\n\x1a\n";
    $len = strlen($data);
    if ($len < 20 || !str_starts_with($data, $signature)) {
        return null;
    }

    $drop = ['tEXt' => true, 'zTXt' => true, 'iTXt' => true, 'eXIf' => true, 'tIME' => true];
    $out = $signature;
    $pos = 8;
    $sawIend = false;

    while ($pos + 12 <= $len) {
        $chunkLen = unpack('N', substr($data, $pos, 4))[1];
        $type = substr($data, $pos + 4, 4);
        $total = 12 + $chunkLen;
        if ($pos + $total > $len) {
            return null;
        }
        if (!isset($drop[$type])) {
            $out .= substr($data, $pos, $total);
        }
        $pos += $total;
        if ($type === 'IEND') {
            $sawIend = true;
            break;
        }
    }

    return $sawIend ? $out : null;
}

/**
 * Renser WebP-bytes: fjerner EXIF- og XMP-chunks og opdaterer flagene
 * i VP8X-headeren samt RIFF-størrelsen. Orientering bevares som for JPEG.
 */
function image_strip_webp_bytes(string $data): ?string
{
    $len = strlen($data);
    if ($len < 20 || !str_starts_with($data, 'RIFF') || substr($data, 8, 4) !== 'WEBP') {
        return null;
    }

    $chunks = [];
    $orientation = 1;
    $pos = 12;

    while ($pos + 8 <= $len) {
        $fourcc = substr($data, $pos, 4);
        $size = unpack('V', substr($data, $pos + 4, 4))[1];
        $padded = $size + ($size % 2);
        if ($pos + 8 + $size > $len) {
            return null;
        }
        $payload = substr($data, $pos + 8, $size);
        $pos += 8 + $padded;

        if ($fourcc === 'EXIF') {
            $tiff = str_starts_with($payload, "Exif\0\0") ? substr($payload, 6) : $payload;
            $orientation = image_orientation_from_tiff($tiff);
            continue;
        }
        if ($fourcc === 'XMP ') {
            continue;
        }
        $chunks[] = [$fourcc, $payload];
    }

    if ($chunks === []) {
        return null;
    }

    $hasVp8x = $chunks[0][0] === 'VP8X' && strlen($chunks[0][1]) >= 10;
    if ($hasVp8x) {
        $flags = ord($chunks[0][1][0]) & ~0x0C;      // ryd EXIF- (0x08) og XMP-flag (0x04)
        if ($orientation !== 1) {
            $chunks[] = ['EXIF', image_minimal_exif_tiff($orientation)];
            $flags |= 0x08;
        }
        $chunks[0][1][0] = chr($flags);
    }

    $body = '';
    foreach ($chunks as [$fourcc, $payload]) {
        $size = strlen($payload);
        $body .= $fourcc . pack('V', $size) . $payload . ($size % 2 === 1 ? "\0" : '');
    }

    return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
}

/**
 * Nødløsning hvis den tabsfri metode ikke kunne læse filen: omkod
 * billedet via GD (som ikke overfører metadata), med orientering
 * anvendt. Giver et lille kvalitetstab for JPEG/WebP, men sikrer at
 * intet metadata følger med.
 */
function image_reencode_via_gd(string $path, string $extension): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $orientation = 1;
    $img = match ($extension) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($path),
        'png'  => @imagecreatefrompng($path),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if ($img === false) {
        return false;
    }

    if ($extension === 'jpg' || $extension === 'jpeg') {
        $orientation = image_jpeg_orientation_from_file($path);
        $img = image_apply_orientation($img, $orientation);
    }

    $tmp = $path . '.tmp';
    $ok = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($img, $tmp, 92),
        'png'  => (function () use ($img, $tmp) {
            imagesavealpha($img, true);
            return imagepng($img, $tmp, 6);
        })(),
        'webp' => imagewebp($img, $tmp, 92),
        default => false,
    };
    imagedestroy($img);

    if (!$ok || !is_file($tmp) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Fjerner metadata fra en billedfil på disken (omskriver den på stedet).
 * Returnerer true, hvis filen nu er renset (eller ikke behøver det).
 */
function image_strip_metadata(string $path, string $extension): bool
{
    if ($extension === 'gif') {
        return true;
    }

    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return false;
    }

    $stripped = match ($extension) {
        'jpg', 'jpeg' => image_strip_jpeg_bytes($data),
        'png'  => image_strip_png_bytes($data),
        'webp' => image_strip_webp_bytes($data),
        default => null,
    };

    if ($stripped === null) {
        return image_reencode_via_gd($path, $extension);
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $stripped) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    clearstatcache(true, $path);
    return true;
}

/** Læser EXIF-orienteringen (1-8) fra en JPEG-fil. 1 hvis den ikke findes. */
function image_jpeg_orientation_from_file(string $path): int
{
    $data = @file_get_contents($path, false, null, 0, 262144); // EXIF ligger altid tidligt i filen
    if ($data === false || strlen($data) < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
        return 1;
    }

    $pos = 2;
    $len = strlen($data);
    while ($pos + 4 <= $len) {
        if ($data[$pos] !== "\xFF") {
            return 1;
        }
        $marker = ord($data[$pos + 1]);
        if ($marker === 0xFF) {
            $pos++;
            continue;
        }
        if ($marker === 0xDA || $marker === 0xD9) {
            return 1;
        }
        $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
        if ($segLen < 2) {
            return 1;
        }
        if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
            return image_orientation_from_tiff(substr($data, $pos + 10, $segLen - 8));
        }
        $pos += 2 + $segLen;
    }

    return 1;
}

/**
 * Drejer/spejler et GD-billede, så det vises rigtigt, ud fra en
 * EXIF-orientering (1-8). GD ignorerer selv orienteringen.
 *
 * @param \GdImage $img
 * @return \GdImage
 */
function image_apply_orientation($img, int $orientation)
{
    $result = $img;

    switch ($orientation) {
        case 2:
            imageflip($result, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $result = imagerotate($img, 180, 0);
            break;
        case 4:
            imageflip($result, IMG_FLIP_VERTICAL);
            break;
        case 5:
            $result = imagerotate($img, 90, 0);
            imageflip($result, IMG_FLIP_VERTICAL);
            break;
        case 6:
            $result = imagerotate($img, -90, 0);
            break;
        case 7:
            $result = imagerotate($img, 90, 0);
            imageflip($result, IMG_FLIP_HORIZONTAL);
            break;
        case 8:
            $result = imagerotate($img, 90, 0);
            break;
    }

    if ($result === false) {
        return $img;
    }
    if ($result !== $img) {
        imagedestroy($img);
    }
    return $result;
}
