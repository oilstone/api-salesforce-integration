<?php

return [
    'instance_url' => env('SALESFORCE_INSTANCE_URL'),
    'instance_version' => env('SALESFORCE_INSTANCE_VERSION', 'v62.0'),
    'client_id' => env('SALESFORCE_CLIENT_ID'),
    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
    'debug' => env('SALESFORCE_DEBUG', false),
    'query_cache_default_ttl' => env('SALESFORCE_QUERY_CACHE_DEFAULT_TTL', 3600),
    'entry_cache_default_ttl' => env('SALESFORCE_ENTRY_CACHE_DEFAULT_TTL', 86400),
    'scopes' => array_filter(array_map('trim', explode(',', (string) env('SALESFORCE_SCOPES', '')))),
];
