<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAttendee extends Model
{
    public $incrementing = false;

    public $timestamps = true;

    protected $table = 'meeting_attendees';

    protected $primaryKey = null; // composite (meeting_id, user_id)

    protected $keyType = 'int';

    protected $fillable = ['meeting_id', 'user_id', 'role', 'attended'];

    protected $casts = ['attended' => 'boolean'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
