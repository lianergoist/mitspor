<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image_metadata.php';

/**
 * Flytter en uploadet billedfil til IMAGE_PUBLIC_DIR/<userId>/ med et
 * tilfældigt, unikt filnavn — ALDRIG afledt af det originale filnavn,
 * for at undgå path traversal og navnekollisioner.
 *
 * @return array{relative_path: string, full_path: string, url: string, size_bytes: int}
 */
function image_store_uploaded_file(int $userId, string $tmpPath, string $extension): array
{
    $userDir = IMAGE_PUBLIC_DIR . '/' . $userId;

    if (!is_dir($userDir)) {
        if (!mkdir($userDir, 0755, true) && !is_dir($userDir)) {
            throw new RuntimeException('Kunne ikke oprette brugerens billedmappe.');
        }
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $fullPath = $userDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $fullPath)) {
        throw new RuntimeException('Kunne ikke gemme billedet på disk.');
    }
    chmod($fullPath, 0644);

    // Fjern skjulte oplysninger (EXIF/GPS m.m.) FØR noget andet sker med
    // filen. Lykkes det ikke, gemmes billedet slet ikke — hellere afvise
    // et upload end at gemme en fil med en position i.
    if (!image_strip_metadata($fullPath, $extension)) {
        @unlink($fullPath);
        throw new RuntimeException('Kunne ikke fjerne skjulte oplysninger fra billedet.');
    }
    clearstatcache(true, $fullPath);

    $thumbnail = image_generate_thumbnail($userId, $filename, $extension);

    return [
        'relative_path' => $userId . '/' . $filename,
        'full_path'     => $fullPath,
        'url'           => IMAGE_PUBLIC_URL_BASE . '/' . $userId . '/' . $filename,
        'size_bytes'    => (int) filesize($fullPath),
        'thumbnail_relative_path' => $thumbnail['relative_path'] ?? null,
        'thumbnail_url'           => $thumbnail['url'] ?? null,
    ];
}

function image_full_path(string $relativePath): string
{
    return IMAGE_PUBLIC_DIR . '/' . $relativePath;
}

/**
 * Genererer en thumbnail (via GD) af et allerede gemt billede, og
 * gemmer den ved siden af originalen som "<basenavn>-thumb.jpg" —
 * altid som JPEG, uanset kildeformat, for ensartet størrelse/kvalitet.
 * Skalerer så længste side bliver IMAGE_THUMBNAIL_MAX_DIMENSION
 * pixels (eller mindre, hvis originalen allerede er mindre).
 *
 * Fejler bevidst "stille" (returnerer null) i stedet for at kaste en
 * exception — mangler GD, eller kan billedet ikke læses/skrives, skal
 * selve uploadet af originalbilledet stadig lykkes; en thumbnail er
 * en bekvemmelighed, ikke en forudsætning.
 *
 * @return array{relative_path: string, full_path: string, url: string}|null
 */
function image_generate_thumbnail(int $userId, string $filename, string $extension): ?array
{
    if (!function_exists('imagecreatetruecolor')) {
        return null; // GD-udvidelsen er ikke tilgængelig på denne server
    }

    $userDir = IMAGE_PUBLIC_DIR . '/' . $userId;
    $sourcePath = $userDir . '/' . $filename;

    $source = match ($extension) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
        'png'  => @imagecreatefrompng($sourcePath),
        'gif'  => @imagecreatefromgif($sourcePath),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if ($source === false) {
        return null;
    }

    // GD ignorerer EXIF-orientering, så fotos taget på højkant ville
    // ellers få en miniature, der ligger på siden.
    if ($extension === 'jpg' || $extension === 'jpeg') {
        $source = image_apply_orientation($source, image_jpeg_orientation_from_file($sourcePath));
    }

    $srcWidth = imagesx($source);
    $srcHeight = imagesy($source);
    $maxDim = IMAGE_THUMBNAIL_MAX_DIMENSION;

    if ($srcWidth <= $maxDim && $srcHeight <= $maxDim) {
        $dstWidth = $srcWidth;
        $dstHeight = $srcHeight;
    } elseif ($srcWidth >= $srcHeight) {
        $dstWidth = $maxDim;
        $dstHeight = max(1, (int) round($srcHeight * $maxDim / $srcWidth));
    } else {
        $dstHeight = $maxDim;
        $dstWidth = max(1, (int) round($srcWidth * $maxDim / $srcHeight));
    }

    $thumb = imagecreatetruecolor($dstWidth, $dstHeight);
    // Hvid baggrund først — ellers bliver evt. gennemsigtighed (PNG/
    // GIF/WebP) sort, når vi gemmer som JPEG (som ikke har alfakanal).
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);
    imagedestroy($source);

    $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '-thumb.jpg';
    $thumbFullPath = $userDir . '/' . $thumbFilename;

    $ok = imagejpeg($thumb, $thumbFullPath, 80);
    imagedestroy($thumb);

    if (!$ok || !is_file($thumbFullPath)) {
        return null;
    }
    chmod($thumbFullPath, 0644);

    return [
        'relative_path' => $userId . '/' . $thumbFilename,
        'full_path'     => $thumbFullPath,
        'url'           => IMAGE_PUBLIC_URL_BASE . '/' . $userId . '/' . $thumbFilename,
    ];
}
