/**
 * TriageModal — اختبارات منطق التصنيف وسلوك الأسئلة الشرطية
 *
 * قاعدة التصنيف عند q1=no: (ميزانية أو فريق 5+) ⇒ 'development' (تطويري)، غيره 'improvement' (تحسيني).
 *
 * الحالات المغطاة:
 * 1. q1=yes → type='improvement' (تظهر بطاقة النتيجة)
 * 2. q1=no + q2=yes + q3=big → type='development'
 * 3. q1=no + q2=no + q3=small → type='improvement'
 * 4. q1=no + q2=yes + q3=small → type='development' (الميزانية وحدها تكفي)
 * 4b. q1=no + q2=no + q3=big → type='development' (الفريق 5+ وحده يكفي — حالة الصورة)
 * 5. Q2/Q3 تظهر فقط عند q1=no وتختفي (مع مسح إجاباتهما) عند العودة لـ q1=yes
 * 6. زر «متابعة للنموذج» يستدعي onComplete بالنوع الصحيح + إجابات الفرز
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => i18nKey,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}));
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { TriageModal } from '@pages/projects/triage/TriageModal';

// ─── Mock lucide-react ────────────────────────────────────────────────────────
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconX: () => <span data-testid="icon-x">×</span>,
  IconLoader: () => <span data-testid="icon-loader" />,

  };
});

// ─── Default props helper ─────────────────────────────────────────────────────
function renderModal(overrides: Partial<React.ComponentProps<typeof TriageModal>> = {}) {
  const onClose = vi.fn();
  const onComplete = vi.fn();

  const props = {
    open: true,
    onClose,
    onComplete,
    ...overrides,
  };

  const result = render(<TriageModal {...props} />);
  return { ...result, onClose, onComplete };
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** اضغط على خيار السؤال الأول */
function clickQ1(label: string) {
  fireEvent.click(screen.getByRole('radio', { name: label }));
}

/** اضغط على خيار السؤال الثاني */
function clickQ2(label: string) {
  fireEvent.click(screen.getByRole('radio', { name: label }));
}

/** اضغط على خيار السؤال الثالث */
function clickQ3(label: string) {
  fireEvent.click(screen.getByRole('radio', { name: label }));
}

/** اضغط على زر متابعة للنموذج */
function clickContinue() {
  fireEvent.click(screen.getByRole('button', { name: 'triage.continue_button' }));
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('TriageModal — منطق التصنيف', () => {
  // [checkpoint 1/6] الإعداد الأساسي للاختبارات

  it('1. q1=yes → يصنّف كـ improvement ويظهر بطاقة النتيجة', () => {
    renderModal();

    // السؤال الأول يظهر
    expect(screen.getByText('triage.q1_label')).toBeInTheDocument();

    // اختر «triage.q1_yes»
    clickQ1('triage.q1_yes');

    // بطاقة النتيجة تظهر بالتصنيف الصحيح
    expect(screen.getByText('triage.result_improvement_title')).toBeInTheDocument();
    // النوع محدد بشكل صريح في عنوان النتيجة
    expect(
      screen.queryByText('triage.result_new_title')
    ).not.toBeInTheDocument();
  });

  // [checkpoint 2/6] q1=no + q2=yes + q3=big → new

  it('2. q1=no + q2=yes + q3=big (triage.q3_big) → يصنّف كـ new', () => {
    renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_yes');
    clickQ3('triage.q3_big');

    expect(screen.getByText('triage.result_new_title')).toBeInTheDocument();
    expect(
      screen.queryByText('triage.result_improvement_title')
    ).not.toBeInTheDocument();
  });

  it('3. q1=no + q2=no + q3=small (بدون ميزانية وفريق صغير) → يصنّف كـ improvement', () => {
    renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_no');
    // بدون ميزانية وفريق صغير هما الحالة الوحيدة التي تبقى improvement عند q1=no.
    clickQ3('triage.q3_small');

    expect(screen.getByText('triage.result_improvement_title')).toBeInTheDocument();
    expect(
      screen.queryByText('triage.result_new_title')
    ).not.toBeInTheDocument();
  });

  it('4. q1=no + q2=yes + q3=small → يصنّف كـ new (الميزانية وحدها تكفي)', () => {
    renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_yes');
    clickQ3('triage.q3_small');

    expect(screen.getByText('triage.result_new_title')).toBeInTheDocument();
    expect(
      screen.queryByText('triage.result_improvement_title')
    ).not.toBeInTheDocument();
  });

  it('4b. q1=no + q2=no + q3=big → يصنّف كـ new (الفريق 5+ وحده يكفي — حالة الصورة)', () => {
    renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_no');
    clickQ3('triage.q3_big');

    expect(screen.getByText('triage.result_new_title')).toBeInTheDocument();
    expect(
      screen.queryByText('triage.result_improvement_title')
    ).not.toBeInTheDocument();
  });

  // [checkpoint 3/6] اختبارات الظهور الشرطي

  it('5a. Q2 و Q3 يظهران فقط عند q1=no', () => {
    renderModal();

    // قبل أي اختيار: Q2 و Q3 غير ظاهرين
    expect(screen.queryByText('triage.q2_label')).not.toBeInTheDocument();
    expect(screen.queryByText('triage.q3_label')).not.toBeInTheDocument();

    // بعد اختيار «triage.q1_no»: Q2 و Q3 يظهران
    clickQ1('triage.q1_no');
    expect(screen.getByText('triage.q2_label')).toBeInTheDocument();
    expect(screen.getByText('triage.q3_label')).toBeInTheDocument();

    // عند اختيار «triage.q1_yes»: Q2 و Q3 يختفيان
    clickQ1('triage.q1_yes');
    expect(screen.queryByText('triage.q2_label')).not.toBeInTheDocument();
    expect(screen.queryByText('triage.q3_label')).not.toBeInTheDocument();
  });

  it('5b. إجابات Q2 و Q3 تُمسح عند العودة لـ q1=yes ثم q1=no مجدداً', () => {
    renderModal();

    // اختر q1=no وأجب على Q2 و Q3
    clickQ1('triage.q1_no');
    clickQ2('triage.q2_yes');
    clickQ3('triage.q3_big');

    // تظهر بطاقة النتيجة «مشروع جديد»
    expect(screen.getByText('triage.result_new_title')).toBeInTheDocument();

    // ارجع لـ q1=yes
    clickQ1('triage.q1_yes');

    // Q2 و Q3 يختفيان (إجاباتهما مُمسحة)
    expect(screen.queryByText('triage.q2_label')).not.toBeInTheDocument();
    expect(screen.queryByText('triage.q3_label')).not.toBeInTheDocument();

    // بطاقة النتيجة تتغير لـ «مشروع تحسيني» (بناءً على q1=yes فقط)
    expect(screen.getByText('triage.result_improvement_title')).toBeInTheDocument();

    // الآن ارجع لـ q1=no — يجب ألا تظهر بطاقة النتيجة حتى يُجيب على Q2 و Q3
    clickQ1('triage.q1_no');

    // Q2 و Q3 يظهران بدون إجابة مسبقة
    expect(screen.getByText('triage.q2_label')).toBeInTheDocument();
    expect(screen.getByText('triage.q3_label')).toBeInTheDocument();

    // بطاقة النتيجة لا تظهر بعد (q2 و q3 فارغان)
    expect(screen.queryByText('triage.result_new_title')).not.toBeInTheDocument();
    expect(screen.queryByText('triage.result_improvement_title')).not.toBeInTheDocument();
  });

  // [checkpoint 4/6] اختبار onComplete

  it('6a. زر «متابعة للنموذج» يستدعي onComplete بـ type=improvement عند q1=yes', () => {
    const { onComplete } = renderModal();

    clickQ1('triage.q1_yes');

    // يظهر زر «متابعة للنموذج»
    expect(screen.getByRole('button', { name: 'triage.continue_button' })).toBeInTheDocument();

    clickContinue();

    expect(onComplete).toHaveBeenCalledTimes(1);
    expect(onComplete).toHaveBeenCalledWith('improvement', {
      q1: 'yes',
      q2: null,
      q3: null,
    });
  });

  it('6b. زر «متابعة للنموذج» يستدعي onComplete بـ type=development عند q1=no + q2=yes + q3=big', () => {
    const { onComplete } = renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_yes');
    clickQ3('triage.q3_big');

    clickContinue();

    expect(onComplete).toHaveBeenCalledTimes(1);
    expect(onComplete).toHaveBeenCalledWith('development', {
      q1: 'no',
      q2: 'yes',
      q3: 'big',
    });
  });

  it('6c. زر «متابعة للنموذج» يستدعي onComplete بـ type=improvement عند q1=no + q2=no', () => {
    const { onComplete } = renderModal();

    clickQ1('triage.q1_no');
    clickQ2('triage.q2_no');
    clickQ3('triage.q3_small');

    clickContinue();

    expect(onComplete).toHaveBeenCalledTimes(1);
    expect(onComplete).toHaveBeenCalledWith('improvement', {
      q1: 'no',
      q2: 'no',
      q3: 'small',
    });
  });

  // [checkpoint 5/6] حالة سلبية: زر متابعة لا يظهر قبل اكتمال الإجابات

  it('لا تظهر بطاقة النتيجة (ولا زر المتابعة) قبل اكتمال الإجابات الضرورية', () => {
    renderModal();

    // بدون أي اختيار
    expect(screen.queryByRole('button', { name: 'triage.continue_button' })).not.toBeInTheDocument();

    // بعد q1=no فقط (Q2 و Q3 لم يُجاب عليهما)
    clickQ1('triage.q1_no');
    expect(screen.queryByRole('button', { name: 'triage.continue_button' })).not.toBeInTheDocument();

    // بعد q1=no + q2=yes فقط (Q3 لم يُجاب عليه)
    clickQ2('triage.q2_yes');
    expect(screen.queryByRole('button', { name: 'triage.continue_button' })).not.toBeInTheDocument();
  });

  // [checkpoint 6/6] التأكد من إغلاق الموديل

  it('زر «إلغاء» يستدعي onClose', () => {
    const { onClose } = renderModal();

    fireEvent.click(screen.getByRole('button', { name: 'common.cancel' }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('الموديل لا يُعرض عند open=false', () => {
    renderModal({ open: false });

    expect(screen.queryByText('triage.q1_label')).not.toBeInTheDocument();
  });
});
