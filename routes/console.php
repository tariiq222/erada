<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// إغلاق الاستبيانات المنتهية كل ساعة
Schedule::command('surveys:expire')->hourly()->withoutOverlapping()->onOneServer();

// حذف ملفات مرفقات التعليقات الخاصة المؤهلة بعد مدة الاحتفاظ يومياً
Schedule::command('attachments:purge-private')->daily()->withoutOverlapping()->onOneServer();

// OVR: أرشفة التقارير المغلقة يومياً
Schedule::command('ovr:archive-closed')->daily()->withoutOverlapping()->onOneServer();

// OVR: تذكير قبل انتهاء SLA كل ساعة
Schedule::command('ovr:notify-sla-due')->hourly()->withoutOverlapping()->onOneServer();

// OVR: إرجاع التقارير العالقة في انتظار المعلومات يومياً
Schedule::command('ovr:notify-pending-timeout')->daily()->withoutOverlapping()->onOneServer();

// RiskManagement: فحص التقييمات المستحقة يومياً
Schedule::command('risks:check-due-evaluations')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// RiskManagement: تذكير بالإجراءات المتأخرة يومياً
Schedule::command('risks:notify-overdue-actions')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// Meetings: تذكير بالاجتماعات المجدولة كل 15 دقيقة
Schedule::command('meetings:send-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Meetings: تذكير بالتوصيات المتأخرة يومياً في 08:00
Schedule::command('recommendations:check-overdue')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Authorization: nightly reconcile of auto scoped roles so drift from bulk HR
// edits (department moves, manager swaps) self-heals without manual intervention.
Schedule::command('roles:reconcile')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Prune failed queue jobs older than 72 hours, daily at 03:00.
Schedule::command('queue:prune-failed --hours=72')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
