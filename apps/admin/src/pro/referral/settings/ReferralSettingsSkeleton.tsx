import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EditorFieldSkeleton, EditorSectionSkeleton } from '@/components/ui/custom/editor-section';
import { Skeleton } from '@/components/ui/skeleton';

/** A number field's loading state: label, the input with its unit, and a hint line. */
function NumberFieldSkeleton() {
  return (
    <EditorFieldSkeleton labelClassName="w-32">
      <Skeleton className="h-8 w-44" />
      <Skeleton className="h-3 w-2/3" />
    </EditorFieldSkeleton>
  );
}

/** Referral → Settings loading state, in the form's own grid so nothing jumps when the form mounts. */
export function ReferralSettingsSkeleton() {
  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
      <div className="flex min-w-0 flex-col gap-4">
        <Card>
          <CardContent className="flex items-center justify-between gap-4">
            <div className="flex flex-1 flex-col gap-1.5">
              <Skeleton className="h-4 w-36" />
              <Skeleton className="h-3 w-2/3" />
            </div>
            <Skeleton className="h-6 w-11 rounded-full" />
          </CardContent>
        </Card>

        <EditorSectionSkeleton>
          <EditorFieldSkeleton labelClassName="w-28">
            <Skeleton className="h-8 w-44" />
            <div className="flex gap-4">
              <Skeleton className="h-4 w-16" />
              <Skeleton className="h-4 w-20" />
            </div>
          </EditorFieldSkeleton>
          <NumberFieldSkeleton />
          <NumberFieldSkeleton />
        </EditorSectionSkeleton>

        {[0, 1].map((index) => (
          <EditorSectionSkeleton key={index}>
            <NumberFieldSkeleton />
          </EditorSectionSkeleton>
        ))}

        <EditorSectionSkeleton>
          <div className="flex items-center gap-2">
            <Skeleton className="size-4" />
            <Skeleton className="h-4 w-56" />
          </div>
          <Skeleton className="h-3 w-3/4" />
        </EditorSectionSkeleton>

        <EditorSectionSkeleton>
          <NumberFieldSkeleton />
        </EditorSectionSkeleton>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            <Skeleton className="h-4 w-28" />
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-3 rounded-md border p-4">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-5/6" />
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-9 w-full" />
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
