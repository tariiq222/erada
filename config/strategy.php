<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tree endpoint (GET /api/strategy/dashboard/portfolio/{id}/tree)
    |--------------------------------------------------------------------------
    |
    | Phase 7.2 — feature flag for the full portfolio->programs->projects tree
    | endpoint. The route stays registered (so the binding/authz can be tested
    | in isolation), but the controller aborts 404 unless this flag is on.
    |
    | Set STRATEGY_TREE_ENDPOINT_ENABLED=true in .env to enable.
    |
    */

    'tree_endpoint_enabled' => env('STRATEGY_TREE_ENDPOINT_ENABLED', false),

];
