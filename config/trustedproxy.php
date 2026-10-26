<?php

return [
    // Configure only proxies that replace the forwarded protocol header.
    // An empty setting keeps direct/local requests untrusted.
    'proxies' => env('TRUSTED_PROXIES', ''),
];
