import React, { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUsers, IconUserPlus, IconCircleX} from '@tabler/icons-react';
import { departmentRolesApi } from '@entities/hr';
import { usersApi } from '@entities/user';
import { useToast } from '@shared/ui/Toast';
import {
  Card,
  CardContent,
  Button,
  Badge,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  Modal,
  ModalBody,
  ModalFooter,
  Select,
  Checkbox,
  Skeleton,
  DeleteConfirmationModal,
} from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import { departmentRoleLabels } from '../constants';

interface DepartmentTeamSectionProps {
  deptId: number;
}

interface MemberUser {
  id: number;
  name: string;
  email: string;
  job_title?: string | null;
}

interface MemberRow {
  id: number;
  user: MemberUser;
  role_id: number;
  role_name: string;
  role_display: string;
  inherit_to_children: boolean;
  expires_at: string | null;
  created_at: string;
}

interface MembersResponse {
  data: MemberRow[];
  available_roles: Array<{ id: number; name: string; label: string }>;
}

interface UserOption {
  id: number;
  name: string;
  email?: string;
}

const DepartmentTeamSection: React.FC<DepartmentTeamSectionProps> = ({ deptId }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [members, setMembers] = useState<MemberRow[]>([]);
  const [availableRoles, setAvailableRoles] = useState<Array<{ id: number; name: string; label: string }>>([]);
  const [isLoading, setIsLoading] = useState(true);

  const [isAssignOpen, setIsAssignOpen] = useState(false);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [isUsersLoading, setIsUsersLoading] = useState(false);
  const [selectedUserId, setSelectedUserId] = useState('');
  const [selectedRole, setSelectedRole] = useState('');
  const [inheritToChildren, setInheritToChildren] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    userId: number | null;
    userName: string;
  }>({ isOpen: false, userId: null, userName: '' });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchMembers = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await departmentRolesApi.getMembers(deptId);
      const r = res as unknown as MembersResponse;
      setMembers(r?.data ?? []);
      setAvailableRoles(r?.available_roles ?? []);
    } catch {
      showToast('error', t('hr.departmentRoles.load_error'));
    } finally {
      setIsLoading(false);
    }
  }, [deptId, showToast, t]);

  useEffect(() => {
    fetchMembers();
  }, [fetchMembers]);

  const handleOpenAssign = async () => {
    setIsAssignOpen(true);
    setIsUsersLoading(true);
    try {
      const res: any = await usersApi.getList();
      const list: UserOption[] = Array.isArray(res) ? res : (res?.data ?? []);
      const takenIds = new Set(members.map((m) => m.user.id));
      setUsers(list.filter((u) => !takenIds.has(u.id)));
    } catch {
      showToast('error', t('hr.departmentRoles.load_error'));
    } finally {
      setIsUsersLoading(false);
    }
  };

  const handleCloseAssign = () => {
    setIsAssignOpen(false);
    setSelectedUserId('');
    setSelectedRole('');
    setInheritToChildren(false);
  };

  const handleAssign = async () => {
    if (!selectedUserId || !selectedRole) {
      showToast('error', t('hr.departmentRoles.select_user_and_role'));
      return;
    }
    setIsSubmitting(true);
    try {
      await departmentRolesApi.assignRoleAssignment(deptId, {
        user_id: Number(selectedUserId),
        role_id: Number(selectedRole),
        inherit_to_children: inheritToChildren,
      });
      showToast('success', t('hr.departmentRoles.assign_success'));
      handleCloseAssign();
      await fetchMembers();
    } catch {
      showToast('error', t('hr.departmentRoles.assign_error'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleConfirmRemove = async () => {
    if (deleteModal.userId == null) return;
    setIsDeleting(true);
    try {
      const member = members.find((item) => item.user.id === deleteModal.userId);
      if (!member) return;
      await departmentRolesApi.removeMember(deptId, deleteModal.userId, member.role_id);
      showToast('success', t('hr.departmentRoles.remove_success'));
      setDeleteModal({ isOpen: false, userId: null, userName: '' });
      await fetchMembers();
    } catch {
      showToast('error', t('hr.departmentRoles.remove_error'));
    } finally {
      setIsDeleting(false);
    }
  };

  const userOptions = users.map((u) => ({
    value: String(u.id),
    label: u.name + (u.email ? ` (${u.email})` : ''),
  }));

  const roleOptions =
    availableRoles.length > 0
      ? availableRoles.map((role) => ({ value: String(role.id), label: role.label }))
      : [];

  const resolveRoleLabel = (row: MemberRow): string => {
    if (row.role_display) return row.role_display;
    return row.role_display || departmentRoleLabels[row.role_name] || row.role_name;
  };

  const renderAvatar = (name: string) => {
    const initial = (name || '?').trim().charAt(0).toUpperCase();
    return (
      <div className="h-9 w-9 rounded-full bg-[var(--accent-default)] flex items-center justify-center text-[var(--text-inverse)] text-sm font-semibold shrink-0">
        {initial}
      </div>
    );
  };

  return (
    <div className="space-y-4">
      <Card className="border border-[var(--border-default)]">
        <CardContent className="p-6">
          {/* Header */}
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div className="flex items-center gap-2">
              <IconUsers className="h-5 w-5 text-[var(--text-secondary)]" />
              <div>
                <h3 className="text-base font-semibold text-[var(--text-primary)]">
                  {t('hr.departmentRoles.title')}
                </h3>
                <p className="text-xs text-[var(--text-secondary)]">
                  {members.length} {t('hr.departmentRoles.member_count_unit')}
                </p>
              </div>
            </div>
            <Button
              leftIcon={<IconUserPlus className="h-4 w-4" />}
              onClick={handleOpenAssign}
            >
              {t('hr.departmentRoles.assign_role')}
            </Button>
          </div>

          {/* Content */}
          {isLoading ? (
            <div className="space-y-3">
              {[...Array(4)].map((_, i) => (
                <div key={i} className="flex items-center gap-4 p-2">
                  <Skeleton className="h-9 w-9 rounded-full" />
                  <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-3 w-28" />
                  </div>
                  <Skeleton className="h-6 w-20" />
                  <Skeleton className="h-6 w-16" />
                </div>
              ))}
            </div>
          ) : members.length === 0 ? (
            <div className="text-center py-10">
              <IconUsers className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
              <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">
                {t('hr.departmentRoles.no_members')}
              </h3>
              <p className="text-[var(--text-secondary)] mb-4">
                {t('hr.departmentRoles.no_members_desc')}
              </p>
              <Button
                leftIcon={<IconUserPlus className="h-4 w-4" />}
                onClick={handleOpenAssign}
              >
                {t('hr.departmentRoles.assign_role')}
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table hoverable>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('hr.departmentRoles.user')}</TableHead>
                    <TableHead>{t('hr.departmentRoles.role')}</TableHead>
                    <TableHead>{t('hr.departmentRoles.inheritance')}</TableHead>
                    <TableHead className="w-16 text-center">
                      {t('common.actions')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {members.map((m) => (
                    <TableRow key={m.id}>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          {renderAvatar(m.user?.name)}
                          <div>
                            <p className="font-medium text-[var(--text-primary)]">
                              {m.user?.name}
                            </p>
                            <p className="text-xs text-[var(--text-secondary)]">
                              {m.user?.job_title || m.user?.email}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="accent" size="sm">
                          {resolveRoleLabel(m)}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {m.inherit_to_children ? (
                          <span className="text-[var(--status-success)] font-medium">
                            {t('common.yes')}
                          </span>
                        ) : (
                          <span className="text-[var(--text-secondary)]">
                            {t('common.no')}
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center">
                          <IconButton
                            variant="danger"
                            onClick={() =>
                              setDeleteModal({
                                isOpen: true,
                                userId: m.user.id,
                                userName: m.user.name,
                              })
                            }
                            title={t('hr.departmentRoles.remove')}
                          >
                            <IconCircleX className="h-4 w-4" />
                          </IconButton>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Assign Modal */}
      <Modal
        isOpen={isAssignOpen}
        onClose={handleCloseAssign}
        title={t('hr.departmentRoles.assign_role')}
        size="md"
      >
        <ModalBody>
          <div className="space-y-4">
            <Select
              label={t('hr.departmentRoles.user')}
              options={userOptions}
              value={selectedUserId}
              onChange={({ target }) => setSelectedUserId(target.value)}
              placeholder={
                isUsersLoading
                  ? t('common.loading')
                  : t('hr.departmentRoles.select_user')
              }
              disabled={isUsersLoading}
              required
            />
            <Select
              label={t('hr.departmentRoles.role')}
              options={roleOptions}
              value={selectedRole}
              onChange={({ target }) => setSelectedRole(target.value)}
              placeholder={t('hr.departmentRoles.select_role')}
              required
            />
            <div>
              <Checkbox
                checked={inheritToChildren}
                onChange={(e) => setInheritToChildren(e.target.checked)}
                label={t('hr.departmentRoles.inherit_to_children')}
              />
            </div>
          </div>
        </ModalBody>
        <ModalFooter>
          <Button variant="outline" onClick={handleCloseAssign} disabled={isSubmitting}>
            {t('common.cancel')}
          </Button>
          <Button
            onClick={handleAssign}
            loading={isSubmitting}
            leftIcon={<IconUserPlus className="h-4 w-4" />}
          >
            {t('hr.departmentRoles.assign_role')}
          </Button>
        </ModalFooter>
      </Modal>

      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.userId !== null ? { id: deleteModal.userId, name: deleteModal.userName } : null}
        onClose={() => setDeleteModal({ isOpen: false, userId: null, userName: '' })}
        onConfirm={handleConfirmRemove}
        title={t('hr.departmentRoles.confirm_remove')}
        itemName={deleteModal.userName}
        itemSubtitle={t('hr.departmentRoles.member_label')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('hr.departmentRoles.remove')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default DepartmentTeamSection;
