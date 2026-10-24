<?php

return [
    'token_ttl_minutes' => max(1, min(1440, (int) env('API_QUERY_TOKEN_TTL_MINUTES', 60))),
    'cursor_ttl_minutes' => 60,
    'max_tokens_per_user' => 10,
    // Hash sin cuenta asociada: comprobar también una contraseña cuando el usuario no existe.
    'dummy_password_hash' => '$2y$12$h5qK2qlAyDPMJvISz4NlH.9UhrP0/VL1KM8HwVkGaIVWSNm.7L/vK',
];
