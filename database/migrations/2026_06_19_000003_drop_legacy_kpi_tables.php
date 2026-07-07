<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('strategic_kpi_measurements');
        Schema::dropIfExists('strategic_kpis');
        Schema::dropIfExists('project_kpis');
    }

    public function down(): void
    {
        // Intentionally a no-op: the tables dropped in up() are not
        // recoverable from this migration. The drop is documented in the
        // post-cutover decision (see CHANGELOG.md for the engine cutover
        // context). A future backfill would need to re-seed from a
        // snapshot, not from a missing backup. The dropped tables
        // (strategic_kpi_measurements, strategic_kpis, project_kpis) were
        // the pre-Phase-6 dual-source KPI store; the canonical source is
        // now App\Modules\Performance (Performance KPIs). Recreating the
        // legacy tables would not restore any code path that reads them
        // and would re-introduce the dual-source split the cutover
        // removed.
    }
};
