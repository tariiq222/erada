import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from './Button';
import { IconClipboardCheck } from './icons';
import { useToast } from './Toast';

interface CopyButtonProps {
  text: string;
  label?: string;
  variant?: 'ghost' | 'secondary' | 'primary' | 'outline';
  size?: 'sm' | 'md';
}

const CopyButton: React.FC<CopyButtonProps> = ({ text, label, variant = 'ghost', size = 'sm' }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [busy, setBusy] = useState(false);

  const handleCopy = async () => {
    if (busy || !text) return;
    setBusy(true);
    try {
      await navigator.clipboard.writeText(text);
      showToast('success', t('meetings.reference_number.copied'));
    } catch {
      showToast('error', 'Copy failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button
      variant={variant}
      size={size}
      onClick={handleCopy}
      disabled={busy || !text}
      aria-label={label ?? t('meetings.reference_number.copy')}
      leftIcon={<IconClipboardCheck className="h-4 w-4" />}
    >
      {label && <span>{label}</span>}
    </Button>
  );
};

export default CopyButton;
