<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified governance rules — the single source of truth for "which department
 * governs a resource type org-wide" (ADR-UNIFIED-ROLE-ACCESS, Phase 1). Replaces
 * the three scattered per-module settings: risks_governing_department (Risk),
 * ovr_governing_department (OVR), and project_type_governing_departments (Projects).
 *
 * A rule says: within $organization_id, members of (the subtree of) $governing_unit_id
 * with a view grant on $resource_type[/$resource_subtype] oversee that resource
 * org-wide. NULL $resource_subtype = all subtypes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('governance_rules')) {
            return;
        }

        Schema::create('governance_rules', function (Blueprint $table) {
            $table->id();
            // Nullable: legacy governing departments could be org-less (org_id NULL);
            // preserve that a null-org department can govern. Cascade on org delete.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('resource_type'); // risk | ovr | project
            $table->string('resource_subtype')->nullable(); // project type; NULL = all subtypes
            $table->foreignId('governing_unit_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->json('capabilities'); // granted capability scope, e.g. ["risks.*"]
            $table->boolean('applies_to_children')->default(true);
            $table->timestamps();

            $table->index('organization_id');
            // NULL resource_subtype cannot be enforced by a plain unique index in
            // Postgres (NULLs are distinct), so the model resolver normalizes on read
            // and the writers upsert on the (org, type, subtype) triple.
            $table->unique(['organization_id', 'resource_type', 'resource_subtype'], 'governance_rules_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_rules');
    }
};
