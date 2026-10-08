import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EditorFieldSkeleton, EditorSectionSkeleton } from '@/components/ui/custom/editor-section';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';

/**
 * The editor's loading state, shared by CampaignEditor's fetch and
 * CampaignCreator's duplicate-source fetch. Each block copies the loaded
 * page's own footprint (back link, header, the three numbered sections and
 * the rail) so nothing jumps when the form mounts.
 */
export function CampaignEditorSkeleton() {
  return (
    <>
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Skeleton tone="page" className="h-4 w-24" />
      </div>

      <PageLayout
        className="max-w-6xl"
        title={
          <span className="flex items-center gap-2">
            <Skeleton tone="page" className="h-7 w-64" />
            <Skeleton tone="page" className="h-5 w-16 rounded-full" />
          </span>
        }
        actions={<Skeleton tone="page" className="h-9 w-36" />}
        description={<Skeleton tone="page" className="h-5 w-24 rounded-full" />}
      >
        <div className="flex flex-col items-start gap-5 lg:flex-row">
          <div className="flex min-w-0 flex-1 flex-col gap-4">
            <EditorSectionSkeleton>
              <EditorFieldSkeleton labelClassName="w-10">
                <Skeleton className="h-9 w-full" />
              </EditorFieldSkeleton>
              <Skeleton className="h-3 w-3/4" />
            </EditorSectionSkeleton>

            <EditorSectionSkeleton>
              <EditorFieldSkeleton labelClassName="w-10">
                <div className="flex items-center gap-2">
                  <Skeleton className="h-9 w-44" />
                  <Skeleton className="h-4 w-36" />
                </div>
                <Skeleton className="h-3 w-48" />
              </EditorFieldSkeleton>
              <Skeleton className="h-px w-full" />
              <div className="flex flex-col gap-1">
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-3 w-3/4" />
              </div>
            </EditorSectionSkeleton>

            <EditorSectionSkeleton>
              <Skeleton className="h-28 w-full" />
              <div className="flex flex-col gap-1.5">
                <Skeleton className="h-8 w-36" />
                <Skeleton className="h-3 w-44" />
              </div>
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
                  <Skeleton className="h-9 w-56" />
                </EditorFieldSkeleton>
                {[0, 1].map((index) => (
                  <EditorFieldSkeleton key={index} labelClassName="w-10">
                    <div className="flex items-center gap-2">
                      <Skeleton className="h-9 w-40" />
                      <Skeleton className="h-9 w-28" />
                    </div>
                  </EditorFieldSkeleton>
                ))}
                <Skeleton className="h-12 w-full" />
              </CardContent>
            </Card>

            <div className="flex flex-col gap-2">
              <Skeleton tone="page" className="h-9 w-full" />
              <Skeleton tone="page" className="h-9 w-full" />
              <div className="flex items-center justify-center gap-4 py-2.5">
                <Skeleton tone="page" className="h-4 w-28" />
                <Skeleton tone="page" className="h-4 w-12" />
              </div>
            </div>
          </div>
        </div>
      </PageLayout>
    </>
  );
}
