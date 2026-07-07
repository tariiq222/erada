# 🚀 دليل النشر (Deployment Guide)

## المعمارية

```
git push (main)
       ↓
GitHub Actions
       ↓
┌──────────────────┐
│   1. Run Tests   │
└────────┬─────────┘
         ↓
┌──────────────────┐
│  2. Migrations   │ ← يتصل مباشرة بقاعدة البيانات الإنتاجية
└────────┬─────────┘
         ↓
┌──────────────────┐
│ 3. Dokploy Hook  │ ← يطلق webhook لبدء النشر
└────────┬─────────┘
         ↓
┌──────────────────┐
│ 4. Health Check  │ ← يتحقق من نجاح النشر
└──────────────────┘
```

## إعداد GitHub Secrets

اذهب إلى: **Repository → Settings → Secrets and variables → Actions**

### Secrets المطلوبة:

| Secret | الوصف | مثال |
|--------|-------|------|
| `APP_KEY` | مفتاح التطبيق (من .env) | `base64:xxxxx...` |
| `APP_URL` | رابط التطبيق | `https://pmo.example.com` |
| `DB_HOST` | عنوان قاعدة البيانات | `db.example.com` أو `127.0.0.1` |
| `DB_PORT` | منفذ PostgreSQL | `5432` |
| `DB_DATABASE` | اسم قاعدة البيانات | `iradah_pmo` |
| `DB_USERNAME` | اسم المستخدم | `iradah` |
| `DB_PASSWORD` | كلمة المرور | `your_secure_password` |
| `DOKPLOY_WEBHOOK_URL` | رابط Webhook من Dokploy | `https://dokploy.example.com/api/deploy/xxx` |
| `MAIL_MAILER` | مشغّل البريد (smtp / postmark) | `postmark` |
| `MAIL_HOST` | خادم SMTP | `smtp.postmarkapp.com` |
| `MAIL_PORT` | منفذ SMTP | `587` |
| `MAIL_USERNAME` | اسم مستخدم SMTP | `postmark` |
| `MAIL_PASSWORD` | كلمة مرور SMTP (نفس توكن Postmark) | `your-postmark-smtp-token` |
| `MAIL_ENCRYPTION` | تشفير STARTTLS | `tls` |
| `MAIL_FROM_ADDRESS` | عنوان المُرسِل | `noreply@iradah.sa` |
| `MAIL_FROM_NAME` | اسم المُرسِل | `منصة إرادة` |
| `POSTMARK_TOKEN` | توكن API لـ Postmark (مطلوب فقط لو MAIL_MAILER=postmark) | `your-postmark-api-token` |

### Production Overrides

القيم أدناه **ليست** GitHub Secrets — بل متغيرات بيئة تُصدَّر مباشرة إلى حاوية Dokploy (Settings → Environment Variables) أو تُوضع في ملف `.env` على السيرفر. كل واحد منها له قيمة افتراضية آمنة محلياً، لكن الإنتاج يجب أن يكتبها صراحة. غيابها عن الإنتاج يعني بقاء الإعداد الخطير الافتراضي.

| متغير | محلياً (افتراضي `.env.example`) | إنتاج | لماذا يجب تغييره |
|------|--------------------------------|-------|------------------|
| `APP_ENV` | `local` | `production` | يُفعّل caches، يُعطّل stack traces، يبدّل الـ error envelope. |
| `APP_DEBUG` | `true` | `false` | **خطر أمني حرج**: `true` يكشف أسرار الـ env، stack traces كاملة، ومتغيرات الخادم في الاستجابة لأي 500. |
| `SESSION_DRIVER` | (database) | `redis` | database sessions = write-amplification على الـ master DB؛ redis أسرع ويتحمّل. |
| `CACHE_STORE` | (database) | `redis` | نفس السبب — rate-limit + 2FA pending + config cache يعتمدون على هذا. |
| `QUEUE_CONNECTION` | (database) | `redis` | الـ scheduler + الـ notifier يعتمدان على queue مشتق من cache. |
| `LOG_CHANNEL` | `stack` | `stack` | تأكد `LOG_STACK=daily` (لا `single`) حتى يُلفَّ اليومي تلقائياً. |
| `LOG_LEVEL` | `debug` | `error` | debug يطبع payload كامل للـ HTTP requests — ضوضاء + تكلفة disk في الإنتاج. |
| `DB_SSLMODE` | (غير مضبوط → يفترض `require`) | `require` أو `verify-full` | `prefer`/`allow` يُسقط TLS بصمت لو فشل negotiation. |
| `DB_SSLROOTCERT` | (فارغ) | `/etc/ssl/postgres-ca.pem` (المسار داخل الحاوية) | مطلوب لـ `verify-full` لمنع MITM على اتصال DB. |
| `SESSION_SECURE_COOKIE` | (افتراضي = production فقط عبر الـ code) | `true` | يضمن إرسال كوكي الجلسة على HTTPS فقط. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,localhost:8000` | نطاق الإنتاج الفعلي (مثلاً `pmo.example.com`) | ضروري لـ SPA stateful auth عبر cookie. |
| `TRUSTED_PROXIES` | (فارغ) | `*` أو CIDR Dokploy (مثلاً `10.0.0.0/8`) | بدونه، خلف Dokploy الـ `Request::ip()` يُرجع IP البروكسي → rate-limit keys خاطئة. |
| `MAIL_MAILER` | `log` | `postmark` (أو `smtp`) | `log` يبتلع الإشعارات بصمت في الإنتاج. |

### سكريبت التحقق (يُشغَّل على السيرفر بعد النشر)

قبل إعلان النشر ناجحاً، شغّل هذا على بيئة الإنتاج لتتأكد أن كل override حُمل فعلياً:

```bash
docker compose exec app php artisan tinker --execute="
\$checks = [
    'APP_ENV'            => ['expect' => 'production', 'fail' => 'NOT production'],
    'APP_DEBUG'          => ['expect' => false,        'fail' => 'DEBUG=true — SECURITY INCIDENT'],
    'SESSION_DRIVER'     => ['expect' => 'redis',      'fail' => 'database sessions under load'],
    'CACHE_STORE'        => ['expect' => 'redis',      'fail' => 'database cache hot-path'],
    'QUEUE_CONNECTION'   => ['expect' => 'redis',      'fail' => 'database queue contention'],
    'DB_SSLMODE'         => ['expect' => 'require',    'fail' => 'DB connection NOT forced TLS'],
    'SESSION_SECURE_COOKIE' => ['expect' => true,      'fail' => 'cookie NOT https-only'],
    'SANCTUM_STATEFUL_DOMAINS' => ['expect_contains' => 'example.com', 'fail' => 'stateful domain not set for prod'],
];
foreach (\$checks as \$k => \$c) {
    \$v = config(strtolower(str_replace('_', '.', \$k === 'DB_SSLMODE' ? 'database.connections.pgsql.sslmode' : \$k === 'SANCTUM_STATEFUL_DOMAINS' ? 'sanctum.stateful' : 'app.' . strtolower(\$k))));
    \$ok = isset(\$c['expect_contains']) ? str_contains((string)\$v, \$c['expect_contains']) : \$v === \$c['expect'];
    echo str_pad(\$k, 28) . (\$ok ? '[OK]' : '[FAIL — ' . \$c['fail'] . ']') . PHP_EOL;
}
"
```

### كيفية الحصول على القيم:

#### 1. APP_KEY
```bash
# من السيرفر أو ملف .env
cat /path/to/project/.env | grep APP_KEY
```

#### 2. DOKPLOY_WEBHOOK_URL
1. اذهب إلى لوحة Dokploy
2. اختر المشروع → Settings → Webhooks
3. انسخ الـ Webhook URL

#### 3. معلومات قاعدة البيانات
```bash
# من السيرفر
cat /path/to/project/.env | grep DB_
```

#### 4. إعدادات البريد (Postmark)
1. أنشئ حساب على https://postmarkapp.com وأضف نطاق المُرسِل (مثلاً `pmo.example.com`).
2. تحقّق من النطاق عبر DKIM + Return-Path (Postmark يرسل تعليمات DNS).
3. أنشئ Server API Token و SMTP Token.
4. ضع القيم في GitHub Secrets بالأسماء أعلاه.
5. تأكد أن `MAIL_FROM_ADDRESS` ينتمي للنطاق المُحقّق، وإلا سيفشل إرسال الرسائل.
6. اختبر: `docker compose exec app php artisan tinker` ثم
   `Mail::raw('test', fn ($m) => $m->to('you@example.com')->subject('Erada SMTP test'));`

## التشغيل

### النشر التلقائي
يتم تلقائياً عند الدفع إلى `main`:
```bash
git push origin main
```

### النشر اليدوي
1. اذهب إلى: **Actions → Deploy to Production**
2. اضغط: **Run workflow**
3. اختياري: حدد "Skip migrations" إذا لا توجد migrations جديدة

### تخطي الـ Migrations
إذا أردت النشر بدون تشغيل migrations:
1. Actions → Deploy to Production → Run workflow
2. ✅ Skip running migrations

## استكشاف الأخطاء

### خطأ في الاتصال بقاعدة البيانات
```
SQLSTATE[08006] Connection refused
```
**الحل:**
- تأكد أن `DB_HOST` يشير إلى عنوان خارجي (ليس `localhost`)
- تأكد أن المنفذ `5432` مفتوح في الجدار الناري
- تأكد من صحة بيانات الاعتماد

### خطأ في Dokploy Webhook
```
Failed to trigger Dokploy deployment
```
**الحل:**
- تحقق من صحة `DOKPLOY_WEBHOOK_URL`
- تأكد أن Dokploy يعمل

### فشل Health Check
```
Health check failed after 5 attempts
```
**الحل:**
- تحقق من سجلات Dokploy
- تحقق من سجلات Laravel: `storage/logs/laravel.log`

## الأمان

### قاعدة البيانات
لتشغيل Migrations من GitHub Actions، يجب:
1. **إما**: فتح المنفذ 5432 من عناوين GitHub Actions فقط
2. **أو**: استخدام SSH Tunnel
3. **أو**: استخدام VPN

### عناوين IP لـ GitHub Actions
```bash
# يمكن الحصول عليها من:
curl -s https://api.github.com/meta | jq '.actions'
```

### بديل آمن: SSH Tunnel
بدلاً من فتح المنفذ مباشرة، يمكن استخدام SSH:

```yaml
# في deploy.yml
- name: Setup SSH Tunnel
  run: |
    ssh -fN -L 5432:localhost:5432 user@${{ secrets.SERVER_IP }}
```

## Health Check Endpoint

تم إضافة endpoint للفحص:

```
GET /api/health
```

**الاستجابة الناجحة:**
```json
{
  "status": "ok",
  "timestamp": "2026-01-21T12:00:00+00:00",
  "services": {
    "database": "ok",
    "cache": "ok"
  }
}
```

**الاستجابة عند وجود مشكلة:**
```json
{
  "status": "degraded",
  "timestamp": "2026-01-21T12:00:00+00:00",
  "services": {
    "database": "ok",
    "cache": "error"
  }
}
```
