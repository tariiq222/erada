<?php

namespace App\Modules\Surveys\Enums;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Carbon\Carbon;

enum DataTransform: string
{
    case Trim = 'trim';
    case Lowercase = 'lowercase';
    case Uppercase = 'uppercase';
    case NormalizePhone = 'normalize_phone';
    case NormalizeEmail = 'normalize_email';
    case MapDepartmentByName = 'map_department_by_name';
    case MapUserByEmail = 'map_user_by_email';
    case ParseDate = 'parse_date';
    case ToInteger = 'to_integer';
    case ToBoolean = 'to_boolean';

    public function label(): string
    {
        return match ($this) {
            self::Trim => 'إزالة المسافات',
            self::Lowercase => 'تحويل لأحرف صغيرة',
            self::Uppercase => 'تحويل لأحرف كبيرة',
            self::NormalizePhone => 'توحيد صيغة الهاتف',
            self::NormalizeEmail => 'توحيد صيغة البريد',
            self::MapDepartmentByName => 'ربط بالقسم (بالاسم)',
            self::MapUserByEmail => 'ربط بالمستخدم (بالبريد)',
            self::ParseDate => 'تحويل لتاريخ',
            self::ToInteger => 'تحويل لرقم صحيح',
            self::ToBoolean => 'تحويل لقيمة منطقية',
        };
    }

    public function apply(mixed $value, ?int $organizationId = null): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Trim => is_string($value) ? trim($value) : $value,
            self::Lowercase => is_string($value) ? mb_strtolower($value) : $value,
            self::Uppercase => is_string($value) ? mb_strtoupper($value) : $value,
            self::NormalizePhone => $this->normalizePhone($value),
            self::NormalizeEmail => $this->normalizeEmail($value),
            self::MapDepartmentByName => $this->mapDepartmentByName($value, $organizationId),
            self::MapUserByEmail => $this->mapUserByEmail($value, $organizationId),
            self::ParseDate => $this->parseDate($value),
            self::ToInteger => (int) $value,
            self::ToBoolean => (bool) $value,
        };
    }

    private function normalizePhone(mixed $value): string
    {
        if (! is_string($value)) {
            return (string) $value;
        }

        // إزالة كل شيء ما عدا الأرقام وعلامة +
        $phone = preg_replace('/[^0-9+]/', '', $value);

        // إذا يبدأ بـ 05 (سعودي) → تحويل لـ +966
        if (str_starts_with($phone, '05') && strlen($phone) === 10) {
            $phone = '+966'.substr($phone, 1);
        }

        return $phone;
    }

    private function normalizeEmail(mixed $value): string
    {
        if (! is_string($value)) {
            return (string) $value;
        }

        return mb_strtolower(trim($value));
    }

    private function mapDepartmentByName(mixed $value, ?int $organizationId): ?int
    {
        if (! is_string($value) || empty($value) || ! $organizationId) {
            return null;
        }

        $department = Department::where('organization_id', $organizationId)
            ->where('name', $value)
            ->first();

        return $department?->id;
    }

    private function mapUserByEmail(mixed $value, ?int $organizationId): ?int
    {
        if (! is_string($value) || empty($value) || ! $organizationId) {
            return null;
        }

        $user = User::where('organization_id', $organizationId)
            ->where('email', mb_strtolower(trim($value)))
            ->first();

        return $user?->id;
    }

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * تطبيق قائمة من التحويلات على قيمة
     */
    public static function applyTransforms(mixed $value, array $transforms, ?int $organizationId = null): mixed
    {
        foreach ($transforms as $transform) {
            $enum = self::tryFrom($transform);
            if ($enum) {
                $value = $enum->apply($value, $organizationId);
            }
        }

        return $value;
    }
}
