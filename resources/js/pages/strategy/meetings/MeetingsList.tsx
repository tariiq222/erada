import React from 'react';
import { useCan } from '@shared/api/access';
import {
  MeetingsHeader, MeetingsFilters, MeetingsTable, useMeetingsList,
} from './list';

const MeetingsList: React.FC = () => {
  const { meetings, loading, pagination, filters, setFilter, resetFilters } = useMeetingsList();

  const canCreate = useCan('meetings.create');
  const canEdit = useCan('meetings.edit');

  return (
    <div className="space-y-4 sm:space-y-6">
      <MeetingsHeader canCreate={canCreate} />
      <MeetingsFilters filters={filters} onChange={setFilter} onReset={resetFilters} />
      <MeetingsTable
        meetings={meetings}
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

export default MeetingsList;
