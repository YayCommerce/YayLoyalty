import { cn } from '@/lib/utils';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EditorFieldSkeleton, EditorSectionSkeleton } from '@/components/ui/custom/editor-section';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';

/**
 * The editor's loading state, for RewardEditor's fetch and RewardCreator's
 * duplicate-source fetch. It copies a catalog reward's page (back link,
 * header, the three numbered sections and the rail), since the type is not
 * known until the row arrives.
 */
export function RewardEditorSkeleton() {
  return (
    <>
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Skeleton tone="page" className="h-4 w-20" />
      </div>

      <PageLayout
        className="max-w-6xl"
        title={
          <span className="flex items-center gap-2">
            <Skeleton tone="page" className="h-7 w-56" />
            <Skeleton tone="page" className="h-5 w-16 rounded-full" />
          </span>
        }
        description={<Skeleton tone="page" className="h-5 w-24 rounded-full" />}
      >
        <div className="flex flex-col items-start gap-5 lg:flex-row">
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            <EditorSectionSkeleton contentClassName="gap-5">
              <FieldWithDescription inputClassName="w-full" labelClassName="w-12" />
            </EditorSectionSkeleton>

            <EditorSectionSkeleton contentClassName="gap-5">
              <FieldWithDescription labelClassName="w-32" />
              <FieldWithDescription labelClassName="w-28" />
              <FieldWithDescription labelClassName="w-28" inputClassName="mt-2 h-5 w-9 rounded-full" />
            </EditorSectionSkeleton>

            <EditorSectionSkeleton contentClassName="gap-5">
              <FieldWithDescription labelClassName="w-14" descriptionLines={2} />
            </EditorSectionSkeleton>
          </div>

          <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  <Skeleton className="h-4 w-36" />
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <EditorFieldSkeleton labelClassName="w-12">
                  <Skeleton className="h-9 w-64 rounded-full" />
                </EditorFieldSkeleton>
                {[0, 1].map((index) => (
                  <EditorFieldSkeleton key={index} labelClassName="w-10">
                    <Skeleton className="h-9 w-48" />
                  </EditorFieldSkeleton>
                ))}
                <Skeleton className="h-12 w-full rounded-lg" />
              </CardContent>
            </Card>

            <div className="flex flex-col gap-2">
              <Skeleton tone="page" className="h-9 w-full" />
              <Skeleton tone="page" className="h-9 w-full" />
              <div className="flex items-center justify-center gap-4 py-2.5">
                <Skeleton tone="page" className="h-4 w-24" />
                <Skeleton tone="page" className="h-4 w-12" />
              </div>
            </div>
          </div>
        </div>
      </PageLayout>
    </>
  );
}

/** RewardField's footprint: label, control, then its description line(s). */
function FieldWithDescription({
  labelClassName,
  inputClassName = 'w-40',
  descriptionLines = 1,
}: {
  labelClassName: string;
  inputClassName?: string;
  descriptionLines?: number;
}) {
  return (
    <EditorFieldSkeleton labelClassName={labelClassName}>
      <Skeleton className={cn('h-9', inputClassName)} />
      {Array.from({ length: descriptionLines }, (_, line) => (
        <Skeleton key={line} className={line === descriptionLines - 1 ? 'h-3 w-2/3' : 'h-3 w-full'} />
      ))}
    </EditorFieldSkeleton>
  );
}
