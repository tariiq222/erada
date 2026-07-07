import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUser, IconUserPlus, IconCircleX, IconCheck, IconPlus} from '@tabler/icons-react';

interface AddMemberModalProps {
  isOpen: boolean;
  onClose: () => void;
  users: { id: number; name: string; email?: string }[];
  selectedUserId: string;
  selectedRole: string;
  onUserSelect: (userId: string) => void;
  onRoleChange: (role: string) => void;
  onAdd: () => Promise<void> | void;
  isLoading: boolean;
  roleOptions: { value: string; label: string }[];
  onAddNewUser?: () => void;
  onAddMultiple?: (userIds: number[]) => Promise<void>;
}

const AddMemberModal: React.FC<AddMemberModalProps> = ({
  isOpen,
  onClose,
  users,
  onUserSelect,
  onRoleChange,
  onAdd,
  isLoading,
  onAddNewUser,
  onAddMultiple,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
  const [isAdding, setIsAdding] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  if (!isOpen) return null;

  const filteredUsers = users.filter(
    (u) =>
      u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (u.email && u.email.toLowerCase().includes(searchQuery.toLowerCase()))
  );

  const getInitials = (name: string) => {
    const parts = name.trim().split(' ');
    if (parts.length >= 2) {
      return parts[0].charAt(0) + parts[1].charAt(0);
    }
    return name.charAt(0);
  };

  const getAvatarColor = (id: number) => {
    const colors = [
      'bg-[var(--accent-default)]',
      'bg-[var(--accent-hover)]',
      'bg-[var(--text-secondary)]',
      'bg-[var(--text-tertiary)]',
    ];
    return colors[id % colors.length];
  };

  // تبديل حالة التحديد للمستخدم
  const toggleUserSelection = (userId: number) => {
    setSelectedUserIds((prev) =>
      prev.includes(userId)
        ? prev.filter((id) => id !== userId)
        : [...prev, userId]
    );
  };

  // إضافة جميع المستخدمين المحددين
  const handleAddSelected = async () => {
    if (selectedUserIds.length === 0) return;

    setIsAdding(true);

    try {
      if (onAddMultiple) {
        // استخدام الدالة الجديدة لإضافة عدة مستخدمين
        await onAddMultiple(selectedUserIds);
      } else {
        // الطريقة القديمة - إضافة واحد تلو الآخر
        for (const userId of selectedUserIds) {
          onUserSelect(String(userId));
          onRoleChange('member');
          await onAdd();
        }
      }
      setSelectedUserIds([]);
      setSearchQuery('');
      onClose();
    } catch {
      // الخطأ سيُعالج في TeamSection
    } finally {
      setIsAdding(false);
    }
  };

  const handleClose = () => {
    setSelectedUserIds([]);
    setSearchQuery('');
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={handleClose} />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border-default)]">
          <button
            type="button"
            onClick={handleClose}
            aria-label={t('common.close')}
            className="p-1 rounded-full hover:bg-[var(--surface-subtle)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            <IconCircleX className="h-5 w-5 text-[var(--text-tertiary)]" />
          </button>
          <h2 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{t('projects.add_team_member')}</h2>
        </div>

        {/* Body */}
        <div className="p-5">
          <div className="space-y-4">
            {/* Search Input */}
            <div className="relative">
              <IconUser className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-tertiary)]" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder={t('projects.search_by_name_or_email')}
                className="w-full ps-10 pe-4 py-3 text-sm border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-[var(--accent-default)] transition-colors bg-[var(--surface-subtle)]"
                autoFocus
              />
            </div>

            {/* Users List */}
            <div className="max-h-64 overflow-y-auto -mx-1 px-1">
              {filteredUsers.length === 0 ? (
                <div className="py-8 text-center text-[var(--text-tertiary)] text-sm">
                  {t('projects.no_available_users')}
                </div>
              ) : (
                <div className="space-y-1">
                  {filteredUsers.map((u) => {
                    const isSelected = selectedUserIds.includes(u.id);
                    return (
                      <button
                        key={u.id}
                        type="button"
                        onClick={() => toggleUserSelection(u.id)}
                        disabled={isLoading || isAdding}
                        className={`w-full flex items-center gap-3 px-3 py-2 text-start rounded-lg transition-colors disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] ${
                          isSelected
                            ? 'bg-[var(--accent-subtle)]/70'
                            : 'hover:bg-[var(--surface-subtle)]'
                        }`}
                      >
                        {/* Selection Indicator */}
                        <div className={`h-5 w-5 rounded border flex items-center justify-center shrink-0 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] ${
                          isSelected
                            ? 'bg-[var(--accent-default)] border-[var(--accent-default)]'
                            : 'border-[var(--border-strong)] bg-[var(--surface-base)]'
                        }`}>
                          {isSelected && <IconCheck className="h-3.5 w-3.5 text-[var(--text-inverse)]" strokeWidth={3} />}
                        </div>
                        {/* Avatar */}
                        <div className={`h-9 w-9 rounded-full ${getAvatarColor(u.id)} flex items-center justify-center shrink-0`}>
                          <span className="text-xs font-semibold text-[var(--text-inverse)]">
                            {getInitials(u.name)}
                          </span>
                        </div>
                        {/* Info */}
                        <div className="flex-1 min-w-0">
                          <p className="font-medium text-[var(--text-primary)] text-sm truncate">{u.name}</p>
                          {u.email && (
                            <p className="text-xs text-[var(--text-tertiary)] truncate">{u.email}</p>
                          )}
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Add New IconUser Button */}
            {onAddNewUser && (
              <button
                type="button"
                onClick={onAddNewUser}
                className="w-full flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-[var(--border-strong)] rounded-xl text-[var(--text-secondary)] hover:border-[var(--accent-default)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)]/50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
              >
                <IconUserPlus className="h-5 w-5" />
                <span className="font-medium">{t('projects.add_new_user_to_system')}</span>
              </button>
            )}

            {/* Action Buttons */}
            <div className="flex items-center justify-between pt-2">
              <button
                type="button"
                onClick={handleClose}
                className="text-sm text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] rounded"
              >
                {t('common.cancel')}
              </button>

              {/* Add Button */}
              <button
                type="button"
                onClick={handleAddSelected}
                disabled={selectedUserIds.length === 0 || isLoading || isAdding}
                className="flex items-center gap-2 px-5 py-2 bg-[var(--accent-default)] text-[var(--text-inverse)] rounded-xl font-medium hover:bg-[var(--accent-hover)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2"
              >
                {isAdding ? (
                  <span className="h-4 w-4 border-2 border-[var(--text-inverse)]/30 border-t-[var(--text-inverse)] rounded-full animate-spin" />
                ) : (
                  <IconPlus className="h-4 w-4" />
                )}
                <span>
                  {t('common.add')}
                  {selectedUserIds.length > 0 && ` (${selectedUserIds.length})`}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AddMemberModal;
