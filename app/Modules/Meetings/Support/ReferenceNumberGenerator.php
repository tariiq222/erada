<?php

namespace App\Modules\Meetings\Support;

use Illuminate\Support\Facades\DB;

class ReferenceNumberGenerator
{
    /** @var array<string, string> */
    private const TABLE_FOR_PREFIX = [
        'MTG' => 'meetings',
        'REC' => 'recommendations',
        'RES' => 'meeting_resolutions',
    ];

    /**
     * Advisory lock seeds per prefix — arbitrary stable integers so concurrent
     * calls for the same prefix serialize without blocking different prefixes.
     *
     * @var array<string, int>
     */
    private const LOCK_KEY = [
        'MTG' => 1001,
        'REC' => 1002,
        'RES' => 1003,
    ];

    public function generate(string $prefix, string $year): string
    {
        if (! isset(self::TABLE_FOR_PREFIX[$prefix])) {
            throw new \InvalidArgumentException("Unknown prefix: {$prefix}");
        }

        $table = self::TABLE_FOR_PREFIX[$prefix];
        $column = 'reference_number';
        $lockKey = self::LOCK_KEY[$prefix];

        return DB::transaction(function () use ($prefix, $year, $table, $column, $lockKey) {
            // Acquire a transaction-scoped advisory lock for this prefix so
            // concurrent calls serialize instead of racing on the MAX query.
            DB::statement("SELECT pg_advisory_xact_lock({$lockKey})");

            $max = DB::table($table)
                ->where($column, 'like', "{$prefix}-{$year}-%")
                ->selectRaw("MAX(CAST(SPLIT_PART({$column}, '-', 3) AS INTEGER)) AS max_seq")
                ->value('max_seq') ?? 0;

            $next = str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);

            return "{$prefix}-{$year}-{$next}";
        });
    }
}
