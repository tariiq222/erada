import React, { useState } from 'react';
import {IconUser, IconSearch, IconCheck} from '@tabler/icons-react';
import { Modal, ModalHeader, ModalBody, ModalFooter } from '@shared/ui/Modal';
import { Button } from '@shared/ui/Button';
import { UserOption } from './types';

interface UserSelectModalProps {
  isOpen: boolean;
  onClose: () => void;
  users: UserOption[];
  selectedUserId: string;
  onSelectUser: (userId: number) => void;
  onRemoveUser: () => void;
}

const UserSelectModal: React.FC<UserSelectModalProps> = ({
  isOpen,
  onClose,
  users,
  selectedUserId,
  onSelectUser,
  onRemoveUser,
}) => {
  const [userSearchQuery, setUserSearchQuery] = useState('');

  const filteredUsers = users.filter(
    (u) =>
      u.name.toLowerCase().includes(userSearchQuery.toLowerCase()) ||
      (u.email && u.email.toLowerCase().includes(userSearchQuery.toLowerCase()))
  );

  const handleClose = () => {
    setUserSearchQuery('');
    onClose();
  };

  return (
    <Modal open={isOpen} onClose={handleClose} size="md">
      <ModalHeader onClose={handleClose}>
        <div className="flex items-center gap-2">
          <IconUser className="h-5 w-5 text-[var(--accent-default)]" />
          اختيار المسؤول
        </div>
      </ModalHeader>
      <ModalBody>
        <div className="space-y-4">
          {/* IconSearch */}
          <div className="relative">
            <IconSearch className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-tertiary)]" />
            <input
              type="text"
              value={userSearchQuery}
              onChange={(e) => setUserSearchQuery(e.target.value)}
              placeholder="بحث بالاسم أو البريد..."
              className="w-full ps-10 pe-4 py-2 text-sm border border-[var(--border-strong)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)]/20 focus:border-[var(--accent-default)] transition-colors"
              autoFocus
            />
          </div>

          {/* Users List */}
          <div className="max-h-64 overflow-y-auto border border-[var(--border-default)] rounded-lg divide-y divide-[var(--border-default)]">
            {filteredUsers.length === 0 ? (
              <div className="p-4 text-center text-[var(--text-tertiary)] text-sm">
                لا يوجد مستخدمين مطابقين
              </div>
            ) : (
              filteredUsers.map((u) => (
                <button
                  key={u.id}
                  type="button"
                  onClick={() => {
                    onSelectUser(u.id);
                    handleClose();
                  }}
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
                        ? 'text-white'
                        : 'text-[var(--text-secondary)]'
                    }`}>
                      {u.name.charAt(0)}
                    </span>
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-[var(--text-primary)] truncate">{u.name}</p>
                    {u.email && (
                      <p className="text-xs text-[var(--text-tertiary)] truncate">{u.email}</p>
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
        <Button
          type="button"
          variant="outline"
          onClick={handleClose}
        >
          إلغاء
        </Button>
        {selectedUserId && (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              onRemoveUser();
              handleClose();
            }}
            className="text-[var(--status-danger)] hover:bg-[var(--status-danger-subtle)]"
          >
            إزالة المسؤول
          </Button>
        )}
      </ModalFooter>
    </Modal>
  );
};

export default UserSelectModal;
