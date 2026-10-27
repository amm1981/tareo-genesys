<?php

return [
    'max_body_bytes' => (int) env('SYNC_MAX_BODY_BYTES', 12582912),
    'max_batch_bytes' => (int) env('SYNC_MAX_BATCH_BYTES', 4194304),
    'max_batch_rows' => 1000,
    'max_rows' => (int) env('SYNC_MAX_ROWS', 10000000),
    'expected_columns' => 38,
    'schema_file' => env('SYNC_SCHEMA_FILE', storage_path('app/private/source-schema-spwconsumomes-v1.json')),
    'legacy_schema_files' => [resource_path('schemas/spwconsumomesvf.v1.json')],
];
