<?php
declare(strict_types=1);

require_once __DIR__ . '/image_storage.php';

/**
 * Validerer og gemmer et uploadet billede for den givne bruger.
 * $file skal være ét element fra $_FILES (fx $_FILES['image']).
 *
 * @return array{success: bool, errors?: string[], url?: string, image_id?: int}
 */
function upload_user_image(PDO $pdo, array $user, array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Billedet er større end serverens grænse.',
            UPLOAD_ERR_FORM_SIZE  => 'Billedet er større end den tilladte grænse.',
            UPLOAD_ERR_PARTIAL    => 'Billedet blev kun delvist uploadet. Prøv igen.',
            UPLOAD_ERR_NO_FILE    => 'Intet billede blev valgt.',
            UPLOAD_ERR_NO_TMP_DIR => 'Serverfejl: mangler midlertidig mappe.',
            UPLOAD_ERR_CANT_WRITE => 'Serverfejl: kunne ikke skrive filen.',
            UPLOAD_ERR_EXTENSION  => 'Uploadet blev stoppet af en PHP-udvidelse.',
        ];
        return ['success' => false, 'errors' => [$uploadErrors[$file['error']] ?? 'Ukendt fejl ved upload.']];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'errors' => ['Ugyldig filupload.']];
    }

    if ($file['size'] <= 0) {
        return ['success' => false, 'errors' => ['Billedet er tomt.']];
    }

    if ($file['size'] > IMAGE_MAX_BYTES) {
        return ['success' => false, 'errors' => ['Billedet er for stort (maks ' . (IMAGE_MAX_BYTES / 1024 / 1024) . ' MB).']];
    }

    if (($user['storage_used_bytes'] + $file['size']) > $user['storage_quota_bytes']) {
        return ['success' => false, 'errors' => ['Du har ikke nok ledig lagerplads til dette billede.']];
    }

    // Bestem filtypen ud fra det FAKTISKE indhold, ikke det klientopgivne
    // filnavn/MIME — det forhindrer at fx et script omdøbt til .jpg
    // bliver accepteret.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(IMAGE_ALLOWED_MIME_TYPES[$actualMime])) {
        return ['success' => false, 'errors' => ['Filtypen understøttes ikke (' . $actualMime . '). Tilladte typer: JPEG, PNG, GIF, WebP.']];
    }

    $extension = IMAGE_ALLOWED_MIME_TYPES[$actualMime];

    try {
        $stored = image_store_uploaded_file((int) $user['id'], $file['tmp_name'], $extension);
    } catch (Throwable $e) {
        error_log('Billedupload fejlede: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Kunne ikke gemme billedet. Prøv igen.']];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO user_images (user_id, relative_path, thumbnail_relative_path, original_filename, mime_type, size_bytes)
             VALUES (:user_id, :relative_path, :thumbnail_relative_path, :original_filename, :mime_type, :size_bytes)'
        );
        $stmt->execute([
            'user_id'                 => $user['id'],
            'relative_path'           => $stored['relative_path'],
            'thumbnail_relative_path' => $stored['thumbnail_relative_path'],
            'original_filename'       => basename((string) $file['name']),
            'mime_type'               => $actualMime,
            'size_bytes'              => $stored['size_bytes'],
        ]);
        $imageId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'UPDATE users SET storage_used_bytes = storage_used_bytes + :size WHERE id = :id'
        );
        $stmt->execute(['size' => $stored['size_bytes'], 'id' => $user['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($stored['full_path']);
        if ($stored['thumbnail_relative_path'] !== null) {
            @unlink(image_full_path($stored['thumbnail_relative_path']));
        }
        error_log('Billedupload (DB) fejlede: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Der skete en fejl under upload. Prøv igen.']];
    }

    return [
        'success'       => true,
        'url'           => $stored['url'],
        'thumbnail_url' => $stored['thumbnail_url'],
        'image_id'      => $imageId,
    ];
}
