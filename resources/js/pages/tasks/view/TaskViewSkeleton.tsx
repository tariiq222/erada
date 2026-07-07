import React from 'react';
import { Card, CardContent, Skeleton, SkeletonText } from '@shared/ui';

const TaskViewSkeleton: React.FC = () => (
  <div className="space-y-6">
    <Skeleton width={200} height={20} />
    <div className="bg-[var(--surface-base)] rounded-2xl p-6">
      <div className="flex justify-between">
        <div>
          <Skeleton width={300} height={32} className="mb-2" />
          <Skeleton width={150} height={20} />
        </div>
        <Skeleton width={100} height={40} />
      </div>
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 space-y-6">
        <Card>
          <CardContent className="p-6">
            <SkeletonText lines={4} />
          </CardContent>
        </Card>
      </div>
      <div className="space-y-6">
        <Card>
          <CardContent className="p-6">
            <SkeletonText lines={8} />
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
);

export default TaskViewSkeleton;
