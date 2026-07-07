<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyAgendaItemRequest - التحقق من صلاحية حذف نقطة جدول أعمال.
 *
 * صلاحية View على الاجتماع (المالك/المنظم/الحاضر يستطيع حذف نقاطه المعلّقة).
 * الفحص الدقيق لـ "owner" يبقى في الـ Controller.
 */
class DestroyAgendaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agendaItem = $this->route('agendaItem');

        if (! $agendaItem instanceof MeetingAgendaItem) {
            $agendaItem = MeetingAgendaItem::find($agendaItem);
        }

        if (! $agendaItem) {
            return false;
        }

        $meeting = $agendaItem->meeting;

        if (! $meeting instanceof Meeting) {
            return false;
        }

        return $this->user()?->can('view', $meeting) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
