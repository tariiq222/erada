<?php

return [
    'pending_decision_expiry_days' => (int) env('MEETINGS_PENDING_EXPIRY_DAYS', 30),
    'recommendation_overdue_grace_days' => (int) env('MEETINGS_RECOMMENDATION_OVERDUE_GRACE_DAYS', 0),
    'meeting_reminder_window_hours' => (int) env('MEETINGS_REMINDER_WINDOW_HOURS', 24),
    'notification_polling_interval_seconds' => (int) env('MEETINGS_NOTIFICATION_POLL_INTERVAL', 60),
];
