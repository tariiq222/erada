<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hash 2FA recovery codes at rest instead of storing them as APP_KEY-encrypted
     * plaintext. The new column stores bcrypt hashes (Hash::make) which are
     * one-way — a DB leak no longer allows recovery of the codes, and the codes
     * are verified via Hash::check. Consumed codes are removed from the array.
     *
     * The `two_factor_secret` column is left untouched in this migration
     * (TOTP needs the secret in original form). TODO: move the secret to the
     * Laravel `encrypted` cast in a follow-up to remove manual Crypt calls.
     *
     * IMPORTANT: existing users with 2FA enabled will need to re-enroll or call
     * POST /api/2fa/recovery-codes to regenerate. Old recovery codes (stored as
     * Crypt::encryptString strings) cannot be safely migrated to hashes — the new
     * scheme is one-way (Hash::make) and we deliberately do not decrypt+rehash
     * old codes to keep the security boundary clean.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('two_factor_recovery_code_hashes')
                ->nullable()
                ->after('two_factor_recovery_codes');
        });

        // Invalidate all existing recovery codes — users must re-enroll or
        // regenerate. The old column is left in place (vestigial, always NULL)
        // so a follow-up migration can drop it after the new scheme is validated.
        DB::table('users')
            ->whereNotNull('two_factor_recovery_codes')
            ->update(['two_factor_recovery_codes' => null]);
    }

    /**
     * The old encrypted codes were nulled in up() and are no longer recoverable.
     * down() only removes the new column; the old `two_factor_recovery_codes`
     * column is left untouched (it was already nulled in up()).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_recovery_code_hashes');
        });
    }
};
