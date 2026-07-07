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
  RadioGroup,
  Radio,
  Select,
  Textarea,
} from '@shared/ui';
import { IconClipboardCheck, IconClipboardList, IconDeviceFloppy, IconX } from '@shared/ui/icons';
import { useToast } from '@shared/ui/Toast';
import { recommendationsApi } from './api';
import { usersApi } from '@entities/user';
import type {
  DecidableAlias,
  Recommendation,
  RecommendationKind,
  RecommendationPriority,
  RulingType,
} from './types';

export interface RecommendationFormProps {
  mode?: 'page' | 'modal';
  initial?: Partial<Recommendation>;
  prefill?: { meeting_id?: number };
  onSuccess?: (recommendation: Recommendation) => void;
  onCancel?: () => void;
}

interface UserOption {
  id: number;
  name: string;
}

const RULING_TYPE_OPTIONS: { value: RulingType; labelKey: string }[] = [
  { value: 'approval', labelKey: 'strategy.decisions.types.approval' },
  { value: 'change_request', labelKey: 'strategy.decisions.types.change_request' },
  { value: 'escalation', labelKey: 'strategy.decisions.types.escalation' },
  { value: 'resource_allocation', labelKey: 'strategy.decisions.types.resource_allocation' },
  { value: 'scope_change', labelKey: 'strategy.decisions.types.scope_change' },
  { value: 'budget_change', labelKey: 'strategy.decisions.types.budget_change' },
  { value: 'timeline_change', labelKey: 'strategy.decisions.types.timeline_change' },
  { value: 'other', labelKey: 'strategy.decisions.types.other' },
];

const DECIDABLE_TYPE_OPTIONS: { value: DecidableAlias | ''; label: string }[] = [
  { value: '', label: '—' },
  { value: 'project', label: 'مشروع' },
  { value: 'portfolio', label: 'محفظة' },
  { value: 'program', label: 'مبادرة' },
  { value: 'risk', label: 'مخاطرة' },
];

const PRIORITY_OPTIONS: { value: RecommendationPriority; labelKey: string }[] = [
  { value: 'low', labelKey: 'meetings.recommendation.priorities.low' },
  { value: 'medium', labelKey: 'meetings.recommendation.priorities.medium' },
  { value: 'high', labelKey: 'meetings.recommendation.priorities.high' },
  { value: 'critical', labelKey: 'meetings.recommendation.priorities.critical' },
];

interface FormState {
  kind: RecommendationKind;
  title: string;
  description: string;
  decidable_type: DecidableAlias | '';
  decidable_id: string;
  // ruling
  type: RulingType | '';
  rationale: string;
  impact: string;
  // action_item
  assignee_id: string;
  due_date: string;
  priority: RecommendationPriority;
}

const FQCN_TO_ALIAS: Record<string, DecidableAlias> = {
  'App\\Modules\\Projects\\Models\\Project': 'project',
  'App\\Modules\\Strategy\\Models\\Portfolio': 'portfolio',
  'App\\Modules\\Strategy\\Models\\Program': 'program',
  'App\\Modules\\RiskManagement\\Models\\Risk': 'risk',
};

const buildInitial = (
  initial: Partial<Recommendation> | undefined,
): FormState => {
  const decidableAlias = initial?.decidable_type
    ? (FQCN_TO_ALIAS[initial.decidable_type] ?? '')
    : '';
  return {
    kind: initial?.kind ?? 'ruling',
    title: initial?.title ?? '',
    description: initial?.description ?? '',
    decidable_type: decidableAlias,
    decidable_id: initial?.decidable_id ? String(initial.decidable_id) : '',
    type: initial?.type ?? '',
    rationale: initial?.rationale ?? '',
    impact: initial?.impact ?? '',
    assignee_id: initial?.assignee_id ? String(initial.assignee_id) : '',
    due_date: initial?.due_date ?? '',
    priority: initial?.priority ?? 'medium',
  };
};

const RecommendationForm: React.FC<RecommendationFormProps> = ({
  mode: _mode = 'page',
  initial,
  prefill,
  onSuccess,
  onCancel,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [data, setData] = useState<FormState>(() => buildInitial(initial));
  const [users, setUsers] = useState<UserOption[]>([]);
  const [saving, setSaving] = useState(false);

  const meetingId = prefill?.meeting_id ?? initial?.meeting_id ?? null;

  useEffect(() => {
    // Lazy-load users only when the assignee picker is reachable.
    if (data.kind !== 'action_item' && !initial?.assignee_id) return;
    usersApi
      .getList()
      .then((res) => {
        const list = (res as unknown as UserOption[]) ?? [];
        setUsers(list);
      })
      .catch(() => setUsers([]));
  }, [data.kind, initial?.assignee_id]);

  const setField = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setData((cur) => ({ ...cur, [key]: value }));
  };

  const validationError = useMemo<string | null>(() => {
    if (!data.title.trim()) return t('common.required');
    if (data.kind === 'ruling' && !data.type) return t('common.required');
    if (data.kind === 'action_item' && !data.assignee_id) return t('common.required');
    if (data.kind === 'action_item' && !data.due_date) return t('common.required');
    return null;
  }, [data, t]);

  const cancel = () => {
    if (onCancel) onCancel();
  };

  const submit = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (validationError) {
      showToast('error', validationError);
      return;
    }
    setSaving(true);
    try {
      const payload = {
        meeting_id: meetingId,
        kind: data.kind,
        title: data.title.trim(),
        description: data.description.trim() || null,
        decidable_type: data.decidable_type || null,
        decidable_id: data.decidable_id ? Number(data.decidable_id) : null,
        ...(data.kind === 'ruling'
          ? {
              type: data.type || null,
              rationale: data.rationale.trim() || null,
              impact: data.impact.trim() || null,
            }
          : {
              assignee_id: data.assignee_id ? Number(data.assignee_id) : null,
              due_date: data.due_date || null,
              priority: data.priority,
            }),
      };
      const result = initial?.id
        ? await recommendationsApi.update(initial.id, payload)
        : await recommendationsApi.create(payload);
      const recommendation = ((result as { data?: Recommendation }).data ??
        result) as Recommendation;
      showToast(
        'success',
        initial?.id
          ? t('meetings.recommendation.messages.updated')
          : t('meetings.recommendation.messages.created'),
      );
      if (onSuccess) onSuccess(recommendation);
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred');
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
              ? t('meetings.recommendation.form.edit_title')
              : t('meetings.recommendation.form.create_title')}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <span className="mb-2 block text-sm font-medium text-[var(--text-secondary)]">
              {t('meetings.recommendation.form.kind_label', { defaultValue: 'نوع القرار' })}
            </span>
            <RadioGroup
              name="recommendation-kind"
              value={data.kind}
              onChange={(v) => setField('kind', v as RecommendationKind)}
              disabled={Boolean(initial?.id)}
              orientation="horizontal"
            >
              <Radio value="ruling" label={t('meetings.recommendation.form.kind_ruling', { defaultValue: 'قرار (توجيه/موافقة)' })} />
              <Radio value="action_item" label={t('meetings.recommendation.form.kind_action_item', { defaultValue: 'إجراء (مهمة)' })} />
            </RadioGroup>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('meetings.recommendation.fields.title')}
              value={data.title}
              onChange={(e) => setField('title', e.target.value)}
              required
              leftIcon={<IconClipboardCheck className="h-4 w-4" />}
            />
            <Select
              label={t('strategy.decisions.fields.decidable_type', {
                defaultValue: 'نوع الكيان',
              })}
              value={data.decidable_type}
              onChange={(e) => setField('decidable_type', e.target.value as DecidableAlias | '')}
              options={DECIDABLE_TYPE_OPTIONS}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Input
              label={t('strategy.decisions.fields.decidable_id', {
                defaultValue: 'معرّف الكيان',
              })}
              type="number"
              value={data.decidable_id}
              onChange={(e) => setField('decidable_id', e.target.value)}
              placeholder="—"
            />
          </div>

          <Textarea
            label={t('meetings.recommendation.fields.description')}
            value={data.description}
            onChange={(e) => setField('description', e.target.value)}
            rows={3}
          />

          {data.kind === 'ruling' ? (
            <div className="space-y-4 rounded-md border border-[var(--border-default)] bg-[var(--surface-subtle)] p-4">
              <div className="flex items-center gap-2">
                <IconClipboardCheck className="h-4 w-4 text-[var(--accent-default)]" />
                <h3 className="text-sm font-semibold text-[var(--text-primary)]">
                  {t('meetings.recommendation.form.kind_ruling', { defaultValue: 'تفاصيل القرار' })}
                </h3>
              </div>
              <Select
                label={t('strategy.decisions.fields.type', { defaultValue: 'نوع القرار' })}
                value={data.type}
                onChange={(e) => setField('type', e.target.value as RulingType)}
                options={[
                  { value: '', label: t('meetings.recommendation.form.select_decision') },
                  ...RULING_TYPE_OPTIONS.map((o) => ({
                    value: o.value,
                    label: t(o.labelKey),
                  })),
                ]}
                required
              />
              <Textarea
                label={t('strategy.decisions.fields.rationale', { defaultValue: 'المبررات' })}
                value={data.rationale}
                onChange={(e) => setField('rationale', e.target.value)}
                rows={3}
              />
              <Textarea
                label={t('strategy.decisions.fields.impact', { defaultValue: 'الأثر' })}
                value={data.impact}
                onChange={(e) => setField('impact', e.target.value)}
                rows={2}
              />
            </div>
          ) : (
            <div className="space-y-4 rounded-md border border-[var(--border-default)] bg-[var(--surface-subtle)] p-4">
              <div className="flex items-center gap-2">
                <IconClipboardList className="h-4 w-4 text-[var(--accent-default)]" />
                <h3 className="text-sm font-semibold text-[var(--text-primary)]">
                  {t('meetings.recommendation.form.kind_action_item', {
                    defaultValue: 'تفاصيل الإجراء',
                  })}
                </h3>
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Select
                  label={t('meetings.recommendation.fields.assignee')}
                  value={data.assignee_id}
                  onChange={(e) => setField('assignee_id', e.target.value)}
                  options={[
                    { value: '', label: t('meetings.recommendation.form.select_assignee') },
                    ...users.map((u) => ({ value: String(u.id), label: u.name })),
                  ]}
                  required
                />
                <DatePicker
                  label={t('meetings.recommendation.fields.due_date')}
                  value={data.due_date}
                  onChange={(v) => setField('due_date', v)}
                />
                <Select
                  label={t('meetings.recommendation.fields.priority')}
                  value={data.priority}
                  onChange={(e) =>
                    setField('priority', e.target.value as RecommendationPriority)
                  }
                  options={PRIORITY_OPTIONS.map((o) => ({
                    value: o.value,
                    label: t(o.labelKey),
                  }))}
                />
              </div>
            </div>
          )}

          {validationError && (
            <Alert variant="warning">{validationError}</Alert>
          )}
        </CardContent>
      </Card>

      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="ghost"
          onClick={cancel}
          leftIcon={<IconX className="h-4 w-4" />}
        >
          {t('meetings.recommendation.form.cancel')}
        </Button>
        <Button
          type="submit"
          loading={saving}
          disabled={Boolean(validationError)}
          leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
        >
          {initial?.id
            ? t('meetings.recommendation.form.submit_update')
            : t('meetings.recommendation.form.submit_create')}
        </Button>
      </div>
    </form>
  );
};

export default RecommendationForm;