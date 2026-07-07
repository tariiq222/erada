<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\LogsActivity;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeProfile extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): EmployeeProfileFactory
    {
        return EmployeeProfileFactory::new();
    }

    public const TYPES = ['full_time', 'part_time', 'contract'];

    public const STATUSES = ['active', 'suspended', 'terminated', 'on_leave'];

    public const CONTRACT_TYPES = ['self_employed', 'civil_service'];

    public const STAFF_CATEGORIES = ['medical', 'administrative'];

    protected array $trackedFields = [
        'employee_no',
        'hire_date',
        'employment_type',
        'employment_status',
    ];

    protected $fillable = [
        'user_id',
        'employee_no',
        'hire_date',
        'ministry_hire_date',
        'employment_type',
        'employment_status',
        'contract_type',
        'social_insurance_number',
        'specialization',
        'current_work_field',
        'fingerprint_number',
        'staff_category',
        'notes',
    ];

    protected $appends = ['is_medical_staff'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personalInfo(): HasOne
    {
        return $this->hasOne(EmployeePersonalInfo::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(EmployeeCertificate::class);
    }

    public function getIsMedicalStaffAttribute(): bool
    {
        return $this->staff_category === 'medical';
    }

    protected static function booted(): void
    {
        static::deleting(function (EmployeeProfile $profile) {
            if (! $profile->isForceDeleting()) {
                $profile->personalInfo?->delete();
                $profile->certificates()->delete();
            }
        });

        static::restoring(function (EmployeeProfile $profile) {
            $profile->personalInfo()->withTrashed()->restore();
            $profile->certificates()->withTrashed()->restore();
        });
    }
}
