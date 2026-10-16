<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
 * Billedlinks i GPX-filer.
 *
 * Editoren gemmer et billede på et waypoint som
 * <link href="billeder/<bruger-id>/<32 hex-tegn>.<endelse>">. Filerne er
 * offentlige, så en GPX-fil, der kopieres til en anden profil, ville
 * ellers vise den oprindelige ejers billeder. Derfor:
 * - ved visning fjernes billedlinks, der tilhører en anden end rutens ejer
 * - ved download af en andens rute fjernes alle MitSpor-billedlinks
 *   (relative stier, som alligevel ikke virker uden for MitSpor).
 */

/**
 * Bruger-id'et i et MitSpor-billedlink, eller null hvis href ikke er et.
 */
function image_link_owner_id(string $href): ?int
{
    $pattern = '#^(?:/|\.\./)?' . preg_quote(IMAGE_PUBLIC_URL_BASE, '#')
        . '/(\d+)/[0-9a-f]{32}(?:-thumb)?\.(?:jpe?g|png|gif|webp)$#i';

    return preg_match($pattern, trim($href), $m) === 1 ? (int) $m[1] : null;
}

/**
 * Fjerner MitSpor-billedlinks fra GPX-indholdet.
 *
 * @param int|null $keepOwnerId billedlinks til denne brugers billeder
 *                              beholdes; null fjerner alle
 * @return string|null det rensede indhold, eller null hvis filen ikke
 *                     kunne læses som XML
 */
function gpx_remove_image_links(string $xml, ?int $keepOwnerId): ?string
{
    // Hurtig vej: ingen billedstier i filen, intet at gøre.
    if (!str_contains($xml, IMAGE_PUBLIC_URL_BASE . '/')) {
        return $xml;
    }

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
        return null;
    }

    $remove = [];
    foreach ($doc->getElementsByTagNameNS('*', 'link') as $link) {
        $ownerId = image_link_owner_id($link->getAttribute('href'));
        if ($ownerId !== null && $ownerId !== $keepOwnerId) {
            $remove[] = $link;
        }
    }
    if ($remove === []) {
        return $xml;
    }

    foreach ($remove as $link) {
        // Fjern også den tomme linje/indrykning foran linket.
        $prev = $link->previousSibling;
        if ($prev instanceof DOMText && trim($prev->nodeValue ?? '') === '') {
            $prev->parentNode->removeChild($prev);
        }
        $link->parentNode->removeChild($link);
    }

    $result = $doc->saveXML();

    return $result === false ? null : $result;
}
