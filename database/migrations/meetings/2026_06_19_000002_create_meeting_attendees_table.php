<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50)->default('attendee');
            $table->boolean('attended')->default(false);
            $table->timestamps();
            $table->primary(['meeting_id', 'user_id']);
            $table->index('user_id', 'meeting_attendees_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendees');
    }
};
