<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScopeTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! AccessDecision::can($request->user(), Capability::SETTINGS_VIEW)) {
            abort(403, 'غير مصرح بعرض أنواع النطاق');
        }

        return response()->json(['data' => AssignmentScope::catalog()]);
    }
}
