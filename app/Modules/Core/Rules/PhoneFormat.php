<?php

namespace App\Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lightweight phone-number format check.
 *
 * The system stores phone as a free-form `string` (max 50) so users can
 * enter any reasonable local or international representation. The
 * registration form's `<input type="tel">` already gives the browser a
 * chance to validate via `pattern`; this rule is the server-side
 * belt-and-braces that rejects obvious garbage (too short, no digits at
 * all, or characters outside the common set).
 *
 * Accepted shape: 7 to 20 characters from the set
 *   digits, spaces, hyphens, dots, parentheses, and a leading +.
 * At least 7 of the characters must be digits — that's the floor for
 * a real phone number (E.164 minimum) and rejects "abc" or "123".
 */
class PhoneFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // nullable/empty is the caller's decision
        }

        if (mb_strlen($value) > 20 || mb_strlen($value) < 7) {
            $fail('رقم الجوال يجب أن يكون بين 7 و 20 حرفاً.');

            return;
        }

        if (! preg_match('/^\+?[\d\s().-]+$/', $value)) {
            $fail('رقم الجوال يحتوي على أحرف غير مسموحة.');

            return;
        }

        $digitCount = preg_match_all('/\d/', $value);
        if ($digitCount < 7) {
            $fail('رقم الجوال يجب أن يحتوي على 7 أرقام على الأقل.');
        }
    }
}
