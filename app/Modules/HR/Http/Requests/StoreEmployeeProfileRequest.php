<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! AccessDecision::can($user, Capability::HR_MANAGE)) {
            return false;
        }

        $userId = $this->input('user_id');

        if ($userId !== null) {
            $target = User::find($userId);

            if ($target === null) {
                return false;
            }

            if (! $user->isSuperAdmin()
                && ($user->organization_id === null
                    || $target->organization_id !== $user->organization_id)) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        $isMedical = $this->input('staff_category') === 'medical';
        $isSaudi = $this->input('personal_info.nationality') === 'SA';
        $isNonSaudi = $this->input('personal_info.nationality') !== null
            && $this->input('personal_info.nationality') !== 'SA';

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'employee_no' => ['required', 'string', 'max:50', 'unique:employee_profiles,employee_no'],
            'hire_date' => ['required', 'date'],
            'employment_type' => ['required', Rule::in(EmployeeProfile::TYPES)],
            'employment_status' => ['required', Rule::in(EmployeeProfile::STATUSES)],
            'dept_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(function ($q) {
                    $q->where('organization_id', $this->user()->organization_id);
                }),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],

            'ministry_hire_date' => ['nullable', 'date'],
            'contract_type' => ['nullable', Rule::in(EmployeeProfile::CONTRACT_TYPES)],
            'social_insurance_number' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('contract_type') === 'self_employed'),
                'string',
                'max:50',
            ],
            'specialization' => ['nullable', 'string', 'max:255'],
            'current_work_field' => ['nullable', 'string', 'max:255'],
            'fingerprint_number' => ['nullable', 'string', 'max:50'],
            'staff_category' => ['nullable', Rule::in(EmployeeProfile::STAFF_CATEGORIES)],

            'personal_info' => ['required', 'array'],
            'personal_info.full_name_english' => ['required', 'string', 'max:255'],
            'personal_info.full_name_arabic' => ['required', 'string', 'max:150'],
            'personal_info.gender' => ['nullable', Rule::in(['male', 'female'])],
            'personal_info.birth_date' => ['nullable', 'date'],
            'personal_info.nationality' => ['required', 'string', 'size:2'],
            'personal_info.address' => ['required', 'string', 'max:1000'],
            'personal_info.emergency_contact' => ['required', 'string', 'max:150'],
            'personal_info.emergency_phone' => ['required', 'string', 'max:20'],
            'personal_info.emergency_contact_relation' => ['required', 'string', 'max:50'],

            'personal_info.national_id' => [
                Rule::requiredIf($isSaudi),
                'nullable',
                'string',
                'size:10',
                Rule::unique('employee_personal_info', 'national_id'),
            ],
            'personal_info.national_id_issue_date' => [Rule::requiredIf($isSaudi), 'nullable', 'date'],
            'personal_info.national_id_issue_place' => [Rule::requiredIf($isSaudi), 'nullable', 'string', 'max:150'],
            'personal_info.national_id_expiry_date' => [Rule::requiredIf($isSaudi), 'nullable', 'date'],

            'personal_info.iqama_number' => [
                Rule::requiredIf($isNonSaudi),
                'nullable',
                'string',
                'size:10',
                Rule::unique('employee_personal_info', 'iqama_number'),
            ],
            'personal_info.iqama_issue_date' => [Rule::requiredIf($isNonSaudi), 'nullable', 'date'],
            'personal_info.iqama_issue_place' => [Rule::requiredIf($isNonSaudi), 'nullable', 'string', 'max:150'],
            'personal_info.iqama_expiry_date' => [Rule::requiredIf($isNonSaudi), 'nullable', 'date'],
            'personal_info.profession' => [Rule::requiredIf($isNonSaudi), 'nullable', 'string', 'max:150'],
            'personal_info.religion' => [Rule::requiredIf($isNonSaudi), 'nullable', 'string', 'max:50'],
            'personal_info.sponsor' => [Rule::requiredIf($isNonSaudi), 'nullable', 'string', 'max:150'],

            'certificates' => [
                'nullable',
                'array',
                function (string $attribute, $value, \Closure $fail) use ($isMedical) {
                    if (! $isMedical) {
                        return;
                    }

                    $types = collect($value ?? [])->pluck('type')->all();

                    foreach (['medical_malpractice_insurance', 'health_specialties'] as $required) {
                        if (! in_array($required, $types, true)) {
                            $fail("شهادة {$required} مطلوبة للطاقم الطبي.");
                        }
                    }

                    if (! array_intersect(['bls', 'acls'], $types)) {
                        $fail('شهادة BLS أو ACLS مطلوبة للطاقم الطبي.');
                    }
                },
            ],
            'certificates.*.type' => ['required_with:certificates', Rule::in(EmployeeCertificate::TYPES)],
            'certificates.*.title' => ['nullable', 'string', 'max:255'],
            'certificates.*.issued_at' => ['nullable', 'date'],
            'certificates.*.expires_at' => ['nullable', 'date'],
            'certificates.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'المستخدم (الموظف) مطلوب',
            'user_id.exists' => 'المستخدم المحدد غير موجود',
            'employee_no.required' => 'الرقم الوظيفي مطلوب',
            'employee_no.unique' => 'الرقم الوظيفي مستخدم بالفعل',
            'hire_date.required' => 'تاريخ التعيين مطلوب',
            'employment_type.required' => 'نوع التوظيف مطلوب',
            'employment_type.in' => 'نوع التوظيف غير صالح',
            'employment_status.required' => 'حالة التوظيف مطلوبة',
            'employment_status.in' => 'حالة التوظيف غير صالحة',
            'social_insurance_number.required_if' => 'الرقم التأميني مطلوب للعاملين على نظام العمل الحر',
            'staff_category.in' => 'فئة الموظف يجب أن تكون medical أو administrative',
            'contract_type.in' => 'نوع العقد يجب أن يكون self_employed أو civil_service',
            'personal_info.required' => 'البيانات الشخصية مطلوبة',
            'personal_info.full_name_english.required' => 'الاسم الكامل بالإنجليزية مطلوب',
            'personal_info.full_name_arabic.required' => 'الاسم الكامل بالعربية مطلوب',
            'personal_info.nationality.required' => 'الجنسية مطلوبة',
            'personal_info.nationality.size' => 'رمز الجنسية يجب أن يكون حرفين (ISO-3166-1 alpha-2)',
            'personal_info.address.required' => 'العنوان مطلوب',
            'personal_info.emergency_contact.required' => 'اسم جهة الاتصال في الطوارئ مطلوب',
            'personal_info.emergency_phone.required' => 'رقم هاتف الطوارئ مطلوب',
            'personal_info.emergency_contact_relation.required' => 'صلة القرابة بجهة الاتصال مطلوبة',
            'personal_info.national_id.required_if' => 'رقم الهوية الوطنية مطلوب للمواطن السعودي',
            'personal_info.national_id.size' => 'رقم الهوية يجب أن يكون 10 أرقام',
            'personal_info.national_id.unique' => 'رقم الهوية مستخدم بالفعل',
            'personal_info.national_id_issue_date.required_if' => 'تاريخ إصدار الهوية مطلوب',
            'personal_info.national_id_issue_place.required_if' => 'مكان إصدار الهوية مطلوب',
            'personal_info.national_id_expiry_date.required_if' => 'تاريخ انتهاء الهوية مطلوب',
            'personal_info.iqama_number.required_if' => 'رقم الإقامة مطلوب لغير السعوديين',
            'personal_info.iqama_number.size' => 'رقم الإقامة يجب أن يكون 10 أرقام',
            'personal_info.iqama_number.unique' => 'رقم الإقامة مستخدم بالفعل',
            'personal_info.iqama_issue_date.required_if' => 'تاريخ إصدار الإقامة مطلوب',
            'personal_info.iqama_issue_place.required_if' => 'مكان إصدار الإقامة مطلوب',
            'personal_info.iqama_expiry_date.required_if' => 'تاريخ انتهاء الإقامة مطلوب',
            'personal_info.profession.required_if' => 'المهنة مطلوبة لغير السعوديين',
            'personal_info.religion.required_if' => 'الديانة مطلوبة لغير السعوديين',
            'personal_info.sponsor.required_if' => 'الكفيل مطلوب لغير السعوديين',
            'certificates.*.type.required_with' => 'نوع الشهادة مطلوب',
            'certificates.*.type.in' => 'نوع الشهادة غير صالح',
        ];
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'الموظف',
            'employee_no' => 'الرقم الوظيفي',
            'hire_date' => 'تاريخ التعيين',
            'employment_type' => 'نوع التوظيف',
            'employment_status' => 'حالة التوظيف',
            'notes' => 'ملاحظات',
            'ministry_hire_date' => 'تاريخ التعيين الوزاري',
            'contract_type' => 'نوع العقد',
            'social_insurance_number' => 'الرقم التأميني',
            'specialization' => 'التخصص',
            'current_work_field' => 'مجال العمل الحالي',
            'fingerprint_number' => 'رقم البصمة',
            'staff_category' => 'فئة الموظف',
            'personal_info.full_name_english' => 'الاسم بالإنجليزية',
            'personal_info.full_name_arabic' => 'الاسم بالعربية',
            'personal_info.gender' => 'الجنس',
            'personal_info.birth_date' => 'تاريخ الميلاد',
            'personal_info.nationality' => 'الجنسية',
            'personal_info.address' => 'العنوان',
            'personal_info.emergency_contact' => 'جهة الاتصال',
            'personal_info.emergency_phone' => 'هاتف الطوارئ',
            'personal_info.emergency_contact_relation' => 'صلة القرابة',
        ];
    }
}
