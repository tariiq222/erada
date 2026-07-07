<?php

namespace App\Modules\OVR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\ReportComment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DestroyReportCommentRequest - delete an OVR report comment.
 *
 * Authorization: the comment author can always delete their own comment; an
 * admin (engine: OVR_DELETE_ALL on the parent report) can delete any comment
 * on a report in their organization. Route model binding supplies `report`
 * (IncidentReport) and `comment` (ReportComment); the comment MUST belong to
 * the report (404 otherwise) before authorize() evaluates.
 */
class DestroyReportCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');
        $comment = $this->route('comment');

        if (! $report instanceof IncidentReport || ! $comment instanceof ReportComment) {
            return false;
        }

        if ($comment->report_id !== $report->id) {
            abort(404, 'التعليق غير موجود في هذا البلاغ');
        }

        $user = $this->user();

        // Comment authors can always delete their own comment.
        if ($comment->user_id === $user->id) {
            return true;
        }

        // Admins (engine OVR_DELETE_ALL on the report) can delete any comment.
        return AccessDecision::can($user, Capability::OVR_DELETE_ALL, $report);
    }

    public function rules(): array
    {
        return [];
    }
}
