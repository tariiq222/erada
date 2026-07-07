import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

// Mock API
const mockProfileUpdate = vi.fn().mockResolvedValue({});
const mockChangePassword = vi.fn().mockResolvedValue({});

// Mock react-i18next with real Arabic translations
vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'common.app_name': 'نظام إرادة', 'common.loading': 'جاري التحميل...', 'common.search_system': 'بحث في النظام...', 'common.all_rights_reserved': 'جميع الحقوق محفوظة',
    'common.save': 'حفظ', 'common.save_changes': 'حفظ التغييرات', 'common.cancel': 'إلغاء', 'common.delete': 'حذف',
    'common.edit': 'تعديل', 'common.create': 'إنشاء', 'common.view': 'عرض', 'common.view_all': 'عرض الكل',
    'common.actions': 'الإجراءات', 'common.search': 'بحث', 'common.filter': 'تصفية', 'common.filters': 'التصفيات',
    'common.reset': 'إعادة تعيين', 'common.confirm': 'تأكيد', 'common.close': 'إغلاق', 'common.back': 'رجوع',
    'common.next': 'التالي', 'common.previous': 'السابق', 'common.yes': 'نعم', 'common.no': 'لا',
    'common.or': 'أو', 'common.of': 'من', 'common.to': 'إلى', 'common.showing': 'عرض',
    'common.results': 'نتيجة', 'common.no_results': 'لا توجد نتائج', 'common.no_data': 'لا توجد بيانات', 'common.required': 'مطلوب',
    'common.optional': 'اختياري', 'common.name': 'الاسم', 'common.email': 'البريد الإلكتروني', 'common.phone': 'رقم الهاتف',
    'common.status': 'الحالة', 'common.priority': 'الأولوية', 'common.progress': 'التقدم', 'common.date': 'التاريخ',
    'common.start_date': 'تاريخ البداية', 'common.end_date': 'تاريخ النهاية', 'common.due_date': 'تاريخ الاستحقاق', 'common.created_at': 'تاريخ الإنشاء',
    'common.updated_at': 'تاريخ التحديث', 'common.description': 'الوصف', 'common.notes': 'ملاحظات', 'common.details': 'التفاصيل',
    'common.department': 'القسم', 'common.manager': 'المدير', 'common.assigned_to': 'مكلف إلى', 'common.created_by': 'أنشئ بواسطة',
    'common.deleting': 'جاري الحذف...', 'common.saving': 'جاري الحفظ...', 'common.success': 'تمت العملية بنجاح', 'common.error': 'خطأ',
    'common.warning': 'تحذير', 'common.info': 'معلومة', 'common.export': 'تصدير', 'common.import': 'استيراد',
    'common.download': 'تحميل', 'common.upload': 'رفع', 'common.add': 'إضافة', 'common.remove': 'إزالة',
    'common.select': 'اختر', 'common.select_all': 'تحديد الكل', 'common.none': 'لا يوجد', 'common.total': 'الإجمالي',
    'common.active': 'نشط', 'common.inactive': 'غير نشط', 'common.enabled': 'مفعل', 'common.disabled': 'معطل',
    'common.all': 'الكل', 'common.day': 'يوم', 'common.days': 'أيام', 'common.refresh_data': 'تحديث البيانات',
    'common.last_update': 'آخر تحديث', 'common.more': 'المزيد', 'common.less': 'أقل', 'common.show_more': 'عرض المزيد',
    'common.show_less': 'عرض أقل', 'common.confirm_delete': 'تأكيد الحذف', 'common.confirm_delete_message': 'هل أنت متأكد من هذا الإجراء؟', 'common.confirm_delete_item': 'هل أنت متأكد من حذف {{itemType}} "{{itemName}}"؟',
    'common.action_irreversible': 'هذا الإجراء لا يمكن التراجع عنه.', 'common.error_occurred': 'حدث خطأ', 'nav.dashboard': 'لوحة التحكم', 'nav.strategy': 'التخطيط التنفيذي',
    'nav.strategy_dashboard': 'لوحة التحكم', 'nav.portfolios': 'الالتزامات التنفيذية', 'nav.programs': 'المبادرات', 'nav.projects': 'المشاريع',
    'nav.my_projects': 'مشاريعي', 'nav.statistics': 'الإحصائيات', 'nav.tasks': 'المهام', 'nav.my_tasks': 'مهامي',
    'nav.surveys': 'الاستبيانات', 'nav.departments': 'الأقسام', 'nav.users': 'المستخدمين', 'nav.profile': 'الملف الشخصي',
    'nav.settings': 'الإعدادات', 'nav.logout': 'تسجيل الخروج', 'nav.admin_panel': 'لوحة الإدارة', 'nav.incidents': 'الحوادث',
    'nav.invitations': 'الدعوات', 'auth.login': 'تسجيل الدخول', 'auth.login_button': 'دخول', 'auth.logging_in': 'جاري الدخول...',
    'auth.login_failed': 'فشل تسجيل الدخول', 'auth.email_label': 'البريد الإلكتروني', 'auth.password_label': 'كلمة المرور', 'auth.remember_me': 'تذكرني',
    'auth.forgot_password': 'نسيت كلمة المرور؟', 'auth.logout_success': 'تم تسجيل الخروج بنجاح', 'auth.account_setup': 'إعداد الحساب', 'auth.setup_account': 'إعداد حسابك',
    'auth.setup_password': 'تعيين كلمة المرور', 'auth.confirm_password': 'تأكيد كلمة المرور', 'auth.new_password': 'كلمة المرور الجديدة', 'auth.current_password': 'كلمة المرور الحالية',
    'auth.password_min_length': 'يجب أن تكون كلمة المرور 8 أحرف على الأقل', 'auth.two_factor_title': 'المصادقة الثنائية', 'auth.two_factor_enter_code': 'يرجى إدخال رمز المصادقة الثنائية', 'auth.verify': 'تحقق',
    'auth.verifying': 'جاري التحقق...', 'auth.account_inactive': 'هذا الحساب غير مفعل.', 'auth.invalid_credentials': 'بيانات الاعتماد غير صحيحة.', 'auth.setup_link_invalid': 'رابط إعداد الحساب غير صالح أو منتهي الصلاحية',
    'auth.setup_success': 'تم إعداد حسابك بنجاح! يمكنك الآن تسجيل الدخول', 'auth.select_department': 'اختر القسم', 'auth.suggest_department': 'أو اكتب اسم القسم المقترح', 'auth.welcome_setup': 'مرحباً {{name}}! يرجى إكمال إعداد حسابك',
    'auth.welcome_setup_generic': 'مرحباً! يرجى إكمال إعداد حسابك', 'auth.complete_setup': 'إكمال الإعداد', 'auth.enter_strong_password': 'أدخل كلمة مرور قوية', 'auth.reenter_password': 'أعد إدخال كلمة المرور',
    'auth.password_not_match': 'كلمة المرور غير متطابقة', 'auth.password_match': 'كلمة المرور متطابقة', 'auth.password_requirements_not_met': 'كلمة المرور لا تستوفي جميع المتطلبات', 'auth.weak_password_warning': 'كلمة المرور ضعيفة. يرجى اختيار كلمة مرور أقوى',
    'auth.password_req_length': '8 أحرف على الأقل', 'auth.password_req_uppercase': 'حرف كبير (A-Z)', 'auth.password_req_lowercase': 'حرف صغير (a-z)', 'auth.password_req_number': 'رقم (0-9)',
    'auth.password_req_special': 'رمز خاص (@$!%*#?&)', 'auth.password_req_not_weak': 'ليست كلمة مرور ضعيفة', 'auth.department_unit': 'القسم / الوحدة', 'auth.select_department_placeholder': 'اختر القسم...',
    'auth.search_department': 'ابحث واختر القسم...', 'auth.other_department': 'أخرى (قسم غير مدرج)', 'auth.suggested_department_name': 'اسم القسم المقترح', 'auth.type_department_name': 'اكتب اسم قسمك / وحدتك',
    'auth.department_will_be_added': 'سيتم إضافة القسم من قبل مدير النظام لاحقاً', 'auth.select_from_list': 'اختيار من القائمة', 'auth.please_select_department': 'يرجى اختيار القسم', 'auth.please_type_department': 'يرجى كتابة اسم القسم',
    'auth.setup_failed': 'فشل في إعداد الحساب', 'auth.link_invalid': 'رابط غير صالح', 'auth.verifying_link': 'جاري التحقق من الرابط...', 'auth.go_to_login': 'الذهاب لتسجيل الدخول',
    'auth.setup_success_message': 'تم إعداد حسابك بنجاح!', 'auth.setup_success_description': 'يمكنك الآن تسجيل الدخول باستخدام بريدك الإلكتروني وكلمة المرور الجديدة', 'auth.no_search_results': 'لا توجد نتائج للبحث', 'auth.two_factor_welcome': 'مرحباً {{name}}',
    'auth.two_factor_enter_code_description': 'أدخل كود التحقق للمتابعة', 'auth.enter_verification_code': 'أدخل كود التحقق', 'auth.auth_app_instruction': 'افتح تطبيق المصادقة (Google Authenticator أو مشابه) وأدخل الكود المكون من 6 أرقام', 'auth.recovery_code': 'كود الاسترداد',
    'auth.recovery_code_instruction': 'أدخل أحد أكواد الاسترداد التي حصلت عليها عند تفعيل المصادقة الثنائية', 'auth.enter_recovery_code': 'يرجى إدخال كود الاسترداد', 'auth.enter_6_digit_code': 'يرجى إدخال الكود المكون من 6 أرقام', 'auth.invalid_verification_code': 'كود التحقق غير صحيح',
    'auth.use_verification_code': 'استخدام كود التحقق', 'auth.cant_access_auth_app': 'لا يمكنني الوصول لتطبيق المصادقة', 'auth.back_to_login': 'العودة لتسجيل الدخول', 'dashboard.welcome': 'مرحباً، {{name}}',
    'dashboard.summary': 'إليك ملخص نشاطات المشاريع والمهام', 'dashboard.total_projects': 'إجمالي المشاريع', 'dashboard.active_projects': 'مشاريع نشطة', 'dashboard.completed_projects': 'مشاريع مكتملة',
    'dashboard.total_tasks': 'إجمالي المهام', 'dashboard.overdue_tasks': 'مهام متأخرة', 'dashboard.users_count': 'المستخدمين', 'dashboard.avg_completion': 'متوسط الإنجاز',
    'dashboard.overdue_tasks_title': 'المهام المتأخرة', 'dashboard.no_overdue_tasks': 'لا توجد مهام متأخرة', 'dashboard.all_on_time': 'أحسنت! كل المهام في موعدها', 'dashboard.recent_projects': 'أحدث المشاريع',
    'dashboard.no_projects': 'لا توجد مشاريع', 'dashboard.overdue_projects': '{{count}} مشروع متأخر عن الجدول الزمني', 'dashboard.critical_overdue': 'منها {{count}} مشروع متأخر أكثر من 30 يوم', 'dashboard.view_overdue_projects': 'عرض المشاريع المتأخرة',
    'status.draft': 'مسودة', 'status.planning': 'تخطيط', 'status.in_progress': 'قيد التنفيذ', 'status.on_hold': 'معلق',
    'status.completed': 'مكتمل', 'status.cancelled': 'ملغى', 'status.todo': 'للتنفيذ', 'status.in_review': 'قيد المراجعة',
    'status.pending': 'معلق', 'status.delayed': 'متأخر', 'status.open': 'مفتوح', 'status.mitigated': 'مخفف',
    'status.closed': 'مغلق', 'priority.low': 'منخفضة', 'priority.medium': 'متوسطة', 'priority.high': 'عالية',
    'priority.urgent': 'عاجلة', 'priority.critical': 'حرجة', 'task_type.project': 'مهمة مشروع', 'task_type.personal': 'مهمة شخصية',
    'task_type.department': 'مهمة إدارية', 'task_type.recurring': 'مهمة متكررة', 'dept_level.1': 'الإدارة العليا', 'dept_level.2': 'إدارة تنفيذية',
    'dept_level.3': 'إدارة', 'dept_level.4': 'قسم', 'dept_level.5': 'وحدة', 'dept_level.6': 'شعبة',
    'role.super_admin': 'مدير النظام', 'role.admin': 'مدير إدارة', 'role.project_manager': 'مدير مشروع', 'role.team_member': 'عضو فريق',
    'role.viewer': 'مشاهد', 'projects.title': 'المشاريع', 'projects.create': 'إنشاء مشروع', 'projects.create_new': 'إنشاء مشروع جديد',
    'projects.edit': 'تعديل المشروع', 'projects.view': 'عرض المشروع', 'projects.delete': 'حذف المشروع', 'projects.delete_confirm': 'هل أنت متأكد من حذف هذا المشروع؟',
    'projects.project_name': 'اسم المشروع', 'projects.project_code': 'رمز المشروع', 'projects.project_manager': 'مدير المشروع', 'projects.project_department': 'الإدارة',
    'projects.project_status': 'حالة المشروع', 'projects.project_priority': 'أولوية المشروع', 'projects.project_progress': 'تقدم المشروع', 'projects.project_budget': 'الميزانية',
    'projects.actual_cost': 'التكلفة الفعلية', 'projects.objectives': 'الأهداف', 'projects.scope': 'النطاق', 'projects.in_scope': 'ضمن النطاق',
    'projects.out_of_scope': 'خارج النطاق', 'projects.milestones': 'المراحل', 'projects.tasks': 'المهام', 'projects.team': 'فريق العمل',
    'projects.stakeholders': 'أصحاب المصلحة', 'projects.risks': 'المخاطر', 'projects.kpis': 'مؤشرات الأداء', 'projects.expenses': 'المصروفات',
    'projects.statistics': 'إحصائيات المشاريع', 'projects.no_projects': 'لا توجد مشاريع', 'projects.start_first': 'ابدأ بإنشاء مشروعك الأول', 'projects.initiative': 'المبادرة',
    'projects.portfolio': 'الالتزام التنفيذي', 'projects.members': 'الأعضاء', 'projects.add_member': 'إضافة عضو', 'projects.impact_low': 'منخفض',
    'projects.impact_medium': 'متوسط', 'projects.impact_high': 'عالي', 'projects.impact_critical': 'حرج', 'projects.stakeholder_end_user': 'مستخدم نهائي',
    'projects.stakeholder_implementer': 'جهة منفذة', 'projects.stakeholder_consultant': 'مستشار', 'projects.stakeholder_governance': 'جهة رقابية', 'projects.stakeholder_operations': 'داعم تشغيلي',
    'projects.stakeholder_influencer': 'صاحب تأثير', 'projects.stakeholder_other': 'أخرى', 'projects.role_developer': 'مطور', 'projects.role_analyst': 'محلل',
    'projects.role_designer': 'مصمم', 'projects.role_tester': 'مختبر', 'projects.role_team_lead': 'قائد فريق', 'projects.role_member': 'عضو',
    'projects.manage_and_track': 'إدارة ومتابعة جميع المشاريع', 'projects.new_project': 'مشروع جديد', 'projects.delete_success': 'تم حذف المشروع "{{name}}" بنجاح', 'projects.delete_failed': 'فشل في حذف المشروع',
    'projects.delete_confirm_title': 'تأكيد حذف المشروع', 'projects.delete_warning': 'سيتم حذف جميع البيانات المرتبطة بالمشروع بما في ذلك المهام والمراحل والتقارير. هذا الإجراء لا يمكن التراجع عنه.', 'projects.not_found': 'المشروع غير موجود', 'projects.back_to_projects': 'العودة للمشاريع',
    'projects.no_department': 'بدون قسم', 'projects.leader': 'قائد', 'projects.not_assigned': 'غير محدد', 'projects.supervisor': 'مشرف',
    'projects.overview': 'نظرة عامة', 'projects.report_card': 'بطاقة المشروع', 'projects.activity_log': 'السجل', 'projects.update_data': 'قم بتحديث بيانات المشروع',
    'projects.enter_new_data': 'أدخل بيانات المشروع الجديد', 'projects.step_basic_info': 'المعلومات الأساسية', 'projects.step_objectives_scope': 'الأهداف والنطاق', 'projects.step_team': 'الفريق',
    'projects.step_milestones': 'المراحل', 'projects.step_tasks': 'المهام', 'projects.step_risks_resources': 'المخاطر والموارد', 'projects.step_of': 'الخطوة {{current}} من {{total}}',
    'projects.independent': 'مستقل', 'projects.independent_projects': 'مشاريع مستقلة', 'projects.filter_results': 'تصفية النتائج', 'projects.search_placeholder': 'بحث بالاسم أو الرمز...',
    'projects.all_statuses': 'كل الحالات', 'projects.all_priorities': 'كل الأولويات', 'projects.all_initiatives': 'كل المبادرات', 'projects.stats_total_projects': 'إجمالي المشاريع',
    'projects.stats_overview': 'نظرة شاملة على أداء المشاريع والمهام', 'projects.stats_completion_rate': 'معدل إنجاز المشاريع', 'projects.stats_task_completion_rate': 'معدل إنجاز المهام', 'projects.stats_by_status': 'توزيع المشاريع حسب الحالة',
    'projects.stats_avg_completion_time': 'متوسط وقت الإنجاز', 'projects.stats_days_count': '{{count}} يوم', 'projects.stats_not_available': 'غير متوفر', 'projects.stats_overdue_projects': 'مشاريع متأخرة',
    'projects.stats_critical_count': 'منها {{count}} حرجة', 'projects.stats_total_budget': 'إجمالي الميزانية', 'projects.stats_currency': 'ريال', 'projects.stats_budget_variance': 'فرق الميزانية',
    'projects.stats_over_budget': '{{count}} مشاريع تجاوزت الميزانية', 'projects.stats_monthly_trends': 'الاتجاهات الشهرية (آخر 6 أشهر)', 'projects.stats_started': 'بدأت', 'projects.stats_finished': 'انتهت',
    'projects.stats_task': 'مهمة', 'projects.stats_dept_performance': 'أداء الأقسام', 'projects.stats_recent_projects': 'أحدث المشاريع', 'projects.stats_all_periods': 'كل الفترات',
    'projects.stats_this_month': 'هذا الشهر', 'projects.stats_quarter': 'ربع سنة', 'projects.stats_this_year': 'هذه السنة', 'projects.stats_custom': 'مخصص',
    'tasks.title': 'المهام', 'tasks.my_tasks': 'مهامي', 'tasks.create': 'إنشاء مهمة', 'tasks.create_new': 'إنشاء مهمة جديدة',
    'tasks.edit': 'تعديل المهمة', 'tasks.view': 'عرض المهمة', 'tasks.delete': 'حذف المهمة', 'tasks.task_title': 'عنوان المهمة',
    'tasks.task_type': 'نوع المهمة', 'tasks.task_status': 'حالة المهمة', 'tasks.task_priority': 'أولوية المهمة', 'tasks.task_progress': 'تقدم المهمة',
    'tasks.assignee': 'المكلف', 'tasks.project': 'المشروع', 'tasks.milestone': 'المرحلة', 'tasks.subtasks': 'المهام الفرعية',
    'tasks.add_subtask': 'إضافة مهمة فرعية', 'tasks.estimated_hours': 'الساعات المقدرة', 'tasks.actual_hours': 'الساعات الفعلية', 'tasks.completed_date': 'تاريخ الإنجاز',
    'tasks.no_tasks': 'لا توجد مهام', 'tasks.start_first': 'ابدأ بإنشاء مهمتك الأولى', 'tasks.table_view': 'عرض جدول', 'tasks.kanban_view': 'عرض كانبان',
    'tasks.complete': 'إكمال المهمة', 'tasks.send_review': 'إرسال للمراجعة', 'tasks.approve': 'موافقة', 'tasks.reject': 'رفض',
    'tasks.reopen': 'إعادة فتح', 'tasks.comments': 'التعليقات', 'tasks.attachments': 'المرفقات', 'tasks.activity': 'النشاط',
    'tasks.owner': 'المالك', 'tasks.private': 'خاصة', 'users.title': 'المستخدمون', 'users.create': 'إنشاء مستخدم',
    'users.create_new': 'إنشاء مستخدم جديد', 'users.edit': 'تعديل المستخدم', 'users.view': 'عرض المستخدم', 'users.delete': 'حذف المستخدم',
    'users.user_name': 'اسم المستخدم', 'users.user_email': 'البريد الإلكتروني', 'users.user_role': 'الدور', 'users.user_department': 'القسم',
    'users.user_status': 'الحالة', 'users.job_title': 'المسمى الوظيفي', 'users.extension': 'التحويلة', 'users.active': 'نشط',
    'users.inactive': 'غير نشط', 'users.no_users': 'لا يوجد مستخدمون', 'users.setup_link': 'رابط إعداد الحساب', 'users.quick_create': 'إنشاء سريع',
    'hr.title': 'الأقسام', 'hr.departments': 'الأقسام', 'hr.employees': 'الموظفون', 'hr.create_department': 'إنشاء قسم',
    'hr.department_name': 'اسم القسم', 'hr.department_level': 'مستوى القسم', 'hr.parent_department': 'القسم الأعلى', 'hr.department_manager': 'مدير القسم',
    'hr.department_email': 'بريد القسم', 'hr.employees_count': 'عدد الموظفين', 'hr.no_departments': 'لا توجد أقسام', 'hr.status_active': 'نشط',
    'hr.status_on_leave': 'في إجازة', 'hr.status_terminated': 'منتهي', 'strategy.title': 'التخطيط التنفيذي', 'strategy.dashboard': 'لوحة التحكم',
    'strategy.portfolios': 'الالتزامات التنفيذية', 'strategy.portfolio': 'التزام تنفيذي', 'strategy.programs': 'المبادرات', 'strategy.program': 'مبادرة',
    'strategy.create_portfolio': 'إنشاء التزام تنفيذي', 'strategy.create_program': 'إنشاء مبادرة', 'strategy.edit_portfolio': 'تعديل الالتزام التنفيذي', 'strategy.edit_program': 'تعديل المبادرة',
    'strategy.view_portfolio': 'عرض الالتزام التنفيذي', 'strategy.view_program': 'عرض المبادرة', 'strategy.portfolio_name': 'اسم الالتزام', 'strategy.program_name': 'اسم المبادرة',
    'strategy.linked_projects': 'المشاريع المرتبطة', 'strategy.no_portfolios': 'لا توجد التزامات تنفيذية', 'strategy.no_programs': 'لا توجد مبادرات', 'strategy.owner': 'المسؤول',
    'strategy.year': 'السنة', 'strategy.target': 'المستهدف', 'strategy.actual': 'الفعلي', 'strategy.blockers': 'المعوقات',
    'strategy.decisions': 'القرارات', 'strategy.reviews': 'المراجعات', 'surveys.title': 'الاستبيانات', 'surveys.create': 'إنشاء استبيان',
    'surveys.create_new': 'إنشاء استبيان جديد', 'surveys.edit': 'تعديل الاستبيان', 'surveys.view': 'عرض الاستبيان', 'surveys.builder': 'بناء الاستبيان',
    'surveys.responses': 'الردود', 'surveys.survey_title': 'عنوان الاستبيان', 'surveys.questions': 'الأسئلة', 'surveys.no_surveys': 'لا توجد استبيانات',
    'surveys.published': 'منشور', 'surveys.draft': 'مسودة', 'surveys.closed': 'مغلق', 'surveys.response_count': 'عدد الردود',
    'surveys.public_link': 'رابط عام', 'surveys.copy_link': 'نسخ الرابط', 'surveys.archived': 'مؤرشف', 'surveys.type_initial': 'أولي',
    'surveys.type_periodic': 'دوري', 'ovr.title': 'تقارير الحوادث', 'ovr.incidents': 'الحوادث', 'ovr.create_incident': 'إبلاغ عن حادثة',
    'ovr.incident_title': 'عنوان الحادثة', 'ovr.incident_type': 'نوع الحادثة', 'ovr.severity': 'الخطورة', 'ovr.reported_by': 'أبلغ بواسطة',
    'ovr.no_incidents': 'لا توجد حوادث مسجلة', 'ovr.severity_low': 'منخفض', 'ovr.severity_medium': 'متوسط', 'ovr.severity_high': 'عالي',
    'ovr.severity_critical': 'حرج', 'ovr.status_reported': 'تم الإبلاغ', 'ovr.status_under_investigation': 'قيد التحقيق', 'ovr.status_resolved': 'تم الحل',
    'profile.title': 'الملف الشخصي', 'profile.personal_info': 'المعلومات الشخصية', 'profile.manage_info': 'إدارة معلوماتك الشخصية وكلمة المرور', 'profile.change_password': 'تغيير كلمة المرور',
    'profile.enter_name': 'أدخل اسمك', 'profile.enter_current_password': 'أدخل كلمة المرور الحالية', 'profile.enter_new_password': 'أدخل كلمة المرور الجديدة', 'profile.confirm_new_password': 'أعد إدخال كلمة المرور',
    'profile.profile_updated': 'تم تحديث الملف الشخصي بنجاح', 'profile.password_changed': 'تم تغيير كلمة المرور بنجاح', 'profile.update_error': 'حدث خطأ أثناء تحديث الملف الشخصي', 'profile.password_error': 'حدث خطأ أثناء تغيير كلمة المرور',
    'profile.two_factor': 'المصادقة الثنائية', 'profile.job_title_placeholder': 'مثال: مهندس برمجيات', 'invitations.title': 'الدعوات', 'invitations.settings': 'إعدادات الدعوات',
    'invitations.accept': 'قبول الدعوة', 'invitations.reject': 'رفض الدعوة', 'invitations.pending': 'دعوات معلقة', 'invitations.expired': 'منتهية الصلاحية',
    'invitations.invite_user': 'دعوة مستخدم', 'invitations.invite_email': 'البريد الإلكتروني للمدعو', 'common.from': 'من', 'common.items': 'عنصر',
    'common.type': 'النوع', 'common.role': 'الدور', 'common.percentage': 'النسبة', 'common.amount': 'المبلغ',
    'common.count': 'العدد', 'common.title': 'العنوان', 'common.value': 'القيمة', 'common.target': 'المستهدف',
    'common.actual': 'الفعلي', 'common.not_available': 'غير متوفر', 'common.not_specified': 'غير محدد', 'common.unknown': 'غير معروف',
    'common.apply': 'تطبيق', 'common.clear': 'مسح', 'common.clear_filters': 'مسح التصفيات', 'common.sort_by': 'ترتيب حسب',
    'common.ascending': 'تصاعدي', 'common.descending': 'تنازلي', 'common.page': 'صفحة', 'common.rows_per_page': 'عدد الصفوف',
    'common.no_description': 'لا يوجد وصف', 'common.write_comment': 'اكتب تعليقاً...', 'common.send': 'إرسال', 'common.reply': 'رد',
    'common.copy': 'نسخ', 'common.copied': 'تم النسخ', 'common.share': 'مشاركة', 'common.print': 'طباعة',
    'common.overview': 'نظرة عامة', 'common.general': 'عام', 'common.advanced': 'متقدم', 'common.basic': 'أساسي',
    'common.step': 'خطوة', 'common.step_n': 'الخطوة {{n}}', 'common.finish': 'إنهاء', 'common.continue': 'متابعة',
    'common.skip': 'تخطي', 'common.retry': 'إعادة المحاولة', 'common.update': 'تحديث', 'common.updated': 'تم التحديث',
    'common.created': 'تم الإنشاء', 'common.deleted': 'تم الحذف', 'common.saved': 'تم الحفظ', 'common.submitted': 'تم الإرسال',
    'common.approved': 'تم الموافقة', 'common.rejected': 'تم الرفض', 'common.completed_female': 'مكتملة', 'common.attach_file': 'إرفاق ملف',
    'common.drag_drop': 'اسحب وأفلت الملفات هنا', 'common.max_file_size': 'الحد الأقصى لحجم الملف: {{size}} ميجابايت', 'common.supported_formats': 'الصيغ المدعومة: {{formats}}', 'common.no_attachments': 'لا توجد مرفقات',
    'common.no_comments': 'لا توجد تعليقات', 'common.sar': 'ريال', 'common.budget_variance': 'انحراف الميزانية', 'common.on_budget': 'ضمن الميزانية',
    'common.over_budget': 'تجاوز الميزانية', 'common.under_budget': 'أقل من الميزانية', 'common.completion_rate': 'نسبة الإنجاز', 'status.completed_female': 'مكتملة',
    'status.active': 'نشط', 'status.archived': 'مؤرشف', 'status.published': 'منشور', 'status.not_started': 'لم يبدأ',
    'status.overdue': 'متأخر', 'projects.basic_info': 'المعلومات الأساسية', 'projects.timeline': 'الجدول الزمني', 'projects.budget_management': 'إدارة الميزانية',
    'projects.total_budget': 'إجمالي الميزانية', 'projects.spent': 'المنصرف', 'projects.remaining': 'المتبقي', 'projects.variance': 'الانحراف',
    'projects.add_expense': 'إضافة مصروف', 'projects.expense_description': 'وصف المصروف', 'projects.expense_amount': 'المبلغ', 'projects.expense_date': 'تاريخ المصروف',
    'projects.expense_category': 'فئة المصروف', 'projects.add_milestone': 'إضافة مرحلة', 'projects.milestone_name': 'اسم المرحلة', 'projects.milestone_date': 'تاريخ المرحلة',
    'projects.milestone_status': 'حالة المرحلة', 'projects.add_objective': 'إضافة هدف', 'projects.objective_title': 'عنوان الهدف', 'projects.add_risk': 'إضافة خطر',
    'projects.risk_title': 'عنوان الخطر', 'projects.risk_description': 'وصف الخطر', 'projects.risk_impact': 'تأثير الخطر', 'projects.risk_probability': 'احتمالية الخطر',
    'projects.risk_mitigation': 'خطة التخفيف', 'projects.risk_status': 'حالة الخطر', 'projects.risk_owner': 'مسؤول الخطر', 'projects.add_stakeholder': 'إضافة صاحب مصلحة',
    'projects.stakeholder_name': 'اسم صاحب المصلحة', 'projects.stakeholder_type': 'نوع صاحب المصلحة', 'projects.stakeholder_role': 'دور صاحب المصلحة', 'projects.stakeholder_influence': 'مستوى التأثير',
    'projects.stakeholder_interest': 'مستوى الاهتمام', 'projects.stakeholder_contact': 'معلومات الاتصال', 'projects.add_kpi': 'إضافة مؤشر أداء', 'projects.kpi_name': 'اسم المؤشر',
    'projects.kpi_target': 'المستهدف', 'projects.kpi_actual': 'الفعلي', 'projects.kpi_unit': 'الوحدة', 'projects.no_milestones': 'لا توجد مراحل',
    'projects.no_objectives': 'لا توجد أهداف', 'projects.no_risks': 'لا توجد مخاطر', 'projects.no_stakeholders': 'لا يوجد أصحاب مصلحة', 'projects.no_team_members': 'لا يوجد أعضاء في الفريق',
    'projects.no_kpis': 'لا توجد مؤشرات أداء', 'projects.no_expenses': 'لا توجد مصروفات', 'projects.no_tasks': 'لا توجد مهام', 'projects.filter_status': 'تصفية حسب الحالة',
    'projects.filter_priority': 'تصفية حسب الأولوية', 'projects.filter_department': 'تصفية حسب القسم', 'projects.filter_manager': 'تصفية حسب المدير', 'projects.search_projects': 'البحث في المشاريع...',
    'projects.total_projects': 'إجمالي المشاريع', 'projects.active_projects': 'المشاريع النشطة', 'projects.completed_projects': 'المشاريع المكتملة', 'projects.on_hold_projects': 'المشاريع المعلقة',
    'projects.overdue_projects': 'المشاريع المتأخرة', 'projects.change_status': 'تغيير الحالة', 'projects.status_change_confirm': 'هل أنت متأكد من تغيير حالة المشروع؟', 'projects.status_changed': 'تم تغيير حالة المشروع',
    'projects.status_change_error': 'خطأ في تغيير حالة المشروع', 'projects.error_loading': 'خطأ في تحميل المشروع', 'projects.error_saving': 'خطأ في حفظ المشروع', 'projects.error_deleting': 'خطأ في حذف المشروع',
    'projects.created_success': 'تم إنشاء المشروع بنجاح', 'projects.updated_success': 'تم تحديث المشروع بنجاح', 'projects.deleted_success': 'تم حذف المشروع بنجاح', 'projects.select_project': 'اختر المشروع',
    'projects.select_manager': 'اختر مدير المشروع', 'projects.select_department': 'اختر القسم', 'projects.select_initiative': 'اختر المبادرة', 'projects.select_portfolio': 'اختر الالتزام التنفيذي',
    'projects.general_info': 'معلومات عامة', 'projects.incomplete_subtasks': 'مهام فرعية غير مكتملة', 'projects.incomplete_subtasks_message': 'يوجد {{count}} مهمة فرعية غير مكتملة', 'projects.error_title': 'خطأ',
    'projects.transition_to': 'نقل إلى {{status}}', 'projects.transition_confirm': 'هل أنت متأكد من نقل المشروع إلى حالة "{{status}}"؟', 'projects.notes_optional': 'ملاحظات (اختياري)', 'projects.weight': 'الوزن',
    'projects.probability': 'الاحتمالية', 'projects.impact': 'التأثير', 'projects.mitigation': 'خطة التخفيف', 'projects.influence_level': 'مستوى التأثير',
    'projects.interest_level': 'مستوى الاهتمام', 'projects.contact_info': 'معلومات الاتصال', 'projects.organization': 'المنظمة/الجهة', 'projects.select_member': 'اختر العضو',
    'projects.member_role': 'دور العضو', 'projects.allocation_percentage': 'نسبة التخصيص', 'projects.search_members': 'البحث عن أعضاء...', 'projects.view_details': 'عرض التفاصيل',
    'tasks.cards_view': 'عرض بطاقات', 'tasks.overdue': 'متأخرة', 'tasks.on_time': 'في الموعد', 'tasks.remaining_days': '{{count}} يوم متبقي',
    'tasks.overdue_days': 'متأخرة {{count}} يوم', 'tasks.due_today': 'مستحقة اليوم', 'tasks.no_due_date': 'بدون تاريخ استحقاق', 'tasks.parent_task': 'المهمة الأصلية',
    'tasks.search_tasks': 'البحث في المهام...', 'tasks.filter_status': 'تصفية حسب الحالة', 'tasks.filter_priority': 'تصفية حسب الأولوية', 'tasks.filter_assignee': 'تصفية حسب المكلف',
    'tasks.filter_project': 'تصفية حسب المشروع', 'tasks.filter_type': 'تصفية حسب النوع', 'tasks.no_subtasks': 'لا توجد مهام فرعية', 'tasks.add_comment': 'إضافة تعليق',
    'tasks.write_comment': 'اكتب تعليقاً...', 'tasks.no_comments': 'لا توجد تعليقات', 'tasks.add_attachment': 'إضافة مرفق', 'tasks.no_attachments': 'لا توجد مرفقات',
    'tasks.select_project': 'اختر المشروع', 'tasks.select_milestone': 'اختر المرحلة', 'tasks.select_assignee': 'اختر المكلف', 'tasks.task_description': 'وصف المهمة',
    'tasks.created_success': 'تم إنشاء المهمة بنجاح', 'tasks.updated_success': 'تم تحديث المهمة بنجاح', 'tasks.deleted_success': 'تم حذف المهمة بنجاح', 'tasks.status_changed': 'تم تغيير حالة المهمة',
    'tasks.error_loading': 'خطأ في تحميل المهمة', 'tasks.error_saving': 'خطأ في حفظ المهمة', 'tasks.details_tab': 'التفاصيل', 'tasks.subtasks_tab': 'المهام الفرعية',
    'tasks.comments_tab': 'التعليقات', 'tasks.attachments_tab': 'المرفقات', 'tasks.activity_tab': 'النشاط', 'tasks.general_info': 'معلومات عامة',
    'tasks.schedule': 'الجدولة', 'tasks.task_info': 'معلومات المهمة', 'tasks.select_type': 'اختر النوع', 'tasks.select_status': 'اختر الحالة',
    'tasks.select_priority': 'اختر الأولوية', 'tasks.date_range': 'الفترة الزمنية', 'tasks.progress_percentage': 'نسبة الإنجاز', 'tasks.create_milestone': 'إنشاء مرحلة',
    'tasks.delete_success': 'تم حذف المهمة بنجاح', 'tasks.delete_error': 'فشل في حذف المهمة', 'tasks.delete_warning': 'سيتم حذف المهمة وجميع المهام الفرعية المرتبطة بها. هذا الإجراء لا يمكن التراجع عنه.', 'tasks.my_tasks_description': 'إدارة ومتابعة مهامك الشخصية',
    'tasks.new_task': 'مهمة جديدة', 'tasks.total_tasks': 'إجمالي المهام', 'tasks.overdue_only': 'المتأخرة فقط', 'tasks.apply': 'تطبيق',
    'tasks.view_mode': 'طريقة العرض', 'tasks.search_placeholder': 'بحث في المهام...', 'tasks.no_tasks_assigned': 'لا توجد مهام مسندة إليك حالياً', 'tasks.page_of': 'صفحة {{current}} من {{total}}',
    'tasks.all_statuses': 'كل الحالات', 'tasks.all_priorities': 'كل الأولويات', 'tasks.all_types': 'كل الأنواع', 'tasks.load_error': 'حدث خطأ في تحميل المهمة',
    'tasks.not_found': 'المهمة غير موجودة', 'tasks.back_to_list': 'العودة للمهام', 'tasks.activities': 'النشاطات', 'tasks.description': 'الوصف',
    'tasks.no_description': 'لا يوجد وصف', 'tasks.adding': 'جاري الإضافة...', 'tasks.subtask_title_placeholder': 'عنوان المهمة الفرعية...', 'tasks.add_subtasks_hint': 'أضف مهام فرعية لتقسيم العمل',
    'tasks.activity_log': 'سجل النشاط', 'tasks.open_full_page': 'فتح الصفحة الكاملة', 'tasks.log': 'السجل', 'tasks.task': 'المهمة',
    'tasks.edit_description': 'تعديل بيانات المهمة', 'tasks.create_description': 'أدخل بيانات المهمة الجديدة', 'tasks.assignment': 'التكليف', 'tasks.execution_assignee': 'المكلف بالتنفيذ',
    'tasks.private_task': 'مهمة خاصة', 'tasks.private_hint': 'المهمة الخاصة لا تظهر إلا للمكلف بها', 'tasks.task_details': 'تفاصيل المهمة', 'tasks.enter_title': 'أدخل عنوان المهمة',
    'tasks.enter_description': 'أدخل وصف المهمة', 'tasks.type_desc_project': 'مهمة مرتبطة بمشروع محدد', 'tasks.type_desc_personal': 'مهمة شخصية غير مرتبطة بمشروع', 'tasks.type_desc_department': 'مهمة إدارية للقسم',
    'tasks.type_desc_recurring': 'مهمة تتكرر بشكل دوري', 'tasks.select_recurrence': 'اختر نمط التكرار', 'tasks.recurrence_daily': 'يومي', 'tasks.recurrence_weekly': 'أسبوعي',
    'tasks.recurrence_biweekly': 'كل أسبوعين', 'tasks.recurrence_monthly': 'شهري', 'tasks.recurrence_quarterly': 'ربع سنوي', 'tasks.recurrence_yearly': 'سنوي',
    'tasks.recurrence_pattern': 'نمط التكرار', 'tasks.select_department': 'اختر القسم', 'tasks.status_schedule': 'الحالة والجدولة', 'tasks.date_range_allowed': 'الفترة المسموحة',
    'tasks.from': 'من', 'tasks.select_start_date': 'اختر تاريخ البداية', 'tasks.delivery_date': 'تاريخ التسليم', 'tasks.select_by_days': 'اختيار بالأيام',
    'tasks.select_from_calendar': 'اختيار من التقويم', 'tasks.days_1': 'يوم واحد', 'tasks.days_2': 'يومان', 'tasks.days_3': '3 أيام',
    'tasks.days_5': '5 أيام', 'tasks.days_7': 'أسبوع', 'tasks.days_10': '10 أيام', 'tasks.days_14': 'أسبوعان',
    'tasks.days_21': '3 أسابيع', 'tasks.days_30': 'شهر', 'tasks.delivery': 'التسليم', 'tasks.select_due_date': 'اختر تاريخ الاستحقاق',
    'tasks.project_milestone': 'المشروع والمرحلة', 'tasks.none': 'لا يوجد', 'tasks.add_new_milestone': 'إضافة مرحلة جديدة', 'tasks.milestone_name': 'اسم المرحلة',
    'tasks.milestone_name_placeholder': 'مثال: المرحلة الأولى', 'tasks.milestone_description': 'وصف المرحلة', 'tasks.milestone_desc_placeholder': 'وصف موجز للمرحلة...', 'tasks.milestone_duration': 'مدة المرحلة',
    'tasks.enter_number': 'أدخل الرقم', 'tasks.unit_day': 'يوم', 'tasks.unit_week': 'أسبوع', 'tasks.unit_month': 'شهر',
    'tasks.milestone_date_auto': 'سيتم حساب تاريخ النهاية تلقائياً بناءً على المدة', 'tasks.add_new_user': 'إضافة مستخدم جديد', 'tasks.select_assignee_title': 'اختيار المكلف', 'tasks.search_user_placeholder': 'بحث بالاسم أو البريد...',
    'tasks.no_matching_users': 'لا يوجد مستخدمون مطابقون', 'tasks.password_label': 'كلمة المرور', 'tasks.password_auto_generated': 'سيتم إنشاء كلمة مرور مؤقتة تلقائياً', 'tasks.password_reset_on_login': 'سيُطلب من المستخدم تغيير كلمة المرور عند أول تسجيل دخول',
    'tasks.role_label': 'الصلاحية', 'tasks.role_team_member_hint': 'سيتم تعيين المستخدم كعضو فريق', 'tasks.reminder': 'تذكير', 'tasks.send_credentials_reminder': 'تأكد من إرسال بيانات الدخول للمستخدم الجديد',
    'tasks.add_user': 'إضافة المستخدم', 'tasks.remove_assignee': 'إزالة المكلف', 'tasks.unassigned': 'غير مكلف', 'tasks.creator': 'المنشئ',
    'tasks.hours': 'الساعات', 'tasks.hour': 'ساعة', 'tasks.manage_description': 'إدارة ومتابعة جميع المهام', 'tasks.filter_results': 'تصفية النتائج',
    'tasks.search_by_title': 'بحث بالعنوان...', 'tasks.drop_here': 'أفلت هنا', 'tasks.show_subtasks': 'عرض المهام الفرعية', 'tasks.time_remaining': 'الوقت المتبقي',
    'tasks.today': 'اليوم', 'tasks.tomorrow': 'غداً', 'tasks.days_remaining': '{{count}} يوم متبقي', 'tasks.change_status': 'تغيير الحالة',
    'tasks.confirm_delete_subtask': 'هل أنت متأكد من حذف هذه المهمة الفرعية؟', 'tasks.confirm_delete_comment': 'هل أنت متأكد من حذف هذا التعليق؟', 'tasks.confirm_delete_attachment': 'هل أنت متأكد من حذف هذا المرفق؟', 'tasks.time_now': 'الآن',
    'tasks.time_minutes_ago': 'منذ {{count}} دقيقة', 'tasks.time_hours_ago': 'منذ {{count}} ساعة', 'tasks.time_days_ago': 'منذ {{count}} يوم', 'tasks.comment_placeholder': 'اكتب تعليقاً... استخدم @ للإشارة لشخص',
    'tasks.no_comments_yet': 'لا توجد تعليقات بعد', 'tasks.be_first_to_comment': 'كن أول من يعلق على هذه المهمة', 'tasks.attachments_from_comments': 'المرفقات المضافة في التعليقات ستظهر هنا', 'tasks.download_file': 'تحميل الملف',
    'tasks.mentioned_users': 'تمت الإشارة إلى', 'users.role': 'الدور', 'users.last_login': 'آخر دخول', 'users.member_since': 'عضو منذ',
    'users.two_factor_enabled': 'المصادقة الثنائية مفعلة', 'users.two_factor_disabled': 'المصادقة الثنائية معطلة', 'users.assigned_projects': 'المشاريع المسندة', 'users.assigned_tasks': 'المهام المسندة',
    'users.search_users': 'البحث في المستخدمين...', 'users.filter_role': 'تصفية حسب الدور', 'users.filter_status': 'تصفية حسب الحالة', 'users.filter_department': 'تصفية حسب القسم',
    'users.send_invitation': 'إرسال دعوة', 'users.resend_invitation': 'إعادة إرسال الدعوة', 'users.invitation_sent': 'تم إرسال الدعوة', 'users.deactivate': 'تعطيل',
    'users.activate': 'تفعيل', 'hr.view_department': 'عرض القسم', 'hr.edit_department': 'تعديل القسم', 'hr.delete_department': 'حذف القسم',
    'hr.sub_departments': 'الأقسام الفرعية', 'hr.no_sub_departments': 'لا توجد أقسام فرعية', 'hr.search_departments': 'البحث في الأقسام...', 'hr.search_employees': 'البحث في الموظفين...',
    'hr.department_details': 'تفاصيل القسم', 'hr.no_employees': 'لا يوجد موظفون', 'strategy.description': 'الوصف', 'strategy.progress': 'التقدم',
    'strategy.start_date': 'تاريخ البداية', 'strategy.end_date': 'تاريخ النهاية', 'strategy.budget': 'الميزانية', 'strategy.search_portfolios': 'البحث في الالتزامات...',
    'strategy.search_programs': 'البحث في المبادرات...', 'strategy.no_linked_projects': 'لا توجد مشاريع مرتبطة', 'strategy.add_project': 'إضافة مشروع', 'strategy.remove_project': 'إزالة مشروع',
    'strategy.projects_count': 'عدد المشاريع', 'strategy.completion_rate': 'نسبة الإنجاز', 'strategy.filter_status': 'تصفية حسب الحالة', 'strategy.filter_year': 'تصفية حسب السنة',
    'strategy.filter_owner': 'تصفية حسب المسؤول', 'strategy.add_blocker': 'إضافة معوق', 'strategy.add_decision': 'إضافة قرار', 'strategy.add_review': 'إضافة مراجعة',
    'strategy.no_blockers': 'لا توجد معوقات', 'strategy.no_decisions': 'لا توجد قرارات', 'strategy.no_reviews': 'لا توجد مراجعات', 'strategy.delete_portfolio': 'حذف الالتزام',
    'strategy.delete_program': 'حذف المبادرة', 'surveys.search_surveys': 'البحث في الاستبيانات...', 'surveys.filter_status': 'تصفية حسب الحالة', 'surveys.filter_type': 'تصفية حسب النوع',
    'surveys.total_responses': 'إجمالي الردود', 'surveys.publish': 'نشر', 'surveys.unpublish': 'إلغاء النشر', 'surveys.close_survey': 'إغلاق الاستبيان',
    'surveys.reopen': 'إعادة فتح', 'surveys.delete_survey': 'حذف الاستبيان', 'surveys.add_question': 'إضافة سؤال', 'surveys.question_text': 'نص السؤال',
    'surveys.question_type': 'نوع السؤال', 'surveys.required_question': 'سؤال مطلوب', 'surveys.survey_description': 'وصف الاستبيان', 'surveys.survey_type': 'نوع الاستبيان',
    'surveys.target_audience': 'الفئة المستهدفة', 'surveys.start_date': 'تاريخ البداية', 'surveys.end_date': 'تاريخ النهاية', 'surveys.view_responses': 'عرض الردود',
    'surveys.export_responses': 'تصدير الردود', 'surveys.link_copied': 'تم نسخ الرابط', 'surveys.created_success': 'تم إنشاء الاستبيان بنجاح', 'surveys.updated_success': 'تم تحديث الاستبيان بنجاح',
    'surveys.deleted_success': 'تم حذف الاستبيان بنجاح', 'ovr.description': 'الوصف', 'ovr.date': 'التاريخ', 'ovr.location': 'الموقع',
    'ovr.resolution': 'الحل', 'ovr.search_incidents': 'البحث في الحوادث...', 'ovr.filter_severity': 'تصفية حسب الخطورة', 'ovr.filter_status': 'تصفية حسب الحالة',
    'ovr.edit_incident': 'تعديل الحادثة', 'ovr.delete_incident': 'حذف الحادثة', 'ovr.view_incident': 'عرض الحادثة', 'ovr.created_success': 'تم إنشاء الحادثة بنجاح',
    'ovr.updated_success': 'تم تحديث الحادثة بنجاح', 'profile.sessions': 'الجلسات النشطة', 'profile.security': 'الأمان', 'profile.preferences': 'التفضيلات',
    'profile.language': 'اللغة', 'profile.notifications': 'الإشعارات', 'profile.email_notifications': 'إشعارات البريد', 'profile.update_profile': 'تحديث الملف الشخصي',
    'invitations.send': 'إرسال دعوة', 'invitations.accepted': 'مقبولة', 'invitations.status': 'حالة الدعوة', 'invitations.sent_at': 'تاريخ الإرسال',
    'invitations.expires_at': 'تاريخ الانتهاء', 'invitations.resend': 'إعادة الإرسال', 'invitations.revoke': 'إلغاء الدعوة', 'invitations.no_invitations': 'لا توجد دعوات',
    'invitations.search_invitations': 'البحث في الدعوات...', 'invitations.invitation_sent': 'تم إرسال الدعوة بنجاح', 'invitations.invitation_revoked': 'تم إلغاء الدعوة', 'dashboard.projects_by_status': 'المشاريع حسب الحالة',
    'dashboard.monthly_trends': 'الاتجاهات الشهرية', 'dashboard.budget_summary': 'ملخص الميزانية', 'dashboard.departments_performance': 'أداء الأقسام', 'dashboard.upcoming_tasks': 'المهام القادمة',
    'dashboard.projects_started': 'مشاريع بدأت', 'dashboard.projects_completed': 'مشاريع اكتملت', 'dashboard.tasks_completed': 'مهام اكتملت', 'dashboard.total_budget': 'إجمالي الميزانية',
    'dashboard.total_actual': 'إجمالي الفعلي', 'dashboard.over_budget_projects': 'مشاريع تجاوزت الميزانية', 'dashboard.no_budget_data': 'لا توجد بيانات ميزانية', 'dashboard.no_departments_data': 'لا توجد بيانات أقسام',
    'dashboard.no_upcoming_tasks': 'لا توجد مهام قادمة', 'dashboard.period': 'الفترة', 'dashboard.last7': 'آخر 7 أيام', 'dashboard.last30': 'آخر 30 يوم',
    'dashboard.last90': 'آخر 90 يوم', 'dashboard.this_year': 'هذه السنة', 'dashboard.custom': 'مخصص', 'theme.light': 'فاتح',
    'theme.dark': 'داكن', 'theme.system': 'النظام', 'priority.normal': 'عادية', 'projects.subtask_status_updated': 'تم تحديث حالة المهمة الفرعية',
    'projects.subtask_status_update_failed': 'فشل تحديث حالة المهمة الفرعية', 'projects.task_moved_to': 'تم نقل المهمة إلى {{status}}', 'projects.updated': 'تم التحديث', 'projects.task_status_update_failed': 'فشل تحديث حالة المهمة',
    'projects.risk_register': 'سجل المخاطر', 'projects.risk_count_unit': 'خطر', 'projects.view_table': 'عرض جدول', 'projects.view_cards': 'عرض بطاقات',
    'projects.add_potential_risks': 'أضف المخاطر المحتملة للمشروع', 'projects.risk_label': 'الخطر', 'projects.risk_fields_required': 'يجب ملء جميع حقول الخطر', 'projects.risk_added_success': 'تم إضافة الخطر بنجاح',
    'projects.risk_add_failed': 'فشل في إضافة الخطر', 'projects.risk_updated_success': 'تم تحديث الخطر بنجاح', 'projects.risk_update_failed': 'فشل في تحديث الخطر', 'projects.risk_status_updated': 'تم تحديث حالة الخطر',
    'projects.risk_status_update_failed': 'فشل في تحديث حالة الخطر', 'projects.risk_delete_confirm': 'هل أنت متأكد من حذف هذا الخطر؟', 'projects.risk_deleted_success': 'تم حذف الخطر بنجاح', 'projects.risk_delete_failed': 'فشل في حذف الخطر',
    'projects.response_plan': 'خطة الاستجابة', 'projects.stakeholder_name_role_required': 'يجب إدخال اسم ودور صاحب المصلحة', 'projects.stakeholder_added_success': 'تم إضافة صاحب المصلحة بنجاح', 'projects.stakeholder_add_failed': 'فشل في إضافة صاحب المصلحة',
    'projects.stakeholder_delete_confirm': 'هل أنت متأكد من حذف صاحب المصلحة هذا؟', 'projects.stakeholder_deleted_success': 'تم حذف صاحب المصلحة بنجاح', 'projects.stakeholder_delete_failed': 'فشل في حذف صاحب المصلحة', 'projects.user_selected_complete_data': 'تم تحديد المستخدم - أكمل البيانات',
    'projects.stakeholder_updated_success': 'تم تحديث صاحب المصلحة بنجاح', 'projects.stakeholder_update_failed': 'فشل في تحديث صاحب المصلحة', 'projects.person_count_unit': 'شخص', 'projects.add_project_stakeholders': 'أضف أصحاب المصلحة للمشروع',
    'projects.role': 'الدور', 'projects.not_specified': 'غير محدد', 'projects.project_team': 'فريق المشروع', 'projects.member_count_unit': 'عضو',
    'projects.add_members': 'إضافة أعضاء', 'projects.add_team_members_desc': 'أضف أعضاء فريق العمل للمشروع', 'projects.member_label': 'العضو', 'projects.remove_member': 'إزالة العضو',
    'projects.confirm_remove_member': 'هل أنت متأكد من إزالة هذا العضو من الفريق؟', 'projects.please_select_member': 'يرجى اختيار عضو', 'projects.member_added_success': 'تم إضافة العضو بنجاح', 'projects.member_add_failed': 'فشل في إضافة العضو',
    'projects.members_added_success': 'تم إضافة {{count}} أعضاء بنجاح', 'projects.members_add_failed': 'فشل في إضافة الأعضاء', 'projects.member_removed_success': 'تم إزالة العضو بنجاح', 'projects.member_remove_failed': 'فشل في إزالة العضو',
    'projects.user_selected': 'تم تحديد المستخدم', 'projects.kpis_title': 'مؤشرات الأداء الرئيسية', 'projects.kpi_count_unit': 'مؤشر', 'projects.add_project_kpis': 'أضف مؤشرات الأداء للمشروع',
    'projects.kpi_indicator': 'المؤشر', 'projects.kpi_current': 'الحالي', 'projects.of_target': 'من المستهدف', 'projects.kpi_current_value': 'القيمة الحالية',
    'projects.kpi_fields_required': 'يجب ملء جميع حقول المؤشر', 'projects.kpi_added_success': 'تم إضافة المؤشر بنجاح', 'projects.kpi_add_failed': 'فشل في إضافة المؤشر', 'projects.kpi_delete_confirm': 'هل أنت متأكد من حذف هذا المؤشر؟',
    'projects.kpi_deleted_success': 'تم حذف المؤشر بنجاح', 'projects.kpi_delete_failed': 'فشل في حذف المؤشر', 'projects.more_kpis': 'مؤشرات إضافية', 'projects.more_risks': 'مخاطر إضافية',
    'projects.completion': 'الإنجاز', 'projects.completed_count': 'مكتملة', 'projects.todo_count': 'للتنفيذ', 'projects.overdue': 'متأخرة',
    'projects.urgent_count': 'عاجلة', 'projects.time_remaining': 'الوقت المتبقي', 'projects.total_days': 'إجمالي الأيام', 'projects.kpi_name_placeholder': 'اسم مؤشر الأداء',
    'projects.example_100': 'مثال: 100', 'projects.example_50': 'مثال: 50', 'projects.kpi_unit_placeholder': 'مثال: % أو ريال أو عدد', 'projects.add_kpi_button': 'إضافة المؤشر',
    'projects.not_allowed': 'غير مسموح', 'projects.ok': 'حسناً', 'projects.incomplete_subtasks_exist': 'توجد مهام فرعية غير مكتملة', 'projects.task_label': 'المهمة',
    'projects.cannot_move_task_to': 'لا يمكن نقل المهمة إلى', 'projects.because_incomplete_subtasks': 'بسبب وجود {{count}} مهمة فرعية غير مكتملة', 'projects.remaining_subtasks': 'المهام الفرعية المتبقية', 'projects.and_more_tasks': 'و {{count}} مهمة أخرى',
    'projects.understood': 'فهمت', 'projects.edit_risk': 'تعديل الخطر', 'projects.add_risk_button': 'إضافة الخطر', 'projects.risk_description_placeholder': 'صف الخطر المحتمل...',
    'projects.response_plan_placeholder': 'صف خطة الاستجابة للخطر...', 'projects.change_risk_status': 'تغيير حالة الخطر', 'projects.select_new_status': 'اختر الحالة الجديدة', 'projects.current_response_plan': 'خطة الاستجابة الحالية',
    'projects.risk_status_open_desc': 'الخطر لا يزال قائماً ويحتاج متابعة', 'projects.risk_status_mitigated_desc': 'تم اتخاذ إجراءات لتخفيف الخطر', 'projects.risk_status_closed_desc': 'الخطر لم يعد قائماً', 'projects.mitigation_actions_label': 'إجراءات التخفيف المتخذة',
    'projects.closure_actions_label': 'سبب إغلاق الخطر', 'projects.additional_notes_optional': 'ملاحظات إضافية (اختياري)', 'projects.mitigation_placeholder': 'صف الإجراءات التي تم اتخاذها لتخفيف الخطر...', 'projects.closure_placeholder': 'صف سبب إغلاق الخطر...',
    'projects.notes_placeholder': 'أضف ملاحظات إضافية...', 'projects.stakeholder_name_placeholder': 'اسم صاحب المصلحة', 'projects.organization_placeholder': 'اسم المنظمة أو الجهة', 'projects.select_from_existing_users': 'اختيار من المستخدمين الموجودين',
    'projects.email': 'البريد الإلكتروني', 'projects.phone': 'رقم الهاتف', 'projects.add_stakeholder_button': 'إضافة صاحب المصلحة', 'projects.add_team_member': 'إضافة عضو للفريق',
    'projects.search_by_name_or_email': 'بحث بالاسم أو البريد الإلكتروني...', 'projects.no_available_users': 'لا يوجد مستخدمين متاحين', 'projects.add_new_user_to_system': 'إضافة شخص جديد للنظام', 'projects.confirm_status_change': 'تأكيد تغيير الحالة',
    'projects.move_to': 'نقل إلى', 'projects.important_notice': 'تنبيه مهم', 'projects.review_warning_message': 'بعد نقل المهمة لقيد المراجعة، لن تتمكن من إعادة فتحها أو تعديل حالتها. سيقوم مدير المشروع بمراجعتها وإكمالها.', 'projects.challenges_and_solutions': 'التحديات وكيف تم حلها',
    'projects.challenges_placeholder': 'اذكر التحديات التي واجهتها أثناء تنفيذ المهمة وكيف تم التغلب عليها...', 'projects.lessons_learned': 'الدروس المستفادة', 'projects.lessons_learned_placeholder': 'ما الدروس التي تعلمتها من هذه المهمة والتي يمكن الاستفادة منها مستقبلاً...', 'projects.review_reason': 'سبب الإرسال للمراجعة',
    'projects.review_reason_placeholder': 'اكتب سبب إرسال المهمة للمراجعة...', 'projects.review_reason_required': 'يجب كتابة سبب إرسال المهمة للمراجعة', 'projects.close_task_permanently': 'إغلاق المهمة نهائياً', 'projects.send_for_review': 'إرسال للمراجعة',
    'projects.view_stakeholder': 'عرض صاحب المصلحة', 'projects.edit_stakeholder': 'تعديل صاحب المصلحة', 'projects.drop_here': 'أفلت هنا', 'projects.subtasks': 'المهام الفرعية',
    'projects.completed_label': 'مكتملة', 'projects.view_subtasks': 'عرض المهام الفرعية', 'projects.start_adding_task': 'ابدأ بإضافة مهمة جديدة لهذا المشروع', 'projects.add_task': 'إضافة مهمة',
    'projects.all_members': 'كل الأعضاء', 'projects.assignee': 'المسؤول', 'projects.showing_of_tasks': 'عرض {{filtered}} من {{total}} مهمة', 'projects.reset_filters': 'إعادة تعيين الفلاتر',
    'projects.task_count_unit': 'مهمة', 'projects.kanban_view': 'عرض Kanban', 'projects.list_view': 'عرض قائمة', 'projects.due_date': 'تاريخ الاستحقاق',
    'projects.time_completed': 'مكتملة', 'projects.time_overdue_days': 'متأخر {{count}} يوم', 'projects.time_today': 'اليوم', 'projects.time_tomorrow': 'غداً',
    'projects.time_days_remaining': '{{count}} يوم', 'projects.time_until_due': 'المدة المتبقية للاستحقاق', 'users.total_users': 'إجمالي المستخدمين', 'users.admins': 'المدراء',
    'users.basic_info': 'المعلومات الأساسية', 'users.contact_info': 'معلومات الاتصال', 'users.department_and_roles': 'القسم والأدوار', 'users.roles': 'الأدوار',
    'users.password': 'كلمة المرور', 'users.confirm_password': 'تأكيد كلمة المرور', 'users.enter_name': 'أدخل اسم المستخدم', 'users.enter_password': 'أدخل كلمة المرور',
    'users.password_keep_empty': 'اتركها فارغة للإبقاء على القديمة', 'users.reenter_password': 'أعد إدخال كلمة المرور', 'users.select_department': '-- اختر القسم --', 'users.user_active': 'المستخدم نشط',
    'users.update_data': 'قم بتحديث بيانات المستخدم', 'users.enter_new_data': 'أدخل بيانات المستخدم الجديد', 'users.load_error': 'فشل في تحميل بيانات المستخدم', 'users.updated_success': 'تم تحديث المستخدم بنجاح',
    'users.created_success': 'تم إنشاء المستخدم بنجاح', 'users.save_error': 'حدث خطأ أثناء الحفظ', 'users.search_placeholder': 'بحث بالاسم أو البريد...', 'users.all_departments': 'كل الأقسام',
    'users.all_roles': 'كل الأدوار', 'users.all_statuses': 'كل الحالات', 'users.role_desc_super_admin': 'صلاحيات كاملة على جميع أقسام النظام', 'users.role_desc_admin': 'إدارة المستخدمين والمشاريع داخل الإدارة',
    'users.role_desc_project_manager': 'إدارة المشاريع والمهام', 'users.role_desc_team_member': 'تنفيذ المهام والمشاركة في المشاريع', 'users.role_desc_viewer': 'عرض البيانات فقط دون تعديل', 'users.setup_link_fetch_error': 'فشل في جلب معلومات الرابط',
    'users.setup_link_created': 'تم إنشاء رابط الإعداد بنجاح', 'users.setup_link_create_error': 'فشل في إنشاء الرابط', 'users.link_copied': 'تم نسخ الرابط', 'users.account_already_setup': 'الحساب مُعد بالفعل',
    'users.account_already_setup_desc': 'هذا المستخدم قام بإعداد حسابه ولا يحتاج رابط دعوة.', 'users.copy_link_instruction': 'انسخ هذا الرابط وأرسله للمستخدم لإعداد حسابه:', 'users.valid_until': 'صالح حتى', 'users.hours_remaining': '{{count}} ساعة متبقية',
    'users.create_new_link': 'إنشاء رابط جديد', 'users.new_link_warning': 'سيتم إلغاء الرابط الحالي وإنشاء رابط جديد صالح لـ 72 ساعة', 'users.no_valid_link': 'لا يوجد رابط صالح', 'users.link_expired_or_none': 'انتهت صلاحية الرابط السابق أو لم يتم إنشاء رابط.',
    'users.create_setup_link': 'إنشاء رابط إعداد جديد', 'hr.department_code': 'كود القسم', 'hr.no_parent_department': 'بدون قسم أب (إدارة عليا)', 'hr.select_parent_hint': 'اختر القسم الأب لتحديد المستويات المسموحة',
    'hr.no_sub_levels': 'لا يمكن إضافة أقسام فرعية تحت هذا المستوى', 'hr.no_levels_available': 'لا توجد مستويات متاحة', 'hr.select_manager': '-- اختر المدير --', 'hr.no_manager': '-- بدون مدير --',
    'hr.level': 'مستوى', 'hr.department_updated': 'تم تحديث القسم بنجاح', 'hr.department_created': 'تم إنشاء القسم بنجاح', 'hr.national_id': 'رقم الهوية',
    'hr.select_department': 'اختر القسم', 'hr.hire_date': 'تاريخ التعيين', 'hr.select_hire_date': 'اختر تاريخ التعيين', 'hr.employee_updated': 'تم تحديث بيانات الموظف بنجاح',
    'hr.employee_created': 'تم إضافة الموظف بنجاح', 'hr.edit_employee': 'تعديل بيانات الموظف', 'hr.create_employee': 'إضافة موظف جديد', 'hr.delete_employee': 'حذف الموظف',
    'hr.org_chart_load_error': 'فشل في تحميل الهيكل التنظيمي', 'hr.org_chart_loading': 'جاري تحميل الهيكل التنظيمي...', 'hr.start_adding_departments': 'ابدأ بإضافة أول قسم لعرض الهيكل التنظيمي', 'hr.expand_all': 'توسيع الكل',
    'hr.collapse_all': 'طي الكل', 'hr.expand': 'توسيع', 'hr.collapse': 'طي', 'hr.employee_count': '{{count}} موظف',
    'hr.total_employees': 'إجمالي الموظفين', 'hr.total_departments': 'إجمالي الأقسام', 'hr.active_departments': 'أقسام نشطة', 'hr.all_departments': 'كل الأقسام',
    'hr.all_statuses': 'كل الحالات', 'hr.search_employees_placeholder': 'بحث بالاسم أو الرقم الوظيفي...', 'auth.email': 'البريد الإلكتروني', 'auth.password': 'كلمة المرور',
    'common.allPriorities': 'كل الأولويات', 'common.allStatuses': 'كل الحالات', 'common.all_statuses': 'كل الحالات', 'common.budget': 'الميزانية',
    'common.copy_all': 'نسخ الكل', 'common.copy_failed': 'فشل النسخ', 'common.currency': 'ريال', 'common.endDate': 'تاريخ النهاية',
    'common.failed_to_load_settings': 'فشل في تحميل الإعدادات', 'common.filterResults': 'تصفية النتائج', 'common.filter_results': 'تصفية النتائج', 'common.important': 'مهم',
    'common.link_copied': 'تم نسخ الرابط', 'common.owner': 'المسؤول', 'common.remaining': 'المتبقي', 'common.searchByNameOrCode': 'بحث بالاسم أو الرمز',
    'common.spent': 'المنصرف', 'common.startDate': 'تاريخ البداية', 'errors.build': 'رقم البناء', 'errors.component_stack': 'مسار المكونات',
    'errors.copied': 'تم النسخ', 'errors.copy_report': 'نسخ التقرير', 'errors.error_id': 'معرّف الخطأ', 'errors.error_message': 'رسالة الخطأ',
    'errors.go_home': 'العودة للرئيسية', 'errors.reload_page': 'إعادة تحميل الصفحة', 'errors.route': 'المسار', 'errors.unexpected_error': 'خطأ غير متوقع',
    'errors.unknown_error': 'خطأ غير معروف', 'invitations.account_setup_link': 'رابط إعداد الحساب', 'invitations.add_new_person': 'إضافة شخص جديد', 'invitations.add_new_user': 'إضافة مستخدم جديد',
    'invitations.copy_link_and_send': 'انسخ هذا الرابط وأرسله للمستخدم', 'invitations.create_user': 'إنشاء المستخدم', 'invitations.create_user_failed': 'فشل في إنشاء المستخدم', 'invitations.invitation_link': 'رابط الدعوة',
    'invitations.invitation_link_description': 'سيتم إرسال رابط دعوة للمستخدم عبر البريد الإلكتروني', 'invitations.no_matching_users': 'لا يوجد مستخدمين مطابقين', 'invitations.permission': 'الصلاحية', 'invitations.roles.general': 'عام',
    'invitations.roles.project_leader': 'قائد مشروع', 'invitations.roles.project_supervisor': 'مشرف مشروع', 'invitations.roles.stakeholder': 'صاحب مصلحة', 'invitations.roles.task_member': 'عضو مهمة',
    'invitations.search_by_name_or_email': 'بحث بالاسم أو البريد الإلكتروني', 'invitations.select_or_add_user': 'اختر أو أضف مستخدم', 'invitations.send_link_before_closing': 'يرجى نسخ الرابط وإرساله للمستخدم قبل إغلاق هذه النافذة', 'invitations.user_already_exists': 'المستخدم موجود بالفعل',
    'invitations.user_created': 'تم إنشاء المستخدم', 'invitations.user_created_successfully': 'تم إنشاء المستخدم بنجاح', 'invitations.valid_for_72_hours': 'صالح لمدة 72 ساعة', 'invitations.will_be_added_to_project': 'سيتم إضافته للمشروع',
    'ovr.all_categories': 'كل الفئات', 'ovr.all_severities': 'كل مستويات الخطورة', 'ovr.critical_open': 'حرجة مفتوحة', 'ovr.search_placeholder': 'بحث في الحوادث...',
    'ovr.status_closed': 'مغلق', 'ovr.total_incidents': 'إجمالي الحوادث', 'profile.2fa.confirm_activation': 'تأكيد التفعيل', 'profile.2fa.disable_2fa': 'تعطيل المصادقة الثنائية',
    'profile.2fa.disable_warning_description': 'سيؤدي تعطيل المصادقة الثنائية إلى تقليل أمان حسابك. هل أنت متأكد؟', 'profile.2fa.disable_warning_title': 'تحذير: تعطيل المصادقة الثنائية', 'profile.2fa.disabled_successfully': 'تم تعطيل المصادقة الثنائية بنجاح', 'profile.2fa.enable_2fa': 'تفعيل المصادقة الثنائية',
    'profile.2fa.enabled': 'مفعلة', 'profile.2fa.enabled_successfully': 'تم تفعيل المصادقة الثنائية بنجاح', 'profile.2fa.enter_6_digit_code': 'أدخل الرمز المكون من 6 أرقام', 'profile.2fa.enter_password': 'أدخل كلمة المرور',
    'profile.2fa.enter_password_to_continue': 'أدخل كلمة المرور للمتابعة', 'profile.2fa.failed_to_disable': 'فشل في تعطيل المصادقة الثنائية', 'profile.2fa.failed_to_enable': 'فشل في تفعيل المصادقة الثنائية', 'profile.2fa.failed_to_load_status': 'فشل في تحميل حالة المصادقة الثنائية',
    'profile.2fa.failed_to_regenerate_codes': 'فشل في إعادة توليد أكواد الاسترداد', 'profile.2fa.invalid_verification_code': 'رمز التحقق غير صحيح', 'profile.2fa.or_enter_manually': 'أو أدخل يدوياً', 'profile.2fa.please_enter_6_digit_code': 'يرجى إدخال الرمز المكون من 6 أرقام',
    'profile.2fa.please_enter_password': 'يرجى إدخال كلمة المرور', 'profile.2fa.please_enter_password_and_code': 'يرجى إدخال كلمة المرور ورمز التحقق', 'profile.2fa.recovery_codes': 'أكواد الاسترداد', 'profile.2fa.recovery_codes_description': 'احفظ هذه الأكواد في مكان آمن. يمكنك استخدامها لتسجيل الدخول إذا فقدت الوصول لتطبيق المصادقة.',
    'profile.2fa.recovery_codes_regenerated': 'تم إعادة توليد أكواد الاسترداد', 'profile.2fa.regenerate_recovery_codes': 'إعادة توليد أكواد الاسترداد', 'profile.2fa.save_recovery_codes': 'احفظ أكواد الاسترداد', 'profile.2fa.saved': 'تم الحفظ',
    'profile.2fa.scan_qr_code': 'امسح رمز QR', 'profile.2fa.scan_qr_code_description': 'استخدم تطبيق المصادقة (Google Authenticator أو Authy) لمسح رمز QR', 'profile.2fa.status_disabled': 'المصادقة الثنائية معطلة', 'profile.2fa.status_disabled_description': 'حسابك غير محمي بالمصادقة الثنائية',
    'profile.2fa.status_enabled': 'المصادقة الثنائية مفعلة', 'profile.2fa.status_enabled_description': 'حسابك محمي بطبقة أمان إضافية', 'profile.2fa.title': 'المصادقة الثنائية', 'profile.2fa.verification_code_from_app': 'رمز التحقق من التطبيق',
    'projects.addNewProject': 'إضافة مشروع جديد', 'projects.project': 'المشروع', 'projects.projectManager': 'مدير المشروع', 'projects.projects': 'المشاريع',
    'strategy.dashboard.allProjectsLinked': 'جميع المشاريع مربوطة', 'strategy.dashboard.avgProgress': 'متوسط التقدم', 'strategy.dashboard.blockers': 'المعوقات', 'strategy.dashboard.noBlockers': 'لا توجد معوقات',
    'strategy.dashboard.overdue': 'متأخرة', 'strategy.dashboard.pendingDecisions': 'القرارات المعلقة', 'strategy.dashboard.quickLinks': 'روابط سريعة', 'strategy.dashboard.subtitle': 'نظرة شاملة على أداء التخطيط الاستراتيجي',
    'strategy.dashboard.title': 'لوحة التحكم الاستراتيجية', 'strategy.dashboard.unlinked': 'غير مرتبطة', 'strategy.goldenChain.loadError': 'فشل في تحميل السلسلة الذهبية', 'strategy.goldenChain.projectUnlinked': 'المشروع غير مرتبط',
    'strategy.goldenChain.projectUnlinkedDesc': 'هذا المشروع غير مرتبط ببرنامج أو التزام تنفيذي', 'strategy.goldenChain.title': 'السلسلة الذهبية', 'strategy.portfolios.addNewPortfolio': 'إضافة التزام تنفيذي جديد', 'strategy.portfolios.allPortfolios': 'كل الالتزامات',
    'strategy.portfolios.deletePortfolio': 'حذف الالتزام التنفيذي', 'strategy.portfolios.deletePortfolioWarning': 'سيتم حذف الالتزام التنفيذي وجميع البيانات المرتبطة. لا يمكن التراجع عن هذا الإجراء.', 'strategy.portfolios.executiveCommitment': 'الالتزام التنفيذي', 'strategy.portfolios.executiveCommitments': 'الالتزامات التنفيذية',
    'strategy.portfolios.totalPortfolios': 'إجمالي الالتزامات', 'strategy.programs.addNewProgram': 'إضافة مبادرة جديدة', 'strategy.programs.avgProgress': 'متوسط التقدم', 'strategy.programs.budgetUtilization': 'نسبة استخدام الميزانية',
    'strategy.programs.confirmDeleteProgram': 'تأكيد حذف المبادرة', 'strategy.programs.createNewProject': 'إنشاء مشروع جديد', 'strategy.programs.deleteProgram': 'حذف المبادرة', 'strategy.programs.deleteProgramWarning': 'سيتم حذف المبادرة وجميع البيانات المرتبطة. لا يمكن التراجع عن هذا الإجراء.',
    'strategy.programs.executiveSponsor': 'الراعي التنفيذي', 'strategy.programs.link': 'ربط', 'strategy.programs.linkExistingProject': 'ربط مشروع موجود', 'strategy.programs.linkProjectToProgram': 'ربط مشروع بالمبادرة',
    'strategy.programs.noLinkedProjects': 'لا توجد مشاريع مرتبطة', 'strategy.programs.noLinkedProjectsDesc': 'لم يتم ربط أي مشروع بهذه المبادرة بعد', 'strategy.programs.noUnlinkedProjects': 'لا توجد مشاريع متاحة للربط', 'strategy.programs.overallProgress': 'التقدم الإجمالي',
    'strategy.programs.program': 'المبادرة', 'strategy.programs.programManager': 'مدير المبادرة', 'strategy.programs.programProgress': 'تقدم المبادرة', 'strategy.programs.programs': 'المبادرات',
    'strategy.programs.projectsSummary': 'ملخص المشاريع', 'strategy.programs.totalPrograms': 'إجمالي المبادرات', 'strategy.programs.unlink': 'فك الارتباط', 'surveys.all_types': 'كل الأنواع',
    'surveys.search_placeholder': 'بحث في الاستبيانات...', 'surveys.total_surveys': 'إجمالي الاستبيانات', 'validation.email_invalid': 'البريد الإلكتروني غير صالح', 'validation.email_required': 'البريد الإلكتروني مطلوب',
    'strategy.dashboard.pendingDecisionsCount': '{{count}} قرار بانتظار المراجعة', 'common.back_to_list': '[Back To List]', 'common.cancel_edit': '[Cancel Edit]', 'common.last_updated_by': '[Last Updated By]',
    'common.not_modified': '[Not Modified]', 'common.order': '[Order]', 'common.reload': '[Reload]', 'common.save_error': '[Save Error]',
    'common.settings': '[Settings]', 'common.view_details': 'عرض التفاصيل', 'common.view_link': '[View Link]', 'hr.add_department': '[Add Department]',
    'hr.add_new_department': '[Add New Department]', 'hr.add_new_employee': '[Add New Employee]', 'hr.chart': '[Chart]', 'hr.chart_view': '[Chart View]',
    'hr.contact_info': '[Contact Info]', 'hr.department': '[Department]', 'hr.department_delete_error': '[Department Delete Error]', 'hr.department_delete_success': '[Department Delete Success]',
    'hr.departments_load_error': '[Departments Load Error]', 'hr.departments_subtitle': '[Departments Subtitle]', 'hr.employee': '[Employee]', 'hr.job_title': '[Job Title]',
    'hr.manager': '[Manager]', 'hr.no_departments_desc': '[No Departments Desc]', 'hr.no_employees_desc': '[No Employees Desc]', 'hr.table': '[Table]',
    'hr.table_view': '[Table View]', 'invitations.accept_and_create': '[Accept And Create]', 'invitations.accept_error': '[Accept Error]', 'invitations.additional_restrictions': '[Additional Restrictions]',
    'invitations.allowed_email_domains': '[Allowed Email Domains]', 'invitations.allowed_email_domains_hint': '[Allowed Email Domains Hint]', 'invitations.already_have_account': '[Already Have Account]', 'invitations.auto_add_to_project': '[Auto Add To Project]',
    'invitations.auto_add_to_project_desc': '[Auto Add To Project Desc]', 'invitations.confirm_password': '[Confirm Password]', 'invitations.confirm_password_placeholder': '[Confirm Password Placeholder]', 'invitations.daily_summary': '[Daily Summary]',
    'invitations.daily_summary_desc': '[Daily Summary Desc]', 'invitations.days': '[Days]', 'invitations.default_role': '[Default Role]', 'invitations.email': '[Email]',
    'invitations.go_to_login': '[Go To Login]', 'invitations.invalid_invitation': '[Invalid Invitation]', 'invitations.invitation_accepted': 'تم قبول الدعوة بنجاح! مرحباً بك', 'invitations.invitation_permissions': '[Invitation Permissions]',
    'invitations.invitation_settings': '[Invitation Settings]', 'invitations.invitation_validity': '[Invitation Validity]', 'invitations.join_invitation': '[Join Invitation]', 'invitations.link_expiry_duration': '[Link Expiry Duration]',
    'invitations.link_expiry_hint': '[Link Expiry Hint]', 'invitations.load_settings_error': '[Load Settings Error]', 'invitations.login': '[Login]', 'invitations.min_chars': '[Min Chars]',
    'invitations.monthly_limit': '[Monthly Limit]', 'invitations.name': '[Name]', 'invitations.name_placeholder': '[Name Placeholder]', 'invitations.name_required': '[Name Required]',
    'invitations.no_limit_hint': '[No Limit Hint]', 'invitations.notifications': '[Notifications]', 'invitations.notify_on_accept': '[Notify On Accept]', 'invitations.notify_on_accept_desc': '[Notify On Accept Desc]',
    'invitations.notify_on_expire': '[Notify On Expire]', 'invitations.notify_on_expire_desc': '[Notify On Expire Desc]', 'invitations.password': '[Password]', 'invitations.password_min_length': '[Password Min Length]',
    'invitations.password_mismatch': '[Password Mismatch]', 'invitations.password_placeholder': '[Password Placeholder]', 'invitations.password_required': '[Password Required]', 'invitations.platform_name': '[Platform Name]',
    'invitations.project': '[Project]', 'invitations.role': '[Role]', 'invitations.save_settings': '[Save Settings]', 'invitations.save_settings_error': '[Save Settings Error]',
    'invitations.settings_saved': '[Settings Saved]', 'invitations.settings_subtitle': '[Settings Subtitle]', 'invitations.task': '[Task]', 'invitations.terms_agreement': '[Terms Agreement]',
    'invitations.valid_until': '[Valid Until]', 'invitations.verifying': '[Verifying]', 'invitations.who_can_invite': '[Who Can Invite]', 'ovr.actions': 'الإجراءات',
    'ovr.category': 'التصنيف', 'ovr.date_and_location': 'التاريخ والموقع', 'ovr.description_placeholder': '[Description Placeholder]', 'ovr.immediate_action': '[Immediate Action]',
    'ovr.immediate_action_placeholder': '[Immediate Action Placeholder]', 'ovr.incident': 'الحادثة', 'ovr.incident_created': '[Incident Created]', 'ovr.incident_date': 'تاريخ الحادثة',
    'ovr.incident_description': 'وصف الحادثة', 'ovr.incident_number': 'رقم الحادثة', 'ovr.incident_time': '[Incident Time]', 'ovr.incident_updated': '[Incident Updated]',
    'ovr.load_error': 'فشل في تحميل الحوادث', 'ovr.location_placeholder': '[Location Placeholder]', 'ovr.new_incident': 'حادثة جديدة', 'ovr.patient_data': 'بيانات المريض',
    'ovr.patient_mrn': 'رقم الملف', 'ovr.patient_name': 'اسم المريض', 'ovr.register': '[Register]', 'ovr.report_incident': 'تسجيل حادثة',
    'ovr.report_new_incident': 'تسجيل حادثة جديدة', 'ovr.reporter': 'المُبلغ', 'ovr.select_category': '[Select Category]', 'ovr.select_employee': '[Select Employee]',
    'ovr.select_incident_date': '[Select Incident Date]', 'ovr.start_reporting': 'ابدأ بتسجيل أول حادثة', 'ovr.subtitle': 'تسجيل ومتابعة الحوادث الطبية', 'ovr.time': 'الوقت',
    'ovr.witnesses': 'الشهود', 'profile.confirm_password': 'تأكيد كلمة المرور', 'profile.confirm_password_placeholder': 'أعد إدخال كلمة المرور', 'profile.current_password': 'كلمة المرور الحالية',
    'profile.current_password_placeholder': 'أدخل كلمة المرور الحالية', 'profile.email': 'البريد الإلكتروني', 'profile.extension': 'التحويلة', 'profile.job_title': 'المسمى الوظيفي',
    'profile.name': 'الاسم', 'profile.name_placeholder': 'أدخل اسمك', 'profile.new_password': 'كلمة المرور الجديدة', 'profile.new_password_placeholder': 'أدخل كلمة المرور الجديدة',
    'profile.password_change_error': 'حدث خطأ أثناء تغيير كلمة المرور', 'profile.password_min_length': 'يجب أن تكون كلمة المرور 8 أحرف على الأقل', 'profile.phone': 'رقم الهاتف', 'profile.profile_update_error': 'حدث خطأ أثناء تحديث الملف الشخصي',
    'profile.subtitle': 'إدارة معلوماتك الشخصية وكلمة المرور', 'profile.role_super_admin': 'مدير النظام', 'profile.role_admin': 'مدير إدارة', 'profile.role_project_manager': 'مدير مشروع', 'profile.role_team_member': 'عضو فريق', 'profile.role_viewer': 'مشاهد', 'projects.add_deliverable': '[Add Deliverable]', 'projects.assignee_optional': '[Assignee Optional]', 'projects.budget_sar': '[Budget Sar]',
    'projects.core_members': '[Core Members]', 'projects.core_stakeholders': '[Core Stakeholders]', 'projects.deliverable_name': '[Deliverable Name]', 'projects.deliverables': '[Deliverables]',
    'projects.department': '[Department]', 'projects.description': '[Description]', 'projects.description_optional': '[Description Optional]', 'projects.end_date': '[End Date]',
    'projects.enter_description': '[Enter Description]', 'projects.enter_item': '[Enter Item]', 'projects.enter_mitigation_plan': '[Enter Mitigation Plan]', 'projects.enter_objective': '[Enter Objective]',
    'projects.enter_project_name': '[Enter Project Name]', 'projects.enter_risk_description': '[Enter Risk Description]', 'projects.financial_resources': '[Financial Resources]', 'projects.financial_resources_placeholder': '[Financial Resources Placeholder]',
    'projects.from': '[From]', 'projects.human_resources': '[Human Resources]', 'projects.human_resources_placeholder': '[Human Resources Placeholder]', 'projects.influence': '[Influence]',
    'projects.kpi_target_value': 'القيمة المستهدفة', 'projects.link_to_program_hint': '[Link To Program Hint]', 'projects.manager': '[Manager]', 'projects.milestone_date_range': '[Milestone Date Range]',
    'projects.milestone_description_placeholder': '[Milestone Description Placeholder]', 'projects.milestone_name_placeholder': '[Milestone Name Placeholder]', 'projects.milestone_optional': '[Milestone Optional]', 'projects.mitigation_plan': '[Mitigation Plan]',
    'projects.name': '[Name]', 'projects.no_departments': '[No Departments]', 'projects.no_milestone': '[No Milestone]', 'projects.priority': '[Priority]',
    'projects.program_optional': '[Program Optional]', 'projects.resources_and_support': '[Resources And Support]', 'projects.select_assignee': '[Select Assignee]', 'projects.select_end_date': '[Select End Date]',
    'projects.select_sponsor': '[Select Sponsor]', 'projects.select_stakeholder': '[Select Stakeholder]', 'projects.select_start_date': '[Select Start Date]', 'projects.select_supervisor': '[Select Supervisor]',
    'projects.select_team_member': '[Select Team Member]', 'projects.set_dates_first_milestones': '[Set Dates First Milestones]', 'projects.set_dates_first_tasks': '[Set Dates First Tasks]', 'projects.settings_and_timeline': '[Settings And Timeline]',
    'projects.sponsor': '[Sponsor]', 'projects.stakeholders_description': '[Stakeholders Description]', 'projects.standalone_project': '[Standalone Project]', 'projects.start_date': '[Start Date]',
    'projects.task_date_range': '[Task Date Range]', 'projects.task_description_placeholder': '[Task Description Placeholder]', 'projects.task_executor': '[Task Executor]', 'projects.task_name': '[Task Name]',
    'projects.task_name_placeholder': '[Task Name Placeholder]', 'projects.team_description': '[Team Description]', 'projects.technical_resources': '[Technical Resources]', 'projects.technical_resources_placeholder': '[Technical Resources Placeholder]',
    'projects.to': '[To]', 'strategy.allocated_budget': '[Allocated Budget]', 'strategy.back_to_portfolios': '[Back To Portfolios]', 'strategy.back_to_programs': '[Back To Programs]',
    'strategy.completion': '[Completion]', 'strategy.create_new_portfolio': '[Create New Portfolio]', 'strategy.create_new_program': 'إنشاء مبادرة جديدة', 'strategy.create_portfolio_desc': '[Create Portfolio Desc]',
    'strategy.create_portfolio_title': '[Create Portfolio Title]', 'strategy.create_program_desc': '[Create Program Desc]', 'strategy.create_program_title': '[Create Program Title]', 'strategy.directive_source': '[Directive Source]',
    'strategy.edit_portfolio_desc': '[Edit Portfolio Desc]', 'strategy.edit_portfolio_title': '[Edit Portfolio Title]', 'strategy.edit_program_desc': '[Edit Program Desc]', 'strategy.edit_program_title': '[Edit Program Title]',
    'strategy.executive_planning': '[Executive Planning]', 'strategy.executive_sponsor': '[Executive Sponsor]', 'strategy.manager': '[Manager]', 'strategy.new_portfolio': 'التزام جديد',
    'strategy.new_program': 'مبادرة جديدة', 'strategy.no_linked_programs': '[No Linked Programs]', 'strategy.no_linked_programs_desc': '[No Linked Programs Desc]', 'strategy.no_portfolios_desc': '[No Portfolios Desc]',
    'strategy.no_programs_desc': 'ابدأ بإنشاء أول مبادرة', 'strategy.objectives': '[Objectives]', 'strategy.other_source_name': '[Other Source Name]', 'strategy.other_source_placeholder': '[Other Source Placeholder]',
    'strategy.overall_completion': '[Overall Completion]', 'strategy.portfolio_create_success': '[Portfolio Create Success]', 'strategy.portfolio_delete_error': '[Portfolio Delete Error]', 'strategy.portfolio_desc_placeholder': '[Portfolio Desc Placeholder]',
    'strategy.portfolio_link_hint': '[Portfolio Link Hint]', 'strategy.portfolio_load_error': '[Portfolio Load Error]', 'strategy.portfolio_name_placeholder': '[Portfolio Name Placeholder]', 'strategy.portfolio_name_required': '[Portfolio Name Required]',
    'strategy.portfolio_not_found': '[Portfolio Not Found]', 'strategy.portfolio_progress': '[Portfolio Progress]', 'strategy.portfolio_required': '[Portfolio Required]', 'strategy.portfolio_save_error': '[Portfolio Save Error]',
    'strategy.portfolio_update_success': '[Portfolio Update Success]', 'strategy.portfolios_subtitle': '[Portfolios Subtitle]', 'strategy.program_create_success': '[Program Create Success]', 'strategy.program_delete_error': '[Program Delete Error]',
    'strategy.program_desc_placeholder': '[Program Desc Placeholder]', 'strategy.program_load_error': '[Program Load Error]', 'strategy.program_manager': 'مدير المبادرة', 'strategy.program_name_placeholder': '[Program Name Placeholder]',
    'strategy.program_name_required': '[Program Name Required]', 'strategy.program_not_found': '[Program Not Found]', 'strategy.program_owner': '[Program Owner]', 'strategy.program_save_error': '[Program Save Error]',
    'strategy.program_update_success': '[Program Update Success]', 'strategy.programs_subtitle': 'إدارة المبادرات التنفيذية', 'strategy.programs_summary': '[Programs Summary]', 'strategy.progress_calculation_method': '[Progress Calculation Method]',
    'strategy.projects': 'المشاريع', 'strategy.rationale': '[Rationale]', 'strategy.rationale_placeholder': '[Rationale Placeholder]', 'strategy.relative_weight': '[Relative Weight]',
    'strategy.relative_weight_hint': '[Relative Weight Hint]', 'strategy.responsible_department': '[Responsible Department]', 'strategy.select_department': '[Select Department]', 'strategy.select_executive_sponsor': '[Select Executive Sponsor]',
    'strategy.select_owner': '[Select Owner]', 'strategy.select_portfolio': '[Select Portfolio]', 'strategy.select_program_manager': '[Select Program Manager]', 'strategy.strategic_plan': '[Strategic Plan]',
    'strategy.strategic_plan_link_hint': '[Strategic Plan Link Hint]', 'strategy.strategic_plan_link_label': '[Strategic Plan Link Label]', 'strategy.strategic_plan_link_placeholder': '[Strategic Plan Link Placeholder]', 'strategy.total_program_budget': '[Total Program Budget]',
    'surveys.accept_terms': '[Accept Terms]', 'surveys.access_settings': '[Access Settings]', 'surveys.add_field': '[Add Field]', 'surveys.add_fields': '[Add Fields]',
    'surveys.add_new_field': '[Add New Field]', 'surveys.add_option': '[Add Option]', 'surveys.adding_field': '[Adding Field]', 'surveys.additional_info': '[Additional Info]',
    'surveys.answers': '[Answers]', 'surveys.answers_protected': '[Answers Protected]', 'surveys.back_to_survey': '[Back To Survey]', 'surveys.basic_info': '[Basic Info]',
    'surveys.build': '[Build]', 'surveys.build_fields': '[Build Fields]', 'surveys.build_subtitle': '[Build Subtitle]', 'surveys.cannot_edit_published': '[Cannot Edit Published]',
    'surveys.category': '[Category]', 'surveys.category_kpi': '[Category Kpi]', 'surveys.category_needs': '[Category Needs]', 'surveys.category_report': '[Category Report]',
    'surveys.category_satisfaction': '[Category Satisfaction]', 'surveys.close_error': '[Close Error]', 'surveys.close_success': '[Close Success]', 'surveys.code': '[Code]',
    'surveys.column': '[Column]', 'surveys.confirm_publish': '[Confirm Publish]', 'surveys.consent': '[Consent]', 'surveys.consent_required': '[Consent Required]',
    'surveys.consent_required_error': '[Consent Required Error]', 'surveys.consent_text': '[Consent Text]', 'surveys.consent_text_placeholder': '[Consent Text Placeholder]', 'surveys.copying': '[Copying]',
    'surveys.create_and_continue': '[Create And Continue]', 'surveys.create_success': '[Create Success]', 'surveys.default_thank_you': '[Default Thank You]', 'surveys.delete_confirm_button': '[Delete Confirm Button]',
    'surveys.delete_confirm_title': '[Delete Confirm Title]', 'surveys.delete_error': '[Delete Error]', 'surveys.delete_section': '[Delete Section]', 'surveys.delete_success': '[Delete Success]',
    'surveys.description_placeholder': '[Description Placeholder]', 'surveys.edit_field': '[Edit Field]', 'surveys.edit_fields': '[Edit Fields]', 'surveys.edit_response': '[Edit Response]',
    'surveys.edit_response_desc': '[Edit Response Desc]', 'surveys.edit_section': '[Edit Section]', 'surveys.edit_subtitle': '[Edit Subtitle]', 'surveys.end': '[End]',
    'surveys.end_date_hint': '[End Date Hint]', 'surveys.export_csv': '[Export Csv]', 'surveys.export_error': '[Export Error]', 'surveys.export_success': '[Export Success]',
    'surveys.field': '[Field]', 'surveys.field_key_hint': '[Field Key Hint]', 'surveys.field_key_label': '[Field Key Label]', 'surveys.field_key_placeholder': '[Field Key Placeholder]',
    'surveys.field_source': '[Field Source]', 'surveys.field_title': '[Field Title]', 'surveys.field_title_placeholder': '[Field Title Placeholder]', 'surveys.field_type': '[Field Type]',
    'surveys.fields': '[Fields]', 'surveys.fields_count': '[Fields Count]', 'surveys.flag': '[Flag]', 'surveys.flag_reason': '[Flag Reason]',
    'surveys.flag_reason_desc': '[Flag Reason Desc]', 'surveys.flag_reason_placeholder': '[Flag Reason Placeholder]', 'surveys.flag_response': '[Flag Response]', 'surveys.helper_description': '[Helper Description]',
    'surveys.helper_description_placeholder': '[Helper Description Placeholder]', 'surveys.important_notice': '[Important Notice]', 'surveys.link_copy_error': '[Link Copy Error]', 'surveys.link_to_table': '[Link To Table]',
    'surveys.linked_to': '[Linked To]', 'surveys.load_error': '[Load Error]', 'surveys.messages': '[Messages]', 'surveys.multiple_responses': '[Multiple Responses]',
    'surveys.multiple_responses_desc': '[Multiple Responses Desc]', 'surveys.new': '[New]', 'surveys.new_field': '[New Field]', 'surveys.new_section': '[New Section]',
    'surveys.new_section_title': '[New Section Title]', 'surveys.new_subtitle': '[New Subtitle]', 'surveys.next': '[Next]', 'surveys.no_category': '[No Category]',
    'surveys.no_fields_in_section': '[No Fields In Section]', 'surveys.no_fields_yet': '[No Fields Yet]', 'surveys.no_responses': '[No Responses]', 'surveys.no_responses_yet': '[No Responses Yet]',
    'surveys.no_section': '[No Section]', 'surveys.not_available': '[Not Available]', 'surveys.not_found': '[Not Found]', 'surveys.option_label': '[Option Label]',
    'surveys.option_value': '[Option Value]', 'surveys.options': '[Options]', 'surveys.preview': '[Preview]', 'surveys.previous': '[Previous]',
    'surveys.public_link_desc': '[Public Link Desc]', 'surveys.publish_error': '[Publish Error]', 'surveys.publish_settings': '[Publish Settings]', 'surveys.publish_success': '[Publish Success]',
    'surveys.publish_survey': '[Publish Survey]', 'surveys.publish_warning_1': '[Publish Warning 1]', 'surveys.publish_warning_2': '[Publish Warning 2]', 'surveys.publish_warning_3': '[Publish Warning 3]',
    'surveys.published_at': '[Published At]', 'surveys.question': '[Question]', 'surveys.recorded_responses': '[Recorded Responses]', 'surveys.required_field': '[Required Field]',
    'surveys.requires_auth': '[Requires Auth]', 'surveys.requires_auth_desc': '[Requires Auth Desc]', 'surveys.requires_auth_modal_desc': '[Requires Auth Modal Desc]', 'surveys.response': '[Response]',
    'surveys.response_details': '[Response Details]', 'surveys.response_details_error': '[Response Details Error]', 'surveys.response_flag_error': '[Response Flag Error]', 'surveys.response_flagged': '[Response Flagged]',
    'surveys.response_flagged_success': '[Response Flagged Success]', 'surveys.response_invalid': '[Response Invalid]', 'surveys.response_submitted': '[Response Submitted]', 'surveys.responses_load_error': '[Responses Load Error]',
    'surveys.scale_max': '[Scale Max]', 'surveys.scale_min': '[Scale Min]', 'surveys.section': '[Section]', 'surveys.section_title': '[Section Title]',
    'surveys.sections': '[Sections]', 'surveys.select_column': '[Select Column]', 'surveys.select_response': '[Select Response]', 'surveys.select_table': '[Select Table]',
    'surveys.start': '[Start]', 'surveys.start_adding_fields': '[Start Adding Fields]', 'surveys.start_creating': '[Start Creating]', 'surveys.start_date_hint': '[Start Date Hint]',
    'surveys.start_survey': '[Start Survey]', 'surveys.submission_success': '[Submission Success]', 'surveys.submit_answers': '[Submit Answers]', 'surveys.submit_failed': '[Submit Failed]',
    'surveys.submitting': '[Submitting]', 'surveys.subtitle': '[Subtitle]', 'surveys.survey': '[Survey]', 'surveys.survey_fields': '[Survey Fields]',
    'surveys.survey_info': '[Survey Info]', 'surveys.survey_link': '[Survey Link]', 'surveys.survey_title_placeholder': '[Survey Title Placeholder]', 'surveys.target_table': '[Target Table]',
    'surveys.thank_you_message': '[Thank You Message]', 'surveys.thank_you_message_placeholder': '[Thank You Message Placeholder]', 'surveys.time_period': '[Time Period]', 'surveys.tip_field_key': '[Tip Field Key]',
    'surveys.tip_link_table': '[Tip Link Table]', 'surveys.tip_no_edit_after_publish': '[Tip No Edit After Publish]', 'surveys.tip_required': '[Tip Required]', 'surveys.tip_sections': '[Tip Sections]',
    'surveys.tips': '[Tips]', 'surveys.type': '[Type]', 'surveys.type_initial_desc': '[Type Initial Desc]', 'surveys.type_initial_hint': '[Type Initial Hint]',
    'surveys.type_periodic_desc': '[Type Periodic Desc]', 'surveys.type_periodic_hint': '[Type Periodic Hint]', 'surveys.update_success': '[Update Success]', 'surveys.visitor': '[Visitor]',
    'surveys.welcome_message': '[Welcome Message]', 'surveys.welcome_message_placeholder': '[Welcome Message Placeholder]', 'users.add_new_user': 'إضافة مستخدم جديد', 'users.add_user': 'إضافة مستخدم',
    'users.delete_error': 'فشل في حذف المستخدم', 'users.delete_confirm': 'هل أنت متأكد من حذف المستخدم {{name}}؟', 'users.delete_success': 'تم حذف المستخدم بنجاح', 'users.delete_user': 'حذف المستخدم', 'users.department': 'القسم',
    'users.invitation_link': 'رابط الدعوة', 'users.no_direct_permissions': 'لا توجد صلاحيات مباشرة', 'users.no_matching_users': 'لا يوجد مستخدمون مطابقون', 'users.no_projects': 'لا توجد مشاريع مسندة',
    'users.no_projects_desc': 'لم يتم إسناد أي مشاريع لهذا المستخدم', 'users.no_tasks': 'لا توجد مهام مسندة', 'users.no_tasks_desc': 'لم يتم إسناد أي مهام لهذا المستخدم', 'users.not_found': 'المستخدم غير موجود',
    'users.permissions': 'الصلاحيات', 'users.permissions_inherited': 'الصلاحيات موروثة من الدور', 'users.projects': 'المشاريع', 'users.subtitle': 'إدارة مستخدمي النظام وصلاحياتهم',
    'users.tasks': 'المهام', 'users.user': 'المستخدم',
    'projects.risk_number': 'خطر {{number}}',
    'projects.stakeholder_number': 'صاحب مصلحة {{number}}',
    'projects.team_member_number': 'عضو فريق {{number}}',
  };
  const resolveKey = (key: string, params?: Record<string, unknown>): string => {
    const val = translations[key];
    if (val === undefined) return key;
    if (params) {
      return val.replace(/\{\{(\w+)\}\}/g, (_: string, k: string) => String(params[k] ?? `{{${k}}}`));
    }
    return val;
  };
  return {
    useTranslation: () => ({
      t: (key: string, params?: Record<string, unknown>) => resolveKey(key, params),
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
    Trans: ({ i18nKey }: { i18nKey: string }) => resolveKey(i18nKey),
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

vi.mock('@shared/api/auth', () => ({
  profileApi: {
    update: (data: any) => mockProfileUpdate(data),
    changePassword: (data: any) => mockChangePassword(data),
  },
}));

// Mock Toast
const mockShowToast = vi.fn();
vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({
    showToast: mockShowToast,
  }),
}));

// Mock Auth
const mockRefreshUser = vi.fn().mockResolvedValue({});
vi.mock('@shared/contexts/AuthContext', () => ({
  useAuth: () => ({
    canAccess: () => true,
    user: {
      name: 'أحمد محمد',
      email: 'ahmed@test.com',
      phone: '0501234567',
      job_title: 'مهندس برمجيات',
      roles: ['admin', 'project_manager'],
      department: { name: 'قسم التقنية' },
    },
    refreshUser: mockRefreshUser,
  }),
}));

// Mock UI components
vi.mock('@shared/ui/Card', () => ({
  Card: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div className={className} data-testid="card">{children}</div>
  ),
  CardHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  CardTitle: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <h2 className={className}>{children}</h2>
  ),
  CardContent: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div className={className}>{children}</div>
  ),
}));

vi.mock('@shared/ui/Button', () => ({
  Button: ({
    children,
    type,
    loading,
    leftIcon,
    onClick
  }: {
    children: React.ReactNode;
    type?: string;
    loading?: boolean;
    leftIcon?: React.ReactNode;
    onClick?: () => void;
  }) => (
    <button type={type as 'submit' | 'button'} disabled={loading} onClick={onClick} data-loading={loading}>
      {leftIcon}
      {children}
    </button>
  ),
}));

vi.mock('@shared/ui/Input', () => ({
  Input: ({
    type,
    value,
    onChange,
    placeholder,
    leftIcon,
    error
  }: {
    type?: string;
    value?: string;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    placeholder?: string;
    leftIcon?: React.ReactNode;
    error?: string;
  }) => (
    <div>
      {leftIcon}
      <input
        type={type}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        aria-invalid={!!error}
      />
      {error && <span className="error">{error}</span>}
    </div>
  ),
}));

vi.mock('@shared/ui/Badge', () => ({
  Badge: ({ children, variant }: { children: React.ReactNode; variant?: string }) => (
    <span data-testid="badge" data-variant={variant}>{children}</span>
  ),
}));

import { Profile } from '@pages/profile/Profile';

describe('Profile Page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders page title', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('الملف الشخصي')).toBeInTheDocument();
  });

  it('renders page description', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('إدارة معلوماتك الشخصية وكلمة المرور')).toBeInTheDocument();
  });

  it('renders user name', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('أحمد محمد')).toBeInTheDocument();
  });

  it('renders user email', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('ahmed@test.com')).toBeInTheDocument();
  });

  it('renders user job title', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('مهندس برمجيات')).toBeInTheDocument();
  });

  it('renders user department', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('قسم التقنية')).toBeInTheDocument();
  });

  it('renders user role badges', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    const badges = screen.getAllByTestId('badge');
    expect(badges.length).toBeGreaterThan(0);
  });

  it('renders profile form section', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('المعلومات الشخصية')).toBeInTheDocument();
  });

  it('renders password form section', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    // Multiple elements with "تغيير كلمة المرور" (heading + button)
    expect(screen.getAllByText('تغيير كلمة المرور').length).toBeGreaterThanOrEqual(1);
  });

  it('renders form labels', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText(/الاسم/)).toBeInTheDocument();
    expect(screen.getByText(/البريد الإلكتروني/)).toBeInTheDocument();
    expect(screen.getByText(/رقم الهاتف/)).toBeInTheDocument();
    expect(screen.getByText(/المسمى الوظيفي/)).toBeInTheDocument();
  });

  it('renders password labels', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText(/كلمة المرور الحالية/)).toBeInTheDocument();
    expect(screen.getByText(/كلمة المرور الجديدة/)).toBeInTheDocument();
    expect(screen.getByText(/تأكيد كلمة المرور/)).toBeInTheDocument();
  });

  it('renders save buttons', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('حفظ التغييرات')).toBeInTheDocument();
    // There are multiple elements with "تغيير كلمة المرور" text (heading + button)
    expect(screen.getAllByText('تغيير كلمة المرور').length).toBeGreaterThanOrEqual(1);
  });
});

describe('Profile Form Submission', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('submits profile form', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const form = screen.getByText('حفظ التغييرات').closest('form');
    expect(form).not.toBeNull();

    fireEvent.submit(form!);

    await waitFor(() => {
      expect(mockProfileUpdate).toHaveBeenCalled();
    });
  });

  it('shows success toast on profile update', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const form = screen.getByText('حفظ التغييرات').closest('form');
    fireEvent.submit(form!);

    await waitFor(() => {
      expect(mockShowToast).toHaveBeenCalledWith('success', 'تم تحديث الملف الشخصي بنجاح');
    });
  });

  it('refreshes user after profile update', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const form = screen.getByText('حفظ التغييرات').closest('form');
    fireEvent.submit(form!);

    await waitFor(() => {
      expect(mockRefreshUser).toHaveBeenCalled();
    });
  });

  it('submits password form', async () => {
    const user = userEvent.setup();
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    // Fill in password fields
    const passwordInputs = screen.getAllByPlaceholderText(/كلمة المرور/);
    await user.type(passwordInputs[0], 'oldpassword');
    await user.type(passwordInputs[1], 'newpassword123');
    await user.type(passwordInputs[2], 'newpassword123');

    // Find password form by finding the button with "تغيير كلمة المرور" text
    const passwordButtons = screen.getAllByText('تغيير كلمة المرور');
    const passwordSubmitButton = passwordButtons.find(btn => btn.tagName === 'BUTTON');
    if (passwordSubmitButton) {
      const passwordForm = passwordSubmitButton.closest('form');
      if (passwordForm) {
        fireEvent.submit(passwordForm);
      }
    }

    await waitFor(() => {
      expect(mockChangePassword).toHaveBeenCalled();
    });
  });

  it('shows success toast on password change', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    // Find password form by finding the button with "تغيير كلمة المرور" text
    const passwordButtons = screen.getAllByText('تغيير كلمة المرور');
    const passwordSubmitButton = passwordButtons.find(btn => btn.tagName === 'BUTTON');
    if (passwordSubmitButton) {
      const passwordForm = passwordSubmitButton.closest('form');
      if (passwordForm) {
        fireEvent.submit(passwordForm);
      }
    }

    await waitFor(() => {
      expect(mockShowToast).toHaveBeenCalledWith('success', 'تم تغيير كلمة المرور بنجاح');
    });
  });
});

describe('Profile Form Errors', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows validation errors from API', async () => {
    mockProfileUpdate.mockRejectedValueOnce({
      errors: {
        email: ['البريد الإلكتروني مستخدم بالفعل'],
      },
    });

    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const form = screen.getByText('حفظ التغييرات').closest('form');
    fireEvent.submit(form!);

    await waitFor(() => {
      expect(screen.getByText('البريد الإلكتروني مستخدم بالفعل')).toBeInTheDocument();
    });
  });

  it('shows error toast on general error', async () => {
    mockProfileUpdate.mockRejectedValueOnce({
      message: 'حدث خطأ غير متوقع',
    });

    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const form = screen.getByText('حفظ التغييرات').closest('form');
    fireEvent.submit(form!);

    await waitFor(() => {
      expect(mockShowToast).toHaveBeenCalledWith('error', 'حدث خطأ غير متوقع');
    });
  });

  it('shows password validation errors', async () => {
    mockChangePassword.mockRejectedValueOnce({
      errors: {
        current_password: ['كلمة المرور الحالية غير صحيحة'],
      },
    });

    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    // Find password form by finding the button with "تغيير كلمة المرور" text
    const passwordButtons = screen.getAllByText('تغيير كلمة المرور');
    const passwordSubmitButton = passwordButtons.find(btn => btn.tagName === 'BUTTON');
    if (passwordSubmitButton) {
      const passwordForm = passwordSubmitButton.closest('form');
      if (passwordForm) {
        fireEvent.submit(passwordForm);
      }
    }

    await waitFor(() => {
      expect(screen.getByText('كلمة المرور الحالية غير صحيحة')).toBeInTheDocument();
    });
  });
});

describe('Profile Password Toggle', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders password visibility toggle buttons', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    // Should have 3 toggle buttons for the 3 password fields
    const toggleButtons = screen.getAllByRole('button').filter(
      btn => btn.getAttribute('type') === 'button'
    );
    expect(toggleButtons.length).toBeGreaterThanOrEqual(3);
  });

  it('toggles password visibility', async () => {
    const user = userEvent.setup();
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    // Find password input by placeholder
    const passwordInputs = screen.getAllByPlaceholderText(/كلمة المرور/);
    expect(passwordInputs[0]).toHaveAttribute('type', 'password');

    // Find and click toggle button (the first button after the password input)
    const toggleButtons = screen.getAllByRole('button').filter(
      btn => btn.getAttribute('type') === 'button'
    );

    if (toggleButtons.length > 0) {
      await user.click(toggleButtons[0]);
      // After clicking, the input type should change
    }
  });
});

describe('Profile Input Changes', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('updates name field', async () => {
    const user = userEvent.setup();
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const nameInput = screen.getByPlaceholderText('أدخل اسمك');
    await user.clear(nameInput);
    await user.type(nameInput, 'اسم جديد');

    expect(nameInput).toHaveValue('اسم جديد');
  });

  it('updates email field', async () => {
    const user = userEvent.setup();
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const emailInput = screen.getByPlaceholderText('example@domain.com');
    await user.clear(emailInput);
    await user.type(emailInput, 'new@test.com');

    expect(emailInput).toHaveValue('new@test.com');
  });

  it('updates phone field', async () => {
    const user = userEvent.setup();
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });

    const phoneInput = screen.getByPlaceholderText('05xxxxxxxx');
    await user.clear(phoneInput);
    await user.type(phoneInput, '0509876543');

    expect(phoneInput).toHaveValue('0509876543');
  });
});

describe('Profile Role Labels', () => {
  it('maps admin role correctly', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('مدير إدارة')).toBeInTheDocument();
  });

  it('maps project_manager role correctly', async () => {
    render(<Profile />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByText('مدير مشروع')).toBeInTheDocument();
  });
});
