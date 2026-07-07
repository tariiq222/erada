<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\UpdateSystemSettingsRequest;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Projects\Services\ProjectSettingsService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SystemSettingsController extends Controller
{
    public function __construct(
        protected ProjectSettingsService $settingsService
    ) {}

    /**
     * الحصول على إعدادات النظام (آمنة للعرض العام)
     *
     * يُعيد فقط الحقول الآمنة للعرض العام، مع إخفاء:
     * - بيانات الاتصال الداخلية (region, city, address, phone, email, website)
     * - أي إعدادات حساسة مخزنة في حقل settings JSON
     *
     * الإعدادات الكاملة محصورة بـ SystemSettingsPolicy@update (super_admin / admin فقط).
     */
    public function show(): JsonResponse
    {
        try {
            $settings = SystemSettings::get();

            // ✅ فقط الإعدادات الآمنة للعرض العام
            $publicSettings = [
                'app_name' => SystemSettings::getValue('app_name', $settings->name ?? config('app.name')),
                'app_logo' => SystemSettings::getValue('app_logo', $settings->logo ?? null),
                'default_locale' => SystemSettings::getValue('default_locale', 'ar'),
                'supported_locales' => SystemSettings::getValue('supported_locales', ['ar', 'en']),
            ];

            return response()->json($publicSettings);
        } catch (\Exception $e) {
            Log::error('Failed to load system settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'app_name' => config('app.name', 'نظام إدارة المشاريع'),
                'app_logo' => null,
                'default_locale' => 'ar',
                'supported_locales' => ['ar', 'en'],
            ]);
        }
    }

    /**
     * تحديث إعدادات النظام
     *
     * Authorization: SystemSettingsPolicy@update
     * Rate Limit: admin (20/min)
     */
    public function update(UpdateSystemSettingsRequest $request): JsonResponse
    {
        $this->authorize('update', SystemSettings::class);

        $validated = $request->validated();
        $user = $request->user();

        try {
            $settings = DB::transaction(function () use ($validated, $user) {
                $oldSettings = SystemSettings::get()->toArray();
                $settings = SystemSettings::updateSettings($validated);

                // تسجيل النشاط
                ActivityLog::create([
                    'user_id' => $user->id,
                    'action' => ActivityLog::ACTION_UPDATED,
                    'description' => 'تحديث إعدادات النظام',
                    'loggable_type' => SystemSettings::class,
                    'loggable_id' => $settings->id,
                    'old_values' => $oldSettings,
                    'new_values' => $settings->toArray(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $settings;
            });

            // مسح الكاش بعد نجاح الـ transaction
            $this->settingsService->clearCache();

            Log::info('System settings updated', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'changes' => array_keys($validated),
            ]);

            return response()->json([
                'message' => 'تم تحديث الإعدادات بنجاح',
                'data' => $settings,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $errorId = uniqid('settings_err_');
            Log::error('Failed to update system settings', [
                'error_id' => $errorId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'فشل في تحديث الإعدادات',
                'error_id' => $errorId,
            ], 500);
        }
    }
}
