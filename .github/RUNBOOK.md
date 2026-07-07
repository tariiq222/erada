# 🚨 دليل الاستعادة من النسخ الاحتياطي — Restore Runbook

> **الهدف:** استعادة قاعدة البيانات الإنتاجية ومرفقاتها بعد حادث (migrate:fresh عرضي، حذف volume، فساد schema). الهدف: RPO 24h، RTO 60 دقيقة.

---

## 1. قبل أي استعادة (Pre-flight checklist)

- [ ] وثّق الحادث: commit SHA، الوقت، الـ migration التي فشلت.
- [ ] أبلغ الفريق في Slack: `#incidents`.
- [ ] قرّر أي backup ستستعيد (انظر §2).
- [ ] تحقق أن لديك صلاحيات IAM على `BACKUP_BUCKET`.

---

## 2. اختر الـ Backup المناسب

```bash
# قائمة آخر 30 يوم من daily backups
aws s3 ls s3://$BACKUP_BUCKET/daily/ --recursive | sort | tail -30

# قائمة الـ pre-migrate backups
aws s3 ls s3://$BACKUP_BUCKET/pre-migrate/ --recursive | sort | tail -30

# قائمة storage snapshots
aws s3 ls s3://$BACKUP_BUCKET/storage/ --recursive | sort | tail -30
```

---

## 3. استعادة قاعدة البيانات (PostgreSQL)

### 3.1 حمّل الـ dump

```bash
export BACKUP_BUCKET="erada-backups-prod"
export DB_HOST="..."     # نفس قيمة secrets.DB_HOST
export DB_USER="..."
export DB_NAME="iradah_pmo"

# مثال: استعادة من daily backup بتاريخ 2026-06-27
aws s3 cp s3://${BACKUP_BUCKET}/daily/erada-iradah_pmo-20260627-0307.dump.gz ./
gunzip erada-iradah_pmo-20260627-0307.dump.gz
```

### 3.2 أوقف التطبيق

```bash
# عبر Dokploy CLI أو الـ UI
dokploy stop erada-prod
# أو: اقطع traffic عبر الـ reverse proxy / LB
```

### 3.3 نفّذ الاستعادة

> ⚠️ `--clean --if-exists` يحذف الـ objects الموجودة قبل الإنشاء. هذا مدمر — تأكد 100% أن الـ dump صحيح.

```bash
# اختياري: استعادة في DB منفصلة أولاً للـ smoke test
createdb -h $DB_HOST -U $DB_USER iradah_pmo_restore_test
pg_restore -h $DB_HOST -U $DB_USER -d iradah_pmo_restore_test \
  --no-owner --no-privileges \
  ./erada-iradah_pmo-20260627-0307.dump

# التحقق من الـ smoke test
psql -h $DB_HOST -U $DB_USER -d iradah_pmo_restore_test \
  -c "SELECT COUNT(*) FROM users;"
psql -h $DB_HOST -U $DB_USER -d iradah_pmo_restore_test \
  -c "SELECT COUNT(*) FROM projects;"
psql -h $DB_HOST -U $DB_USER -d iradah_pmo_restore_test \
  -c "SELECT MAX(created_at) FROM users;"  # يجب أن يكون قريباً من وقت الـ backup

# الاستعادة الفعلية
dropdb -h $DB_HOST -U $DB_USER --if-exists $DB_NAME
createdb -h $DB_HOST -U $DB_USER $DB_NAME
pg_restore -h $DB_HOST -U $DB_USER -d $DB_NAME \
  --no-owner --no-privileges \
  --clean --if-exists \
  ./erada-iradah_pmo-20260627-0307.dump
```

### 3.4 أعد التطبيق

```bash
dokploy start erada-prod
```

### 3.5 تحقّق

```bash
# Health check
curl -fsS $APP_URL/api/health
# المتوقع: HTTP 200 + {"status":"ok","services":{"database":"ok",...}}

# Login smoke test
curl -fsS $APP_URL/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"<rotate-after>"}'
```

---

## 4. استعادة المرفقات (storage)

```bash
# حمّل آخر storage snapshot
aws s3 cp s3://${BACKUP_BUCKET}/storage/erada-storage-20260627-0307.tar.gz ./

# أوقف التطبيق لتفادي الكتابة فوق الـ snapshot
dokploy stop erada-prod

# فك الضغط فوق storage/app/private
ssh dokploy@$DOKPLOY_HOST \
  "sudo tar -xzf - -C /var/www/storage/app" < erada-storage-20260627-0307.tar.gz

# ضبط الصلاحيات
ssh dokploy@$DOKPLOY_HOST \
  "sudo chown -R www-data:www-data /var/www/storage/app"

dokploy start erada-prod
```

---

## 5. توثيق ما بعد الحادث (Post-incident)

- [ ] Postmortem خلال 48 ساعة.
- [ ] حدّث هذا الـ runbook بناءً على ما تعلّمته.
- [ ] لو كان السبب migration سيئة، أضف rollback migration قبل الـ deploy التالي.

---

## 6. اتصال (Contact)

- **DBA on-call:** عبر PagerDuty rotation
- **Dokploy admin:** @tariq
- **S3 access:** IAM group `erada-backup-readers`