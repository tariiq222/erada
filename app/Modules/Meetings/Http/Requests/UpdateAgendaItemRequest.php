<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateAgendaItemRequest - التحقق من بيانات تحديث نقطة جدول أعمال.
 *
 * صلاحية View على الاجتماع (المالك/المنظم/الحاضر يستطيع التعديل على نقاطه المعلّقة).
 * الفحص الدقيق لـ "pending + owner" يبقى في الـ Controller.
 */
class UpdateAgendaItemRequest extends FormRequest
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
