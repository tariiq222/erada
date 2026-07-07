import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconSearch, IconUser, IconCheck} from '@tabler/icons-react';
import { Button } from '@shared/ui/Button';
import { Modal, ModalHeader, ModalBody, ModalFooter } from '@shared/ui/Modal';
import type { UserOption } from './types';

interface UserModalProps {
  isOpen: boolean;
  onClose: () => void;
  users: UserOption[];
  selectedUserId: string;
  searchQuery: string;
  onSearchChange: (query: string) => void;
  onSelectUser: (userId: number) => void;
  onRemoveUser: () => void;
}

const UserModal = memo<UserModalProps>(({
  isOpen,
  onClose,
  users,
  selectedUserId,
  searchQuery,
  onSearchChange,
  onSelectUser,
  onRemoveUser,
}) => {
  const { t } = useTranslation();
  const filteredUsers = users.filter(
    (u) =>
      u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (u.email && u.email.toLowerCase().includes(searchQuery.toLowerCase()))
  );

  return (
    <Modal open={isOpen} onClose={onClose} size="md">
      <ModalHeader onClose={onClose}>
        <div className="flex items-center gap-2">
          <IconUser className="h-5 w-5 text-[var(--accent-default)]" />
          {t('tasks.select_assignee_title')}
        </div>
      </ModalHeader>
      <ModalBody>
        <div className="space-y-4">
          {/* IconSearch */}
          <div className="relative">
            <IconSearch className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-tertiary)]" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder={t('tasks.search_user_placeholder')}
              className="w-full ps-10 pe-4 py-2 text-sm border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)]/20 focus:border-[var(--accent-default)] transition-colors"
              autoFocus
            />
          </div>

          {/* Users List */}
          <div className="max-h-64 overflow-y-auto border border-[var(--border-default)] rounded-lg divide-y divide-[var(--border-default)]">
            {filteredUsers.length === 0 ? (
              <div className="p-4 text-center text-[var(--text-secondary)] text-sm">
                {t('tasks.no_matching_users')}
              </div>
            ) : (
              filteredUsers.map((u) => (
                <button
                  key={u.id}
                  type="button"
                  onClick={() => onSelectUser(u.id)}
                  className={`w-full flex items-center gap-3 px-4 py-3 text-start hover:bg-[var(--surface-subtle)] transition-colors ${
                    selectedUserId === u.id.toString() ? 'bg-[var(--accent-subtle)]' : ''
                  }`}
                >
                  <div className={`h-9 w-9 rounded-full flex items-center justify-center shrink-0 ${
                    selectedUserId === u.id.toString()
                      ? 'bg-[var(--accent-default)]'
                      : 'bg-[var(--surface-muted)]'
                  }`}>
                    <span className={`text-sm font-semibold ${
                      selectedUserId === u.id.toString()
                        ? 'text-[var(--text-inverse)]'
                        : 'text-[var(--text-secondary)]'
                    }`}>
                      {u.name.charAt(0)}
                    </span>
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-[var(--text-primary)] truncate">{u.name}</p>
                    {u.email && (
                      <p className="text-xs text-[var(--text-secondary)] truncate">{u.email}</p>
                    )}
                  </div>
                  {selectedUserId === u.id.toString() && (
                    <IconCheck className="h-5 w-5 text-[var(--accent-default)] shrink-0" />
                  )}
                </button>
              ))
            )}
          </div>
        </div>
      </ModalBody>
      <ModalFooter>
        <Button type="button" variant="outline" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        {selectedUserId && (
          <Button
            type="button"
            variant="ghost"
            onClick={onRemoveUser}
            className="text-[var(--status-danger)] hover:bg-[var(--status-danger-subtle)]"
          >
            {t('tasks.remove_assignee')}
          </Button>
        )}
      </ModalFooter>
    </Modal>
  );
});

UserModal.displayName = 'UserModal';

export default UserModal;
