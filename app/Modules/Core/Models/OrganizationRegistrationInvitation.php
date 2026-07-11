<?php

namespace App\Modules\Core\Models;

use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrganizationRegistrationInvitation extends Model
{
    protected $fillable = [
        'organization_id',
        'department_id',
        'email',
        'token_hash',
        'expires_at',
        'consumed_at',
        'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issue(int $organizationId, ?int $departmentId, string $email, ?int $invitedBy = null): array
    {
        $token = Str::random(64);

        return [
            static::create([
                'organization_id' => $organizationId,
                'department_id' => $departmentId,
                'email' => mb_strtolower($email),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
                'invited_by' => $invitedBy,
            ]),
            $token,
        ];
    }
}
