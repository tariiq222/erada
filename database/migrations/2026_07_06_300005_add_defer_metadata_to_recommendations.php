<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase R2 / Direction B — install the defer-metadata columns on
 * `recommendations`.
 *
 * The Phase R1 model gained a `defer()` helper + the
 * `Recommendation::deferred_until|reason|by|at` columns (referenced in
 * the scope's "KEEP migrations: 200002_add_defer_metadata_to_recommendations"
 * item) but the corresponding migration was never authored on this
 * branch. We add it here — strictly additive — so the Phase R2 controller
 * can persist defer state without the migration failing.
 *
 * Per LR-004, never edit an applied migration; always create a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $t): void {
            $t->string('defer_reason', 5000)->nullable()->after('rationale');
            $t->timestamp('deferred_until')->nullable()->after('defer_reason');
            $t->foreignId('deferred_by')->nullable()->after('deferred_until')
                ->constrained('users')->nullOnDelete();
            $t->timestamp('deferred_at')->nullable()->after('deferred_by');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('deferred_by');
            $t->dropColumn(['defer_reason', 'deferred_until', 'deferred_at']);
        });
    }
};
