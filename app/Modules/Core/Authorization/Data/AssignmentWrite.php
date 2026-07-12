<?php

namespace App\Modules\Core\Authorization\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class AssignmentWrite
{
    public ?CarbonImmutable $expiresAt;

    public function __construct(
        public AssignmentScope $scope,
        ?CarbonInterface $expiresAt = null,
        public string $source = 'manual',
    ) {
        if (! in_array($source, ['manual', 'auto', 'migration'], true)) {
            throw new InvalidArgumentException("Unsupported assignment source [{$source}].");
        }

        $this->expiresAt = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);
    }
}
