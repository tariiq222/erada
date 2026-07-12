<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Authorization\Data\AssignmentScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AssignCanonicalRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The canonical engine-capability route middleware is the public gate.
        // AuthorizationAssignmentService applies the subject, scope, and
        // privilege-escalation guard inside the write transaction.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'replace_all' => ['required', 'accepted'],
            'assignments' => ['present', 'array'],
            'assignments.*.role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'assignments.*.scope_type' => ['required', 'string', Rule::in(AssignmentScope::TYPES)],
            'assignments.*.scope_id' => ['nullable', 'integer', 'min:1'],
            'assignments.*.inherit_to_children' => ['sometimes', 'boolean'],
            'assignments.*.expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $identities = [];

                foreach ($this->input('assignments', []) as $index => $assignment) {
                    if (! is_array($assignment)) {
                        continue;
                    }

                    $scopeType = $assignment['scope_type'] ?? null;
                    $scopeId = $assignment['scope_id'] ?? null;
                    $allowsNullScope = in_array($scopeType, ['all', 'own'], true);

                    if ($allowsNullScope && $scopeId !== null) {
                        $validator->errors()->add("assignments.{$index}.scope_id", 'هذا النطاق لا يقبل معرّف نطاق.');
                    }

                    if (is_string($scopeType) && ! $allowsNullScope && $scopeId === null) {
                        $validator->errors()->add("assignments.{$index}.scope_id", 'معرّف النطاق مطلوب لهذا النوع.');
                    }

                    $roleId = $assignment['role_id'] ?? null;
                    if (! is_numeric($roleId) || ! is_string($scopeType)) {
                        continue;
                    }

                    $identity = implode(':', [(int) $roleId, $scopeType, $scopeId ?? 'null']);
                    if (isset($identities[$identity])) {
                        $validator->errors()->add("assignments.{$index}", 'لا يمكن تكرار الدور والنطاق نفسيهما في الطلب.');
                    }
                    $identities[$identity] = true;
                }
            },
        ];
    }
}
