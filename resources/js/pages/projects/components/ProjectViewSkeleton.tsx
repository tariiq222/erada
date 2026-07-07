import React from 'react';
import { Card, CardContent, Skeleton, SkeletonText } from '@shared/ui';

const ProjectViewSkeleton: React.FC = () => (
  <div className="space-y-6">
    <Skeleton width={300} height={20} />
    <div className="flex justify-between">
      <div>
        <Skeleton width={250} height={32} className="mb-2" />
        <Skeleton width={100} height={20} />
      </div>
      <Skeleton width={100} height={40} />
    </div>
    <Card>
      <CardContent className="p-4 sm:p-6">
        <Skeleton width={200} height={24} className="mb-4" />
        <Skeleton height={16} />
      </CardContent>
    </Card>
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {[1, 2, 3, 4].map((i) => (
        <Card key={i}>
          <CardContent className="p-4">
            <Skeleton height={48} />
          </CardContent>
        </Card>
      ))}
    </div>
    <SkeletonText lines={5} />
  </div>
);

export default ProjectViewSkeleton;
