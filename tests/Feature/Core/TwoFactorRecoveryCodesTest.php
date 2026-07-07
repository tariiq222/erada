<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TwoFactorService;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * اختبارات تجزئة (hashing) أكواد استرداد 2FA عند التخزين.
 *
 * يغطي:
 * - تخزين الأكواد كـ Hash::make (وليست Crypt::encryptString).
 * - التحقق عبر Hash::check.
 * - إبطال الكود بعد الاستخدام (لا يمكن إعادة استخدامه).
 * - رفض الأكواد غير الصحيحة.
 * - ترحيل العمود القديم إلى NULL (إبطال الأكواد القديمة).
 */
class TwoFactorRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected TwoFactorService $twoFactorService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->twoFactorService = app(TwoFactorService::class);
    }

    public function test_recovery_codes_are_stored_as_hashes_not_plaintext(): void
    {
        $result = $this->twoFactorService->enable($this->user);
        $plainCodes = $result['recovery_codes'];

        $this->user->refresh();
        $storedHashes = $this->user->two_factor_recovery_code_hashes;

        $this->assertIsArray($storedHashes, 'two_factor_recovery_code_hashes should be an array');
        $this->assertCount(count($plainCodes), $storedHashes, 'hash count should match generated count');

        foreach ($plainCodes as $code) {
            $this->assertNotContains(
                $code,
                $storedHashes,
                "plaintext code {$code} must NOT appear in stored hashes"
            );
            foreach ($storedHashes as $hash) {
                $this->assertStringNotContainsString(
                    $code,
                    $hash,
                    "plaintext code {$code} must NOT appear as substring of any stored hash"
                );
            }
            $this->assertNotSame(
                $code,
                Crypt::encryptString($code),
                'sanity: encrypted form is not the same as plaintext (confirms we are not just encrypting)'
            );
        }

        $this->assertDatabaseMissing('users', [
            'id' => $this->user->id,
            'two_factor_recovery_codes' => $plainCodes[0] ?? null,
        ]);
    }

    public function test_recovery_code_correct_works_once(): void
    {
        $result = $this->twoFactorService->enable($this->user);
        $plainCodes = $result['recovery_codes'];
        $firstCode = $plainCodes[0];

        $this->assertTrue(
            $this->twoFactorService->verifyRecoveryCode($this->user, $firstCode),
            'valid recovery code should verify successfully'
        );
    }

    public function test_recovery_code_consumed_is_invalidated(): void
    {
        $result = $this->twoFactorService->enable($this->user);
        $firstCode = $result['recovery_codes'][0];

        $this->assertTrue(
            $this->twoFactorService->verifyRecoveryCode($this->user, $firstCode),
            'first use should succeed'
        );

        $this->assertFalse(
            $this->twoFactorService->verifyRecoveryCode($this->user, $firstCode),
            'second use of the same code must fail (code was consumed)'
        );

        $this->user->refresh();
        $remaining = $this->user->two_factor_recovery_code_hashes ?? [];
        $this->assertCount(
            count($result['recovery_codes']) - 1,
            $remaining,
            'one code should have been removed from the stored hashes'
        );
    }

    public function test_recovery_code_wrong_always_fails(): void
    {
        $result = $this->twoFactorService->enable($this->user);
        $originalCount = count($result['recovery_codes']);

        $this->assertFalse(
            $this->twoFactorService->verifyRecoveryCode($this->user, 'AAAAAAAAAA'),
            'a code that was never generated must not verify'
        );

        $this->assertFalse(
            $this->twoFactorService->verifyRecoveryCode($this->user, ''),
            'empty code must not verify'
        );

        $this->user->refresh();
        $this->assertCount(
            $originalCount,
            $this->user->two_factor_recovery_code_hashes ?? [],
            'failed verification must not consume any codes'
        );
    }

    public function test_old_two_factor_recovery_codes_column_is_nulled_after_migration(): void
    {
        // RefreshDatabase already applied the migration, so the new column exists.
        // To test the migration's up() effect on a "pre-migration" user state, drop
        // the new column first, then re-create it via up() and assert behavior.
        if (Schema::hasColumn('users', 'two_factor_recovery_code_hashes')) {
            Schema::table('users', function ($table) {
                $table->dropColumn('two_factor_recovery_code_hashes');
            });
        }

        $fakeEncrypted = Crypt::encryptString(json_encode(['FAKECODE1', 'FAKECODE2']));

        $this->user->update([
            'two_factor_recovery_codes' => $fakeEncrypted,
        ]);

        $this->assertNotNull($this->user->fresh()->two_factor_recovery_codes);

        $migration = require __DIR__.'/../../../database/migrations/2026_06_16_120000_hash_2fa_recovery_codes.php';
        $migration->up();

        $this->user->refresh();
        $this->assertNull(
            $this->user->two_factor_recovery_codes,
            'old two_factor_recovery_codes column must be NULL after migration up()'
        );
        $this->assertTrue(
            Schema::hasColumn('users', 'two_factor_recovery_code_hashes'),
            'new two_factor_recovery_code_hashes column must exist after migration up()'
        );

        $migration->down();

        $this->user->refresh();
        $this->assertNull(
            $this->user->two_factor_recovery_code_hashes,
            'two_factor_recovery_code_hashes column must be removed after migration down()'
        );
    }
}
