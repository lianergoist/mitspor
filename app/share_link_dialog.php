<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

/**
 * Del-link-dialogen til private ruter: opretter, viser, kopierer og sletter
 * rutens delbare link (public/share-link.php). Bruges af kontrolpanelet og
 * rutens side. Adfærden ligger i public/assets/del-link.js.
 */
function share_link_dialog(): string
{
    $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<dialog id="share-link-dialog" class="modal-dialog" data-csrf="{$csrf}">
    <button type="button" id="share-link-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Del link</h2>

    <div id="share-link-errors" class="modal-errors" hidden></div>

    <p>
        <strong id="share-link-route-title"></strong> er privat, men et delbart link giver adgang
        til denne ene rute uden login.
    </p>

    <div id="share-link-active">
        <label>
            Link
            <input type="text" id="share-link-url" readonly>
        </label>
        <div class="inline-edit-actions" style="margin-top: 8px;">
            <button type="button" id="share-link-copy-btn" class="btn">Kopiér</button>
            <button type="button" id="share-link-revoke-btn" class="btn btn-danger">Slet link</button>
        </div>
    </div>
</dialog>
HTML;
}

/**
 * "Del link" til en side, alle kan se (offentlig rute eller gruppe med
 * offentlige etaper): viser adressen med Kopiér og, hvis browseren kan,
 * Del … (Web Share). Uden $url (fx en helt privat gruppe) vises kun
 * $tekstHtml. Åbnes af knapper med data-open-public-link
 * (public/assets/del-offentlig.js). $tekstHtml skal være escapet.
 */
function public_link_dialog(?string $url, string $tekstHtml): string
{
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $felt = $url === null ? '' : <<<HTML
    <label>
        Link
        <input type="text" id="public-link-url" readonly value="{$h($url)}">
    </label>
    <div class="inline-edit-actions" style="margin-top: 8px;">
        <button type="button" id="public-link-copy-btn" class="btn">Kopiér</button>
        <button type="button" id="public-link-share-btn" class="btn" hidden>Del …</button>
    </div>
HTML;

    return <<<HTML
<dialog id="public-link-dialog" class="modal-dialog">
    <button type="button" id="public-link-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Del link</h2>
    <p>{$tekstHtml}</p>
{$felt}
</dialog>
HTML;
}
