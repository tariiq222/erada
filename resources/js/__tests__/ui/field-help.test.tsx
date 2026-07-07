import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
  Trans: ({ i18nKey }: { i18nKey: string }) => i18nKey,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}));
import React from 'react';
import { FieldHelp } from '@shared/ui/FieldHelp';
import { Textarea } from '@shared/ui/Textarea';
import { Input } from '@shared/ui/Input';

describe('shared inputs expose a help affordance next to the label', () => {
  it('Textarea renders a help trigger when help is provided', () => {
    render(<Textarea label="الموارد البشرية" help="الكوادر المطلوبة" />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('Textarea renders no help trigger when help is omitted', () => {
    render(<Textarea label="الموارد البشرية" />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('Input reveals its help text on focus', async () => {
    render(<Input label="اسم المشروع" help="اسم يميّز المشروع" />);
    fireEvent.focus(screen.getByRole('button'));
    expect(await screen.findByRole('tooltip')).toHaveTextContent('اسم يميّز المشروع');
  });
});

describe('FieldHelp', () => {
  it('renders an accessible help trigger button', () => {
    render(<FieldHelp content="why this field matters" />);
    const button = screen.getByRole('button');
    expect(button).toBeInTheDocument();
    expect(button).toHaveAttribute('type', 'button');
    expect(button).toHaveAttribute('aria-label');
  });

  it('reveals the explanation on focus', async () => {
    render(<FieldHelp content="القدرات التي يجب أن يحقّقها المخرَج" />);
    fireEvent.focus(screen.getByRole('button'));
    const tooltip = await screen.findByRole('tooltip');
    expect(tooltip).toHaveTextContent('القدرات التي يجب أن يحقّقها المخرَج');
  });

  it('hides the explanation again on blur', async () => {
    render(<FieldHelp content="نص الشرح" />);
    const button = screen.getByRole('button');
    fireEvent.focus(button);
    expect(await screen.findByRole('tooltip')).toBeInTheDocument();
    fireEvent.blur(button);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });
});
