<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\OrganizationRegistrationInvitation;
use Illuminate\Validation\ValidationException;

class OrganizationRegistrationInvitationService
{
    public function consume(string $token, string $email): OrganizationRegistrationInvitation
    {
        $invitation = OrganizationRegistrationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('email', mb_strtolower($email))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'invite_token' => ['The invitation is invalid, expired, or already consumed.'],
            ]);
        }

        $invitation->forceFill(['consumed_at' => now()])->save();

        return $invitation;
    }
}
