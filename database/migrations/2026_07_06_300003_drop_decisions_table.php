<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase R1 / Direction B — drop the legacy `decisions` table.
 *
 * The 300001 migration already removed the FK from recommendations, so by
 * the time 300003 runs nothing references `decisions` anymore. The
 * `dropIfExists` is intentionally idempotent — re-running 300003 after a
 * partial fresh is safe.
 *
 * `down()` restores a bare stub (just `id` + timestamps) so a developer
 * who runs `migrate:rollback` gets a coherent schema without a fatal
 * missing-table. Phase R2+ owns the actual data migration; this is
 * rollback-only parity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('decisions');
    }

    public function down(): void
    {
        Schema::create('decisions', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
    }
};
