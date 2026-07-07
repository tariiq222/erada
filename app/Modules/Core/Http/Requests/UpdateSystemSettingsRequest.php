<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\SystemSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSystemSettingsRequest extends FormRequest
{
    /**
     * الصلاحية للقيام بالطلب
     */
    public function authorize(): bool
    {
        return Gate::allows('update', SystemSettings::class);
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[\p{Arabic}\p{L}\s\-\.0-9]+$/u',
            ],
            'name_en' => [
                'nullable',
                'string',
                'max:255',
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Z0-9\-_]+$/i',
            ],
            'logo' => [
                'nullable',
                'string',
                'max:500',
            ],
            'region' => [
                'nullable',
                'string',
                'max:255',
            ],
            'city' => [
                'nullable',
                'string',
                'max:255',
            ],
            'address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[\d\s\+\-\(\)]+$/',
            ],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
            ],
            'website' => [
                'nullable',
                'url:http,https',
                'max:255',
            ],
            'settings' => [
                'nullable',
                'array',
            ],
            'settings.date_format' => [
                'nullable',
                'string',
                'in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD',
            ],
            'settings.time_format' => [
                'nullable',
                'string',
                'in:12h,24h',
            ],
            'settings.timezone' => [
                'nullable',
                'string',
                'timezone',
            ],
            'settings.default_language' => [
                'nullable',
                'string',
                'in:ar,en',
            ],
            'settings.maintenance_mode' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * رسائل الخطأ المخصصة
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم النظام مطلوب',
            'name.max' => 'اسم النظام طويل جداً (الحد الأقصى 255 حرف)',
            'name.regex' => 'اسم النظام يحتوي على حروف غير مسموحة',
            'code.regex' => 'الرمز يجب أن يحتوي على حروف إنجليزية وأرقام فقط',
            'phone.regex' => 'رقم الهاتف غير صالح',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'website.url' => 'رابط الموقع غير صالح',
            'settings.timezone.timezone' => 'المنطقة الزمنية غير صالحة',
            'settings.date_format.in' => 'صيغة التاريخ غير مدعومة',
            'settings.time_format.in' => 'صيغة الوقت غير مدعومة',
            'settings.default_language.in' => 'اللغة غير مدعومة',
        ];
    }

    /**
     * تجهيز البيانات قبل التحقق
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && $this->phone) {
            $this->merge([
                'phone' => preg_replace('/\s+/', ' ', trim($this->phone)),
            ]);
        }

        if ($this->has('website') && $this->website) {
            $website = $this->website;
            if (! str_starts_with($website, 'http://') && ! str_starts_with($website, 'https://')) {
                $this->merge(['website' => 'https://'.$website]);
            }
        }
    }
}
