import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { strategyDashboardApi } from '@entities/strategy';
import { Card } from '@shared/ui';
import {IconBriefcase, IconRocket, IconLayoutKanban, IconChevronLeft, IconAlertCircle} from '@tabler/icons-react';

interface ChainItem {
  id: number;
  code: string;
  name: string;
  status?: string;
}

/**
 * PMI Standard Golden Chain
 * Portfolio (الالتزام التنفيذي) → Program (المبادرة) → Project (المشروع)
 */
interface GoldenChain {
  portfolio: ChainItem | null;
  program: ChainItem | null;
  project: ChainItem | null;
}

interface GoldenChainViewProps {
  type: 'portfolio' | 'program' | 'project';
  id: number;
  showWarning?: boolean;
}

const CHAIN_ICONS = {
  portfolio: IconBriefcase,
  program: IconRocket,
  project: IconLayoutKanban,
};

const CHAIN_COLORS = {
  portfolio: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  program: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  project: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
};

const CHAIN_LINKS = {
  portfolio: '/strategy/portfolios',
  program: '/strategy/programs',
  project: '/projects',
};

const GoldenChainView: React.FC<GoldenChainViewProps> = ({ type, id, showWarning = true }) => {
  const { t } = useTranslation();
  const [chain, setChain] = useState<GoldenChain | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const CHAIN_LABELS = {
    portfolio: t('strategy.portfolios.executiveCommitment'),
    program: t('strategy.programs.program'),
    project: t('projects.project'),
  };

  useEffect(() => {
    const fetchChain = async () => {
      setLoading(true);
      setError(null);
      try {
        const data = (await strategyDashboardApi.getGoldenChain(type, id)) as GoldenChain;
        setChain(data);
      } catch (err: any) {
        setError(err.message || t('strategy.goldenChain.loadError'));
      } finally {
        setLoading(false);
      }
    };

    fetchChain();
  }, [type, id, t]);

  if (loading) {
    return (
      <Card className="p-4">
        <div className="flex items-center justify-center h-16">
          <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-[var(--accent-default)]"></div>
        </div>
      </Card>
    );
  }

  if (error) {
    return (
      <Card className="p-4 bg-[var(--status-danger-subtle)]">
        <div className="flex items-center gap-2 text-[var(--status-danger-text)]">
          <IconAlertCircle className="w-5 h-5" />
          <span>{error}</span>
        </div>
      </Card>
    );
  }

  if (!chain) return null;

  // Check if project is unlinked (no program)
  const isUnlinked = type === 'project' && !chain.program;

  // PMI Standard chain: Portfolio → Program → Project
  const chainItems = [
    { key: 'portfolio', data: chain.portfolio },
    { key: 'program', data: chain.program },
    { key: 'project', data: chain.project },
  ].filter((item) => item.data !== null && item.data !== undefined && item.data.id !== undefined);

  return (
    <Card className="p-4">
      <h3 className="text-sm font-medium text-[var(--text-secondary)] mb-3">{t('strategy.goldenChain.title')}</h3>

      {isUnlinked && showWarning && (
        <div className="mb-4 p-3 rounded-lg bg-[var(--status-warning-subtle)] border border-[var(--status-warning)]/20">
          <div className="flex items-center gap-2 text-[var(--status-warning)]">
            <IconAlertCircle className="w-5 h-5 flex-shrink-0" />
            <div>
              <p className="font-medium text-sm">{t('strategy.goldenChain.projectUnlinked')}</p>
              <p className="text-xs text-[var(--text-secondary)]">{t('strategy.goldenChain.projectUnlinkedDesc')}</p>
            </div>
          </div>
        </div>
      )}

      <div className="flex items-center gap-2 flex-wrap">
        {chainItems.map((item, index) => {
          const Icon = CHAIN_ICONS[item.key as keyof typeof CHAIN_ICONS];
          const colorClass = CHAIN_COLORS[item.key as keyof typeof CHAIN_COLORS];
          const label = CHAIN_LABELS[item.key as keyof typeof CHAIN_LABELS];
          const baseLink = CHAIN_LINKS[item.key as keyof typeof CHAIN_LINKS];

          return (
            <React.Fragment key={item.key}>
              <Link
                to={`${baseLink}/${item.data!.id}`}
                className="flex items-center gap-2 p-2 rounded-lg hover:bg-[var(--surface-muted)] transition-colors"
              >
                <div className={`p-1 rounded-lg ${colorClass}`}>
                  <Icon className="w-4 h-4" />
                </div>
                <div className="min-w-0">
                  <p className="text-xs text-[var(--text-secondary)]">{label}</p>
                  <p className="text-sm font-medium text-[var(--text-primary)] truncate max-w-[150px]">
                    {item.data!.name}
                  </p>
                </div>
              </Link>
              {index < chainItems.length - 1 && (
                <IconChevronLeft className="w-4 h-4 text-[var(--text-secondary)] flex-shrink-0" />
              )}
            </React.Fragment>
          );
        })}
      </div>
    </Card>
  );
};

export default GoldenChainView;
