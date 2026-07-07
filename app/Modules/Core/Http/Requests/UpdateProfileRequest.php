<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * الصلاحية للقيام بالطلب
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'regex:/^[\p{Arabic}\p{L}\s\-\.]+$/u', // عربي/إنجليزي فقط
            ],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[\d\s\+\-\(\)]+$/', // أرقام ورموز الهاتف فقط
            ],
            'extension' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^\d+$/', // أرقام فقط
            ],
            'job_title' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * رسائل الخطأ المخصصة
     */
    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'name.min' => 'الاسم قصير جداً',
            'name.max' => 'الاسم طويل جداً',
            'name.regex' => 'الاسم يجب أن يحتوي على حروف فقط',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقاً',
            'phone.regex' => 'رقم الهاتف غير صالح',
            'phone.max' => 'رقم الهاتف طويل جداً',
            'extension.regex' => 'الرقم الفرعي يجب أن يحتوي على أرقام فقط',
            'job_title.max' => 'المسمى الوظيفي طويل جداً',
        ];
    }
}
