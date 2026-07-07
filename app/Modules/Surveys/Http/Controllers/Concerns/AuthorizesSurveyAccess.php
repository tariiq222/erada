<?php

namespace App\Modules\Surveys\Http\Controllers\Concerns;

use App\Modules\Surveys\Models\Survey;
use Illuminate\Http\Request;

/**
 * فحص صلاحية الوصول للاستبيان (عزل المؤسسات) لكل الـ controllers المتداخلة.
 *
 * موحّد من 4 نسخ متطابقة كانت تحتوي ثغرة null-org (D-02).
 */
trait AuthorizesSurveyAccess
{
    /**
     * التحقق من صلاحية الوصول للاستبيان.
     */
    protected function authorizeSurvey(Request $request, Survey $survey): void
    {
        $user = $request->user();

        // super_admin يتجاوز — authorizeSurvey ليست فحص Gate، لذا Gate::before
        // (AppServiceProvider:72) لا يغطّيها؛ التجاوز يجب أن يكون صريحاً هنا.
        if ($user?->isSuperAdmin()) {
            return;
        }

        if ($user === null
            || $user->organization_id === null
            || $survey->organization_id === null
            || $survey->organization_id !== $user->organization_id) {
            abort(403, 'غير مصرح لك بالوصول لهذا الاستبيان');
        }
    }
}
