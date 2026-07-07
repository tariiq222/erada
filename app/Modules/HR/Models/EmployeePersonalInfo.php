<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Traits\LogsActivity;
use Database\Factories\EmployeePersonalInfoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeePersonalInfo extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): EmployeePersonalInfoFactory
    {
        return EmployeePersonalInfoFactory::new();
    }

    protected $table = 'employee_personal_info';

    protected array $trackedFields = [
        'national_id',
        'iqama_number',
        'nationality',
        'gender',
        'birth_date',
        'full_name_english',
    ];

    protected $fillable = [
        'employee_profile_id',
        'full_name_english',
        'full_name_arabic',
        'nationality',
        'gender',
        'birth_date',
        'address',
        'emergency_contact',
        'emergency_phone',
        'emergency_contact_relation',
        'national_id',
        'national_id_issue_date',
        'national_id_issue_place',
        'national_id_expiry_date',
        'national_id_document_path',
        'iqama_number',
        'iqama_issue_date',
        'iqama_issue_place',
        'iqama_expiry_date',
        'iqama_document_path',
        'profession',
        'religion',
        'sponsor',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id_issue_date' => 'date',
            'national_id_expiry_date' => 'date',
            'iqama_issue_date' => 'date',
            'iqama_expiry_date' => 'date',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function scopeSaudi(Builder $query): Builder
    {
        return $query->where('nationality', 'SA');
    }

    public function scopeNonSaudi(Builder $query): Builder
    {
        return $query->where('nationality', '!=', 'SA');
    }

    public function isSaudi(): bool
    {
        return $this->nationality === 'SA';
    }
}
