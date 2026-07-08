<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Meeting Resolutions Foundation — create `resolution_links`.
 *
 * Direction R: resolutions can be linked to multiple projects / risks
 * independently of the meeting they came from. We keep the link surface as
 * an explicit pivot (instead of a polymorphic column on `meeting_resolutions`)
 * so a single resolution can simultaneously be `related_to` a project and
 * declare an `implementation_scope` against a risk, without forcing the
 * creator to pick a single primary target.
 *
 * The `linkable_type` values are intentionally a constrained string allowlist
 * (`project` | `risk`) — there is no `morphMap` for resolution links; the
 * controller resolves the alias to a FQCN before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resolution_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained('meeting_resolutions')->cascadeOnDelete();
            $table->string('linkable_type', 50);
            $table->unsignedBigInteger('linkable_id');
            $table->string('link_role', 30)->default('related_to');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['resolution_id'], 'resolution_links_resolution_idx');
            $table->index(['linkable_type', 'linkable_id'], 'resolution_links_linkable_idx');
            $table->unique(
                ['resolution_id', 'linkable_type', 'linkable_id', 'link_role'],
                'resolution_links_unique_combo',
            );
        });

        DB::statement(
            'ALTER TABLE resolution_links ADD CONSTRAINT resolution_links_linkable_type_check '
            ."CHECK (linkable_type IN ('project', 'risk'))"
        );
        DB::statement(
            'ALTER TABLE resolution_links ADD CONSTRAINT resolution_links_link_role_check '
            ."CHECK (link_role IN ('related_to', 'implementation_scope'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('resolution_links');
    }
};
