<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Traits\LogsActivity;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

class EmployeeCertificate extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): CertificateFactory
    {
        return CertificateFactory::new();
    }

    public const TYPES = [
        'graduation',
        'bls',
        'acls',
        'medical_malpractice_insurance',
        'health_specialties',
        'additional_qualifications',
    ];

    public const MEDICAL_TYPES = [
        'bls',
        'acls',
        'medical_malpractice_insurance',
        'health_specialties',
    ];

    protected array $trackedFields = [
        'type',
        'expires_at',
        'file_path',
    ];

    protected $fillable = [
        'employee_profile_id',
        'type',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'issued_at',
        'expires_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'file_size' => 'integer',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->asDateTime($this->expires_at)->isPast();
    }

    public function isExpiringSoon(int $days = 90): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $expires = $this->asDateTime($this->expires_at);

        if ($expires->isPast()) {
            return true;
        }

        return $expires->diffInDays(now()) <= $days;
    }

    public function getDownloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'hr.certificates.download',
            now()->addMinutes(15),
            ['certificate' => $this->id]
        );
    }
}
