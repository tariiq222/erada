import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { strategyDashboardApi } from '@entities/strategy';
import { IconRocket, IconAlertCircle } from '@tabler/icons-react';

interface ChainItem {
  id: number;
  code: string;
  name: string;
  status?: string;
}

interface GoldenChain {
  portfolio: ChainItem | null;
  program: ChainItem | null;
  project: ChainItem | null;
}

interface GoldenChainTagProps {
  type: 'portfolio' | 'program' | 'project';
  id: number;
}

/**
 * Compact pill that shows the strategic linkage of an entity in the
 * PMI Standard Golden Chain (Portfolio -> Program -> Project).
 *
 * - Linked (project with a program): accent pill linking to the program.
 * - Unlinked (project with no program): non-clickable warning pill.
 * - Loading / error / non-project types: render null so the header
 *   stays clean and avoids layout jumps.
 */
const GoldenChainTag: React.FC<GoldenChainTagProps> = ({ type, id }) => {
  const { t } = useTranslation();
  const [chain, setChain] = useState<GoldenChain | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const fetchChain = async () => {
      setLoading(true);
      setError(null);
      try {
        const data = (await strategyDashboardApi.getGoldenChain(type, id)) as GoldenChain;
        if (!cancelled) setChain(data);
      } catch (err: unknown) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'loadError');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    fetchChain();
    return () => {
      cancelled = true;
    };
  }, [type, id]);

  if (loading || error || !chain) return null;

  // Only projects get a strategic-linkage pill; other types render nothing.
  if (type !== 'project') return null;

  // Linked: project has a program -> accent link pill.
  if (chain.program) {
    const program = chain.program;
    const portfolioPart = chain.portfolio?.name ? `${chain.portfolio.name} - ` : '';
    const title = `${portfolioPart}${program.name}`;
    return (
      <Link
        to={`/strategy/programs/${program.id}`}
        className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--accent-subtle)] text-[var(--accent-text)] border border-[var(--accent-muted)] hover:opacity-90 transition-opacity"
        title={title}
      >
        <IconRocket className="h-3.5 w-3.5" />
        <span className="font-medium max-w-[160px] truncate">{program.name}</span>
      </Link>
    );
  }

  // Unlinked: project has no program -> non-clickable warning pill.
  return (
    <span
      className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)] border border-[var(--status-warning)]/20"
      title={t('strategy.goldenChain.projectUnlinkedDesc')}
    >
      <IconAlertCircle className="h-3.5 w-3.5" />
      <span className="font-medium">{t('strategy.goldenChain.unlinkedTag')}</span>
    </span>
  );
};

export default GoldenChainTag;