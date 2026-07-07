<?php

namespace App\Modules\Shared\Support;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves per-record abilities through the single decision engine so the
 * frontend never re-derives scope-chain logic. $map is keyed by action name
 * and valued by Capability::CONST; the returned array preserves the keys.
 */
class ElementAbilities
{
    /**
     * @param  array<string, string>  $map
     * @return array<string, bool>
     */
    public static function resolve(?User $user, Model $record, array $map): array
    {
        $out = [];
        foreach ($map as $key => $capability) {
            $out[$key] = $user !== null && AccessDecision::can($user, $capability, $record);
        }

        return $out;
    }
}
