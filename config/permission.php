<?php

/*
 * Migration-only compatibility configuration.
 *
 * The canonical authorization runtime no longer uses the legacy permission
 * package, but the historical create_permission_tables migration still needs
 * these names to materialize tables that are removed by the later cutover
 * migrations on a fresh database.
 */
return [
    'teams' => false,
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],
    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],
    'cache' => [
        'expiration_time' => 86400,
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
    'testing' => false,
];
