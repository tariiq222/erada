<?php

namespace App\Modules\Meetings\Support;

use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use InvalidArgumentException;

/**
 * Central allowlist mapping short aliases to the model classes a Meeting
 * may be linked to via its polymorphic `subject_type` / `subject_id` pair.
 *
 * The DB still stores fully-qualified class names (the codebase uses no
 * morphMap for these scopes), so this helper governs:
 *   - which aliases the API accepts at request validation time
 *   - how an alias resolves to its concrete ScopeAware class
 *   - how a class resolves back to its alias (for logging / UI labels)
 *
 * Adding a new link type (e.g. committees) is one line in MAP.
 *
 * History:
 *   Originally served Decision (deleted in Direction B). The Decision -> Recommendation
 *   cutover dropped this helper, but the Meeting-side `subject_type` validation
 *   and query filters still depended on it. Three files (StoreMeetingRequest,
 *   UpdateMeetingRequest, MeetingController) were left dangling and produced
 *   500s on /api/meetings POST. Restored here as the canonical single source
 *   for the Meeting subject allowlist.
 */
final class DecidableType
{
    /** @var array<string, class-string> */
    private const MAP = [
        'project' => Project::class,
        'portfolio' => Portfolio::class,
        'program' => Program::class,
        'risk' => Risk::class,
    ];

    /** @return array<int, string> */
    public static function aliases(): array
    {
        return array_keys(self::MAP);
    }

    /** @return class-string */
    public static function classFor(string $alias): string
    {
        return self::MAP[$alias]
            ?? throw new InvalidArgumentException("نوع العنصر غير صالح: {$alias}");
    }

    public static function aliasFor(string $class): ?string
    {
        return array_search($class, self::MAP, true) ?: null;
    }
}
