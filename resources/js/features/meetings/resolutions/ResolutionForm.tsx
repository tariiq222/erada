import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  DatePicker,
  Input,
  Radio,
  RadioGroup,
  Select,
  Textarea,
} from '@shared/ui';
import {
  IconClipboardCheck,
  IconClipboardList,
  IconDeviceFloppy,
  IconFlag,
  IconPlus,
  IconTrash,
  IconX,
} from '@shared/ui/icons';
import { useToast } from '@shared/ui/Toast';
import { resolutionsApi } from './api';
import { usersApi } from '@entities/user';
import type {
  LinkableType,
  LinkRole,
  MeetingResolution,
  ResolutionCreatePayload,
  ResolutionKind,
  ResolutionLinkPayload,
  ResolutionPriority,
} from './types';

export interface ResolutionFormProps {
  mode: 'modal' | 'page';
  meetingId: number;
  initial?: Partial<MeetingResolution>;
  onSuccess?: (resolution: MeetingResolution) => void;
  onCancel?: () => void;
}

interface UserOption {
  id: number;
  name: string;
}

interface LinkDraft {
  /** Local-only identifier so React keys are stable across reorders. */
  uid: string;
  linkable_type: LinkableType;
  linkable_id: string;
  link_role: LinkRole;
}

// Monotonic counter for local-only link IDs — security-irrelevant, just
// used as a React key.
let linkUidCounter = 0;
const makeLinkUid = (): string => `link-${++linkUidCounter}`;

const PRIORITY_OPTIONS: { value: ResolutionPriority; labelKey: string }[] = [
  { value: 'low', labelKey: 'meetings.resolution.priorities.low' },
  { value: 'medium', labelKey: 'meetings.resolution.priorities.medium' },
  { value: 'high', labelKey: 'meetings.resolution.priorities.high' },
  { value: 'critical', labelKey: 'meetings.resolution.priorities.critical' },
];

const LINKABLE_TYPE_OPTIONS: { value: LinkableType; label: string }[] = [
  { value: 'project', label: 'مشروع' },
  { value: 'risk', label: 'مخاطرة' },
];

const PRIORITY_DEFAULT_LABEL: Record<ResolutionPriority, string> = {
  low: 'منخفضة',
  medium: 'متوسطة',
  high: 'عالية',
  critical: 'حرجة',
};

const LINK_ROLE_OPTIONS: { value: LinkRole; label: string }[] = [
  { value: 'related_to', label: 'مرتبط بـ' },
  { value: 'implementation_scope', label: 'نطاق التنفيذ' },
];

interface FormState {
  kind: ResolutionKind;
  title: string;
  description: string;
  owner_id: string;
  priority: ResolutionPriority;
  due_date: string;
  links: LinkDraft[];
}

const buildInitial = (
  initial: Partial<MeetingResolution> | undefined,
): FormState => ({
  kind: initial?.kind ?? 'recommendation',
  title: initial?.title ?? '',
  description: initial?.description ?? '',
  owner_id: initial?.owner_id ? String(initial.owner_id) : '',
  priority: initial?.priority ?? 'medium',
  due_date: initial?.due_date ?? '',
  links:
    initial?.links?.map((l) => ({
      uid: makeLinkUid(),
      linkable_type: l.linkable_type,
      linkable_id: String(l.linkable_id),
      link_role: l.link_role,
    })) ?? [],
});

const ResolutionForm: React.FC<ResolutionFormProps> = ({
  mode: _mode = 'page',
  meetingId,
  initial,
  onSuccess,
  onCancel,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [data, setData] = useState<FormState>(() => buildInitial(initial));
  const [users, setUsers] = useState<UserOption[]>([]);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    let active = true;
    usersApi
      .getList()
      .then((res) => {
        const list = (Array.isArray(res)
          ? res
          : (res as { data?: UserOption[] }).data) ?? [];
        if (active) setUsers(list);
      })
      .catch(() => {
        if (active) setUsers([]);
      });
    return () => { active = false; };
  }, []);

  const setField = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setData((cur) => ({ ...cur, [key]: value }));
    setFieldErrors((cur) => {
      if (!(key in cur)) return cur;
      const next = { ...cur };
      delete next[key];
      return next;
    });
  };

  const addLink = () => {
    setData((cur) => ({
      ...cur,
      links: [
        ...cur.links,
        {
          uid: makeLinkUid(),
          linkable_type: 'project',
          linkable_id: '',
          link_role: 'related_to',
        },
      ],
    }));
  };

  const updateLink = (index: number, patch: Partial<LinkDraft>) => {
    setData((cur) => ({
      ...cur,
      links: cur.links.map((l, i) => (i === index ? { ...l, ...patch } : l)),
    }));
  };

  const removeLink = (index: number) => {
    setData((cur) => ({
      ...cur,
      links: cur.links.filter((_, i) => i !== index),
    }));
  };

  const validationError = useMemo<string | null>(() => {
    if (!data.title.trim()) {
      return t('common.required', { defaultValue: 'حقل مطلوب' });
    }
    if (!data.owner_id) {
      return t('common.required', { defaultValue: 'حقل مطلوب' });
    }
    return null;
  }, [data, t]);

  const submit = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (validationError) {
      showToast('error', validationError);
      return;
    }
    setSaving(true);
    setFieldErrors({});
    try {
      const cleanLinks: ResolutionLinkPayload[] = data.links
        .filter((l) => l.linkable_id.trim() !== '')
        .map((l) => ({
          linkable_type: l.linkable_type,
          linkable_id: Number(l.linkable_id),
          link_role: l.link_role,
        }));

      const payload: ResolutionCreatePayload = {
        meeting_id: meetingId,
        kind: data.kind,
        title: data.title.trim(),
        description: data.description.trim() || null,
        owner_id: Number(data.owner_id),
        priority: data.priority,
        due_date: data.due_date || null,
        ...(cleanLinks.length > 0 ? { links: cleanLinks } : {}),
      };

      const result = initial?.id
        ? await resolutionsApi.update(initial.id, payload)
        : await resolutionsApi.createForMeeting(meetingId, payload);

      const created =
        (result as { resolution?: MeetingResolution }).resolution ??
        (result as unknown as MeetingResolution);

      showToast(
        'success',
        initial?.id
          ? t('meetings.resolution.messages.updated', { defaultValue: 'تم تحديث القرار' })
          : t('meetings.resolution.messages.created', { defaultValue: 'تم إنشاء القرار' }),
      );
      if (onSuccess) onSuccess(created);
    } catch (err) {
      const apiErr = err as { errors?: Record<string, string[]>; message?: string };
      if (apiErr?.errors && typeof apiErr.errors === 'object') {
        const next: Record<string, string> = {};
        Object.entries(apiErr.errors).forEach(([k, v]) => {
          if (Array.isArray(v) && v.length > 0) {
            next[k] = String(v[0]);
          } else if (typeof v === 'string') {
            next[k] = v;
          }
        });
        setFieldErrors(next);
      }
      const msg =
        apiErr?.message ?? t('common.error_occurred', { defaultValue: 'حدث خطأ' });
      showToast('error', msg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>
            {initial?.id
              ? t('meetings.resolution.form.edit_title', { defaultValue: 'تعديل القرار' })
              : t('meetings.resolution.form.create_title', { defaultValue: 'قرار جديد' })}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <span className="mb-2 block text-sm font-medium text-[var(--text-secondary)]">
              {t('meetings.resolution.form.kind_label', { defaultValue: 'نوع القرار' })}
            </span>
            <RadioGroup
              name="resolution-kind"
              value={data.kind}
              onChange={(v) => setField('kind', v as ResolutionKind)}
              disabled={Boolean(initial?.id)}
              orientation="horizontal"
            >
              <Radio
                value="recommendation"
                label={t('meetings.resolution.kind.recommendation', { defaultValue: 'توصية' })}
              />
              <Radio
                value="decision"
                label={t('meetings.resolution.kind.decision', { defaultValue: 'قرار' })}
              />
            </RadioGroup>
          </div>

          <Input
            label={t('meetings.resolution.fields.title', { defaultValue: 'العنوان' })}
            value={data.title}
            onChange={(e) => setField('title', e.target.value)}
            required
            error={fieldErrors.title}
            leftIcon={<IconClipboardCheck className="h-4 w-4" />}
          />

          <Textarea
            label={t('meetings.resolution.fields.description', { defaultValue: 'الوصف' })}
            value={data.description}
            onChange={(e) => setField('description', e.target.value)}
            rows={3}
            error={fieldErrors.description}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Select
              label={t('meetings.resolution.fields.owner', { defaultValue: 'المالك' })}
              value={data.owner_id}
              onChange={(e) => setField('owner_id', e.target.value)}
              options={[
                {
                  value: '',
                  label: t('meetings.resolution.form.select_owner', {
                    defaultValue: 'اختر المالك',
                  }),
                },
                ...users.map((u) => ({ value: String(u.id), label: u.name })),
              ]}
              required
              error={fieldErrors.owner_id}
            />
            <Select
              label={t('meetings.resolution.fields.priority', { defaultValue: 'الأولوية' })}
              value={data.priority}
              onChange={(e) =>
                setField('priority', e.target.value as ResolutionPriority)
              }
              options={PRIORITY_OPTIONS.map((o) => ({
                value: o.value,
                label: t(o.labelKey, {
                  defaultValue: PRIORITY_DEFAULT_LABEL[o.value],
                }),
              }))}
            />
            <DatePicker
              label={t('meetings.resolution.fields.due_date', { defaultValue: 'تاريخ الاستحقاق' })}
              value={data.due_date}
              onChange={(v) => setField('due_date', v)}
            />
          </div>

          <div className="space-y-3 rounded-md border border-[var(--border-default)] bg-[var(--surface-subtle)] p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                {data.kind === 'decision' ? (
                  <IconClipboardCheck className="h-4 w-4 text-[var(--accent-default)]" />
                ) : (
                  <IconClipboardList className="h-4 w-4 text-[var(--accent-default)]" />
                )}
                <h3 className="text-sm font-semibold text-[var(--text-primary)]">
                  {t('meetings.resolution.form.links_title', {
                    defaultValue: 'الروابط (اختياري)',
                  })}
                </h3>
              </div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                leftIcon={<IconPlus className="h-4 w-4" />}
                onClick={addLink}
              >
                {t('meetings.resolution.form.add_link', { defaultValue: 'إضافة رابط' })}
              </Button>
            </div>

            {data.links.length === 0 ? (
              <p className="text-xs text-[var(--text-tertiary)]">
                {t('meetings.resolution.form.links_empty', {
                  defaultValue: 'لا توجد روابط — اربط القرار بمشروع أو مخاطرة لتحديد نطاق التنفيذ.',
                })}
              </p>
            ) : (
              <div className="space-y-2">
                {data.links.map((link, idx) => (
                  <div
                    key={link.uid}
                    className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]"
                  >
                    <Select
                      label={
                        idx === 0
                          ? t('meetings.resolution.fields.linkable_type', {
                              defaultValue: 'نوع الكيان',
                            })
                          : undefined
                      }
                      value={link.linkable_type}
                      onChange={(e) =>
                        updateLink(idx, {
                          linkable_type: e.target.value as LinkableType,
                        })
                      }
                      options={LINKABLE_TYPE_OPTIONS}
                    />
                    <Input
                      label={
                        idx === 0
                          ? t('meetings.resolution.fields.linkable_id', {
                              defaultValue: 'معرّف الكيان',
                            })
                          : undefined
                      }
                      type="number"
                      value={link.linkable_id}
                      onChange={(e) =>
                        updateLink(idx, { linkable_id: e.target.value })
                      }
                      placeholder="—"
                      leftIcon={<IconFlag className="h-4 w-4" />}
                    />
                    <Select
                      label={
                        idx === 0
                          ? t('meetings.resolution.fields.link_role', {
                              defaultValue: 'الدور',
                            })
                          : undefined
                      }
                      value={link.link_role}
                      onChange={(e) =>
                        updateLink(idx, { link_role: e.target.value as LinkRole })
                      }
                      options={LINK_ROLE_OPTIONS}
                    />
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => removeLink(idx)}
                      leftIcon={<IconTrash className="h-4 w-4" />}
                      aria-label={t('meetings.resolution.form.remove_link', {
                        defaultValue: 'حذف الرابط',
                      })}
                    >
                      {t('common.delete', { defaultValue: 'حذف' })}
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {validationError && <Alert variant="warning">{validationError}</Alert>}
        </CardContent>
      </Card>

      <div className="flex justify-end gap-2">
        {onCancel && (
          <Button
            type="button"
            variant="ghost"
            onClick={onCancel}
            leftIcon={<IconX className="h-4 w-4" />}
          >
            {t('common.cancel', { defaultValue: 'إلغاء' })}
          </Button>
        )}
        <Button
          type="submit"
          loading={saving}
          disabled={Boolean(validationError)}
          leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
        >
          {initial?.id
            ? t('meetings.resolution.form.submit_update', { defaultValue: 'حفظ التعديلات' })
            : t('meetings.resolution.form.submit_create', { defaultValue: 'إنشاء' })}
        </Button>
      </div>
    </form>
  );
};

export default ResolutionForm;
