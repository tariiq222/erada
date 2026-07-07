import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Select } from '@shared/ui/Select';
import {IconUsers, IconUser, IconPlus, IconTrash, IconInfoCircle} from '@tabler/icons-react';
import type { TeamMemberItem, UserOption } from './types';

interface TeamSectionProps {
  teamMembers: TeamMemberItem[];
  users: UserOption[];
  onAddTeamMember: () => void;
  onRemoveTeamMember: (index: number) => void;
  onSelectTeamMember: (index: number, userId: number) => void;
  compact?: boolean;
}

const TeamSection = memo<TeamSectionProps>(({
  teamMembers,
  users,
  onAddTeamMember,
  onRemoveTeamMember,
  onSelectTeamMember,
  compact = false,
}) => {
  const { t } = useTranslation();

  const userOptions = users.map((u) => ({ value: String(u.id), label: u.name }));

  if (compact) {
    return (
      <div className="space-y-3">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs text-[var(--text-secondary)] flex items-start gap-1">
            <IconInfoCircle className="h-3.5 w-3.5 mt-0 shrink-0" />
            <span>{t('projects.team_description')}</span>
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onAddTeamMember}
            leftIcon={<IconPlus className="h-3.5 w-3.5" />}
            className="h-11 w-full shrink-0 sm:w-auto lg:h-8"
          >
            {t('projects.add_team_member')}
          </Button>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
          {teamMembers.map((member, index) => {
            return (
              <div key={index} className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-muted)]/50 p-2">
                <div className="flex items-center justify-between gap-2 mb-2">
                  <span className="text-xs font-medium text-[var(--text-primary)] flex items-center gap-1">
                    <IconUser className="h-3.5 w-3.5 text-[var(--accent-default)]" />
                    {t('projects.team_member_number', { number: index + 1 })}
                  </span>
                  {teamMembers.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="2xs"
                      onClick={() => onRemoveTeamMember(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                    >
                      <IconTrash className="h-3 w-3" />
                    </IconButton>
                  )}
                </div>
                <Select
                  options={userOptions}
                  value={member.user_id ? String(member.user_id) : ''}
                  onChange={(e) => onSelectTeamMember(index, Number(e.target.value))}
                  placeholder={t('projects.select_team_member')}
                  searchable
                />
                {member.user_id ? (
                  <span className="text-xs text-[var(--text-secondary)] px-2">{t('projects.task_executor')}</span>
                ) : null}
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconUsers className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.team')}
        </CardTitle>
        <p className="text-sm text-[var(--text-secondary)] mt-1 flex items-start gap-1">
          <IconInfoCircle className="h-4 w-4 mt-0 shrink-0" />
          <span>{t('projects.team_description')}</span>
        </p>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {/* أعضاء الفريق */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {teamMembers.map((member, index) => {
            return (
              <div key={index} className="p-3 border border-[var(--border-default)] rounded-lg bg-[var(--surface-muted)]/50">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-sm font-medium text-[var(--text-primary)] flex items-center gap-1">
                    <IconUser className="h-3.5 w-3.5 text-[var(--accent-default)]" />
                    {t('projects.team_member_number', { number: index + 1 })}
                  </span>
                  {teamMembers.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="2xs"
                      onClick={() => onRemoveTeamMember(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                    >
                      <IconTrash className="h-3 w-3" />
                    </IconButton>
                  )}
                </div>
                {/* IconUser Selection Button */}
                <Select
                  options={userOptions}
                  value={member.user_id ? String(member.user_id) : ''}
                  onChange={(e) => onSelectTeamMember(index, Number(e.target.value))}
                  placeholder={t('projects.select_team_member')}
                  searchable
                />
                {member.user_id ? (
                  <span className="text-xs text-[var(--text-secondary)] px-2">{t('projects.task_executor')}</span>
                ) : null}
              </div>
            );
          })}
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onAddTeamMember}
          leftIcon={<IconPlus className="h-4 w-4" />}
          className="mt-4 h-11 lg:h-8"
        >
          {t('projects.add_team_member')}
        </Button>
      </CardContent>
    </Card>
  );
});

TeamSection.displayName = 'TeamSection';

export default TeamSection;
