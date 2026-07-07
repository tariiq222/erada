import React from 'react';
import { useCan } from '@shared/api/access';
import {
  RecommendationsHeader,
  RecommendationsFilters,
  RecommendationsTable,
  useRecommendationsList,
} from './list';

const RecommendationsList: React.FC = () => {
  const { recommendations, loading, pagination, filters, setFilter, resetFilters } =
    useRecommendationsList();

  const canCreate = useCan('recommendations.create');
  const canEdit = useCan('recommendations.edit');

  return (
    <div className="space-y-4 sm:space-y-6">
      <RecommendationsHeader canCreate={canCreate} />
      <RecommendationsFilters filters={filters} onChange={setFilter} onReset={resetFilters} />
      <RecommendationsTable
        recommendations={recommendations}
        loading={loading}
        canEdit={canEdit}
        currentPage={pagination.currentPage}
        lastPage={pagination.lastPage}
        total={pagination.total}
        onPageChange={(p) => setFilter('page', p)}
      />
    </div>
  );
};

export default RecommendationsList;
