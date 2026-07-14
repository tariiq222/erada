<?php

namespace App\Modules\Core\Authorization\Data;

use InvalidArgumentException;

final readonly class AssignmentScope
{
    public const ALL = 'all';

    public const ORGANIZATION = 'organization';

    public const OWN = 'own';

    public const TYPES = [
        'all',
        'organization',
        'department',
        'own',
        'project',
        'program',
        'portfolio',
        'kpi',
        'meeting',
        'survey',
    ];

    /** @var array<string, array{ar: string, en: string}> */
    private const LABELS = [
        'all' => ['ar' => 'النظام بالكامل', 'en' => 'All'],
        'organization' => ['ar' => 'المنظمة', 'en' => 'Organization'],
        'department' => ['ar' => 'الإدارة', 'en' => 'Department'],
        'own' => ['ar' => 'السجلات الخاصة', 'en' => 'Own records'],
        'project' => ['ar' => 'المشروع', 'en' => 'Project'],
        'program' => ['ar' => 'البرنامج', 'en' => 'Program'],
        'portfolio' => ['ar' => 'المحفظة', 'en' => 'Portfolio'],
        'kpi' => ['ar' => 'مؤشر الأداء', 'en' => 'KPI'],
        'meeting' => ['ar' => 'الاجتماع', 'en' => 'Meeting'],
        'survey' => ['ar' => 'الاستبيان', 'en' => 'Survey'],
    ];

    /**
     * @return list<array{key: string, label_ar: string, label_en: string, target_requirement: 'none'|'required'}>
     */
    public static function catalog(): array
    {
        return array_map(static fn (string $type): array => [
            'key' => $type,
            'label_ar' => self::LABELS[$type]['ar'],
            'label_en' => self::LABELS[$type]['en'],
            'target_requirement' => in_array($type, [self::ALL, self::OWN], true) ? 'none' : 'required',
        ], self::TYPES);
    }

    public function __construct(
        public string $type,
        public ?int $id,
        public bool $inheritToChildren = false,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported authorization scope [{$type}].");
        }

        $allowsNull = in_array($type, ['all', 'own'], true);

        if (($allowsNull && $id !== null) || (! $allowsNull && ($id === null || $id < 1))) {
            throw new InvalidArgumentException("Invalid identifier for authorization scope [{$type}].");
        }
    }

    public function semanticKey(): string
    {
        return $this->type.':'.($this->id ?? 'null');
    }

    /**
     * A role's declared scope is a semantic boundary, not a maximum reach.
     * Narrowing an `all` or `organization` role would silently change what the
     * role means, so every assignment (including `own`) must match exactly.
     */
    public function isCompatibleWithRoleScope(?string $roleScope): bool
    {
        return $roleScope !== null
            && in_array($roleScope, self::TYPES, true)
            && $roleScope === $this->type;
    }
}
