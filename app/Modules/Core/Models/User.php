<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Traits\CanonicalDepartmentAssignments;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\Stakeholder;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Traits\LogsActivity;
use App\Modules\Tasks\Models\Task;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use CanonicalDepartmentAssignments, HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;

    /**
     * الحقول التي نريد تتبعها في سجل التغييرات
     */
    protected array $trackedFields = [
        'name',
        'email',
        'organization_id',
        'department_id',
        'phone',
        'extension',
        'job_title',
        'is_active',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'department_id',
        'phone',
        'extension',
        'job_title',
        'preferred_locale',
        'is_active',
        'registration_status',
        'registration_approved_at',
        'registration_approved_by',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_recovery_code_hashes',
        'two_factor_confirmed_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_recovery_code_hashes',
        'two_factor_confirmed_at',
        'two_factor_required',
        'failed_login_attempts',
        'last_failed_login_at',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'registration_approved_at' => 'datetime',
            // حقول الأمان
            'locked_until' => 'datetime',
            'failed_login_attempts' => 'integer',
            'last_failed_login_at' => 'datetime',
            'last_login_at' => 'datetime',
            // حقول 2FA
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_required' => 'boolean',
            'two_factor_recovery_code_hashes' => 'array',
        ];
    }

    /**
     * هل المصادقة الثنائية مفعلة؟
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && ! empty($this->two_factor_confirmed_at);
    }

    /**
     * هل الحساب مقفل حالياً؟
     */
    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Generate a secure random password for new users.
     *
     * The password meets complexity requirements:
     * - 16 characters minimum
     * - Contains uppercase, lowercase, numbers, and special characters
     * - Cryptographically secure random generation
     *
     * @return string The generated password (plaintext, for one-time display/email if needed)
     */
    public static function generateSecurePassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // Excluded I, O to avoid confusion
        $lowercase = 'abcdefghjkmnpqrstuvwxyz'; // Excluded i, l, o to avoid confusion
        $numbers = '23456789'; // Excluded 0, 1 to avoid confusion
        $special = '@$!%*#?&';

        // Ensure at least one of each required character type
        $password = $uppercase[random_int(0, strlen($uppercase) - 1)]
                  .$lowercase[random_int(0, strlen($lowercase) - 1)]
                  .$numbers[random_int(0, strlen($numbers) - 1)]
                  .$special[random_int(0, strlen($special) - 1)];

        // Fill remaining characters from all character sets
        $allChars = $uppercase.$lowercase.$numbers.$special;
        for ($i = 4; $i < 16; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle to avoid predictable pattern (first 4 chars always same types)
        $password = str_shuffle($password);

        return $password;
    }

    // المؤسسة
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // القسم
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // الملف الوظيفي (HR)
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    // المهام المكلف بها (من موديول Tasks الموحد)
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    // المهام التي أنشأها
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    // المهام الشخصية (التي يملكها)
    public function ownedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'owner_id');
    }

    // المهام الشخصية فقط
    public function personalTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'owner_id')
            ->where('type', 'personal');
    }

    // جميع المهام المتعلقة بالمستخدم (مكلف أو مالك أو منشئ)
    public function allTasks()
    {
        return Task::where(function ($query) {
            $query->where('assigned_to', $this->id)
                ->orWhere('owner_id', $this->id)
                ->orWhere('created_by', $this->id);
        });
    }

    // التعليقات
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // الأقسام التي يديرها
    public function managedDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'manager_id');
    }

    /**
     * Canonical role assignments. Callers making authorization decisions must
     * additionally constrain lifecycle and scope, normally through
     * activeCanonicalRoleAssignments().
     */
    /** @return HasMany<AuthorizationRoleAssignment, $this> */
    public function canonicalRoleAssignments(): HasMany
    {
        return $this->hasMany(AuthorizationRoleAssignment::class, 'user_id');
    }

    /** @return HasMany<AuthorizationRoleAssignment, $this> */
    public function activeCanonicalRoleAssignments(): HasMany
    {
        return $this->canonicalRoleAssignments()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('role', fn ($query) => $query->where('is_active', true));
    }

    /** @return array<int, string> */
    public function canonicalRoleNames(): array
    {
        if ($this->relationLoaded('activeCanonicalRoleAssignments')) {
            return $this->activeCanonicalRoleAssignments
                ->pluck('role.name')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $this->activeCanonicalRoleAssignments()
            ->with('role:id,name,is_active')
            ->get()
            ->pluck('role.name')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function canonicalCapabilityNames(): array
    {
        if ($this->isSuperAdmin()) {
            return Capability::all();
        }

        $roleIds = $this->activeCanonicalRoleAssignments()
            ->pluck('authorization_role_id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $grants = AuthorizationRolePermission::query()
            ->with('resource:id,key')
            ->whereIn('authorization_role_id', $roleIds)
            ->get()
            ->map(fn (AuthorizationRolePermission $permission) => [
                'resource' => $permission->resource?->key,
                'action' => $permission->action,
            ]);

        return collect(CapabilityToAuthorizationRolePermission::mapAll())
            ->filter(fn (array $mapping) => $grants->contains(
                fn (array $grant) => $grant['resource'] === $mapping['resource']
                    && $grant['action'] === $mapping['action']
            ))
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    // هل المستخدم Super Admin
    public function isSuperAdmin(): bool
    {
        return $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ALL)
            ->whereNull('scope_id')
            ->whereHas('role', fn ($query) => $query
                ->where('name', 'super_admin')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ALL)
                ->where('is_system', true))
            ->exists();
    }

    // هل المستخدم Org Admin (مدير على مستوى المؤسسة عبر Authorization Role)
    public function isOrgAdmin(): bool
    {
        return $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
            ->whereNotNull('scope_id')
            ->whereHas('role', fn ($query) => $query
                ->where('name', 'admin')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
                ->where('is_admin_role', true))
            ->exists();
    }

    // هل المستخدم Organization Super Admin — الدور الموحّد الجديد على مستوى المؤسسة.
    //
    // الإعداد:
    //   - name = 'organization_super_admin'
    //   - scope_type = 'organization'  (server-derived, لا يقبل X-Organization-Id للتوسيع)
    //   - is_admin_role = false        (يحجب اختصار AccessDecision::whyCan() للمدير)
    //   - is_system = true             (يحجز الدور في كتالوج البذور)
    //
    // التمييز عن isOrgAdmin() ضروري — كلاهما scope_type=organization لكن
    // الاختلاف في is_admin_role يحدد سلوك المحرّك في فرع
    // AccessDecision.php:~1170 (الـ admin-shortcut).
    public function isOrganizationSuperAdmin(): bool
    {
        return $this->activeCanonicalRoleAssignments()
            ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
            ->whereNotNull('scope_id')
            ->whereHas('role', fn ($query) => $query
                ->where('name', 'organization_super_admin')
                ->where('scope_type', AuthorizationRoleAssignment::SCOPE_ORGANIZATION)
                ->where('is_admin_role', false)
                ->where('is_system', true))
            ->exists();
    }

    /**
     * Resolve the organization to scope queries to, honoring the org picked in the
     * header. Only super_admin may switch; everyone else stays locked to their own
     * organization so the header can never widen their scope. Returns null = all orgs.
     */
    public function resolveActiveOrganizationId(?int $requested): ?int
    {
        if (! $this->isSuperAdmin()) {
            return $this->organization_id;
        }

        return $requested ?: null;
    }

    // هل المستخدم في تير الإدارة — مقاد بمحرك AuthZ الموحد عبر Capability::SETTINGS_MANAGE
    // (super_admin يتجاوز المحرّك تلقائياً).
    public function isAdmin(): bool
    {
        return AccessDecision::canonicalTrace($this, Capability::SETTINGS_MANAGE)['granted'];
    }

    // ========== علاقات التتبع ==========

    // المستخدم الذي أضاف هذا الحساب
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // المستخدم الذي قام بآخر تعديل
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // أدوار أصحاب المصلحة
    public function stakeholderRoles(): HasMany
    {
        return $this->hasMany(Stakeholder::class, 'user_id');
    }

    // المصروفات التي أنشأها
    public function createdExpenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class, 'created_by');
    }

    // مؤشرات الأداء التي يملكها (نظام Performance)
    public function ownedKpis(): HasMany
    {
        return $this->hasMany(Kpi::class, 'owner_id');
    }
}
