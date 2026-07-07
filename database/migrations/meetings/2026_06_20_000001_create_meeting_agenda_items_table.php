<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('agenda_requested_at')->nullable()->after('agenda');
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->integer('position')->default(0);
            $table->text('review_note')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('meeting_id', 'agenda_items_meeting_idx');
            $table->index(['meeting_id', 'status'], 'agenda_items_meeting_status_idx');
            $table->index('organization_id', 'agenda_items_org_idx');
        });

        DB::statement("ALTER TABLE meeting_agenda_items ADD CONSTRAINT agenda_items_status_check CHECK (status IN ('pending','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_agenda_items');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('agenda_requested_at');
        });
    }
};
