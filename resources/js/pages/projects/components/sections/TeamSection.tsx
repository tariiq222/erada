import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUsers, IconUserPlus, IconList, IconLayoutGrid, IconTrash} from '@tabler/icons-react';
import { projectsApi } from '@entities/project';
import { usersApi } from '@entities/user';
import { useToast } from '@shared/ui/Toast';
import {
  Card,
  CardContent,
  Button,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  DeleteConfirmationModal,
  Select,
  EmptyState,
} from '@shared/ui';
import SectionHeader from '@shared/ui/SectionHeader';
import { AddMemberModal } from '../modals';
import { roleIcons, roleOptions } from '../../constants';
import type { ProjectDetails } from '../../types';

interface TeamSectionProps {
  members: ProjectDetails['members'];
  projectId: number;
  onMemberAdded?: () => void;
  onMemberRemoved?: () => void;
  canEdit?: boolean;
}

const TeamSection: React.FC<TeamSectionProps> = ({ members, projectId, onMemberAdded, onMemberRemoved, canEdit = true }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [users, setUsers] = useState<{ id: number; name: string }[]>([]);
  const [selectedUserId, setSelectedUserId] = useState<string>('');
  const [selectedRole, setSelectedRole] = useState<string>('member');
  const [isLoading, setIsLoading] = useState(false);
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');

  // Delete confirmation modal state
  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    memberId: number | null;
    memberName: string;
  }>({ isOpen: false, memberId: null, memberName: '' });
  const [isDeleting, setIsDeleting] = useState(false);

  // جلب قائمة المستخدمين عند فتح الـ Modal
  const fetchUsers = async () => {
    try {
      // جلب قائمة المستخدمين والأعضاء الحاليين معاً
      const [allUsers, currentMembers] = await Promise.all([
        usersApi.getList(),
        projectsApi.getMembers(projectId),
      ]);
      // استثناء الأعضاء الموجودين بالفعل
      const existingIds = (currentMembers as { id: number }[]).map(m => m.id);
      const availableUsers = (allUsers as { id: number; name: string }[]).filter(
        (u: { id: number }) => !existingIds.includes(u.id)
      );
      setUsers(availableUsers);
    } catch (error) {
      console.error('Failed to fetch users:', error);
    }
  };

  const handleOpenModal = () => {
    fetchUsers();
    setIsModalOpen(true);
  };

  const handleAddMember = async () => {
    if (!selectedUserId) {
      showToast('error', t('projects.please_select_member'));
      return;
    }

    setIsLoading(true);
    try {
      await projectsApi.addMember(projectId, {
        user_id: Number(selectedUserId),
        role: selectedRole,
      });
      showToast('success', t('projects.member_added_success'));
      setIsModalOpen(false);
      setSelectedUserId('');
      setSelectedRole('member');
      onMemberAdded?.();
    } catch {
      showToast('error', t('projects.member_add_failed'));
    } finally {
      setIsLoading(false);
    }
  };

  // إضافة عدة أعضاء دفعة واحدة
  const handleAddMultipleMembers = async (userIds: number[]) => {
    setIsLoading(true);
    let successCount = 0;
    let failCount = 0;

    for (const userId of userIds) {
      try {
        await projectsApi.addMember(projectId, {
          user_id: userId,
          role: 'member',
        });
        successCount++;
      } catch {
        failCount++;
      }
    }

    setIsLoading(false);
    setIsModalOpen(false);

    if (successCount > 0) {
      showToast('success', t('projects.members_added_success', { count: successCount }));
      onMemberAdded?.();
    }
    if (failCount > 0) {
      showToast('error', t('projects.members_add_failed', { count: failCount }));
    }
  };

  // فتح نافذة تأكيد الحذف
  const openDeleteModal = (memberId: number, memberName: string) => {
    setDeleteModal({ isOpen: true, memberId, memberName });
  };

  // تأكيد حذف العضو
  const handleConfirmRemove = async () => {
    if (!deleteModal.memberId) return;

    setIsDeleting(true);
    try {
      await projectsApi.removeMember(projectId, deleteModal.memberId);
      showToast('success', t('projects.member_removed_success'));
      setDeleteModal({ isOpen: false, memberId: null, memberName: '' });
      onMemberRemoved?.();
    } catch {
      showToast('error', t('projects.member_remove_failed'));
    } finally {
      setIsDeleting(false);
    }
  };

  const handleRoleChange = async (memberId: number, newRole: string) => {
    try {
      await projectsApi.updateMemberRole(projectId, memberId, newRole);
      showToast('success', t('projects.member_role_updated'));
      onMemberAdded?.();
    } catch {
      showToast('error', t('projects.member_role_update_failed'));
    }
  };

  return (
    <div className="space-y-4">
      <SectionHeader
        icon={IconUsers}
        iconTone="project"
        iconVariant="subtle"
        level={3}
        title={t('projects.project_team')}
        meta={<span className="text-[var(--text-tertiary)]">{members.length} {t('projects.member_count_unit')}</span>}
        actions={
          <div className="flex items-center gap-2">
            {/* View Toggle */}
            <div className="flex items-center bg-[var(--surface-muted)] rounded-lg p-0">
              <button
                onClick={() => setViewMode('table')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'table' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('projects.view_table')}
                aria-label={t('projects.view_table')}
                aria-pressed={viewMode === 'table'}
              >
                <IconList className="h-4 w-4" />
              </button>
              <button
                onClick={() => setViewMode('cards')}
                className={`p-1 rounded-md transition-colors ${viewMode === 'cards' ? 'bg-[var(--surface-base)] shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
                title={t('projects.view_cards')}
                aria-label={t('projects.view_cards')}
                aria-pressed={viewMode === 'cards'}
              >
                <IconLayoutGrid className="h-4 w-4" />
              </button>
            </div>
            {canEdit && (
              <Button variant="outline" size="sm" leftIcon={<IconUserPlus className="h-4 w-4" />} onClick={handleOpenModal}>
                {t('projects.add_member')}
              </Button>
            )}
          </div>
        }
      />

      {/* Team Content */}
      {members.length === 0 ? (
        <Card>
          <EmptyState
            icon={IconUsers}
            title={t('projects.no_team_members')}
            description={t('projects.add_team_members_desc')}
            size="lg"
            action={canEdit ? (
              <Button leftIcon={<IconUserPlus className="h-4 w-4" />} onClick={handleOpenModal}>
                {t('projects.add_members')}
              </Button>
            ) : undefined}
          />
        </Card>
      ) : viewMode === 'table' ? (
        /* Table View */
        <Card className="border border-[var(--border-default)]">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('projects.member_label')}</TableHead>
                  <TableHead>{t('projects.role')}</TableHead>
                  <TableHead className="w-16">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {members.map((member) => {
                  const roleKey = member.pivot?.role || 'member';
                  const RoleIcon = roleIcons[roleKey] || roleIcons.default;
                  return (
                    <TableRow key={member.id}>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          <div className="h-8 w-8 rounded-full bg-[var(--surface-muted)] flex items-center justify-center shrink-0">
                            <span className="text-[var(--text-secondary)] font-bold text-sm">
                              {member.name.charAt(0)}
                            </span>
                          </div>
                          <span className="font-medium text-[var(--text-primary)]">{member.name}</span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          <RoleIcon className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
                          {canEdit ? (
                            <Select
                              value={roleKey}
                              onChange={(e) => handleRoleChange(member.id, e.target.value)}
                              className="min-w-[140px] text-sm"
                              aria-label={t('projects.role')}
                              options={roleOptions.map((opt) => ({
                                value: opt.value,
                                label: opt.label,
                              }))}
                            />
                          ) : (
                            <span className="text-sm text-[var(--text-secondary)]">{roleKey}</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        {canEdit && (
                          <button
                            onClick={() => openDeleteModal(member.id, member.name)}
                            className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                            title={t('projects.remove_member')}
                            aria-label={t('projects.remove_member')}
                          >
                            <IconTrash className="h-4 w-4" />
                          </button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        </Card>
      ) : (
        /* Cards View */
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {members.map((member) => {
            const roleKey = member.pivot?.role || 'member';
            const RoleIcon = roleIcons[roleKey] || roleIcons.default;

            return (
              <Card key={member.id} className="hover:shadow-md transition-shadow border border-[var(--border-default)]">
                <CardContent className="p-4">
                  <div className="flex items-start gap-3">
                    {/* Avatar */}
                    <div className="h-10 w-10 rounded-full bg-[var(--surface-muted)] flex items-center justify-center shrink-0">
                      <span className="text-[var(--text-secondary)] font-bold">
                        {member.name.charAt(0)}
                      </span>
                    </div>

                    {/* Info */}
                    <div className="flex-1 min-w-0">
                      <h4 className="font-semibold text-[var(--text-primary)] truncate">{member.name}</h4>
                      <div className="flex items-center gap-1 mt-1">
                        <RoleIcon className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
                        {canEdit ? (
                          <Select
                            value={roleKey}
                            onChange={(e) => handleRoleChange(member.id, e.target.value)}
                            className="min-w-[140px] text-sm"
                            aria-label={t('projects.role')}
                            options={roleOptions.map((opt) => ({
                              value: opt.value,
                              label: opt.label,
                            }))}
                          />
                        ) : (
                          <span className="text-sm text-[var(--text-secondary)]">{roleKey}</span>
                        )}
                      </div>
                    </div>

                    {/* Actions */}
                    {canEdit && (
                      <button
                        onClick={() => openDeleteModal(member.id, member.name)}
                        className="p-1 rounded-lg hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)] transition-colors"
                        title={t('projects.remove_member')}
                        aria-label={t('projects.remove_member')}
                      >
                        <IconTrash className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Add Member Modal - New Design */}
      {isModalOpen && (
        <AddMemberModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          users={users}
          selectedUserId={selectedUserId}
          selectedRole={selectedRole}
          onUserSelect={setSelectedUserId}
          onRoleChange={setSelectedRole}
          onAdd={handleAddMember}
          onAddMultiple={handleAddMultipleMembers}
          isLoading={isLoading}
          roleOptions={roleOptions}
        />
      )}

      {/* Delete Member Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.memberId !== null ? { id: deleteModal.memberId, name: deleteModal.memberName } : null}
        onClose={() => setDeleteModal({ isOpen: false, memberId: null, memberName: '' })}
        onConfirm={handleConfirmRemove}
        title={t('projects.confirm_remove_member')}
        itemName={deleteModal.memberName}
        itemSubtitle={t('projects.member_label')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('projects.remove_member')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default TeamSection;
