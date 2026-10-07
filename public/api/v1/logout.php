<?php
declare(strict_types=1);

// API: log ud — tilbagekalder det token, forespørgslen er sendt med.

require_once __DIR__ . '/../../../app/api_auth.php';

api_bootstrap(['POST']);

api_require_token();
api_token_revoke(get_db(), (int) api_current_token_id());

api_json(['success' => true]);
