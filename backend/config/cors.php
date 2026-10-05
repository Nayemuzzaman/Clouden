<?php

/*
| The dashboard is served from the same origin as the API, so no cross-origin
| access is needed. With no paths listed, Laravel never adds CORS headers and
| browsers block cross-origin reads of every API response.
*/

return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
