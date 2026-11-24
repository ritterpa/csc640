<?php

// Bearer token settings, read from .env (created by code/stage2/run.sh).
// The acceptance tests read JWT_SECRET from the same .env to sign their
// expired-token test case.
return [
    'secret' => env('JWT_SECRET', ''),
    'ttl'    => (int) env('JWT_TTL', 3600),   // token lifetime in seconds
];
