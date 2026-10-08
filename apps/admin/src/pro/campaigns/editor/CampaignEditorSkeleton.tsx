import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EditorFieldSkeleton, EditorSectionSkeleton } from '@/components/ui/custom/editor-section';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';

/**
 * The editor's loading state, shared by CampaignEditor's fetch and
 * CampaignCreator's duplicate-source fetch. Each block copies the loaded
 * page's own footprint (breadcrumb, header, the three numbered sections and
 * the rail) so nothing jumps when the form mounts.
 */
export function CampaignEditorSkeleton() {
  return (
    <>
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Skeleton tone="page" className="h-4 w-20" />
        <span aria-hidden className="text-muted-foreground">
          ·
        </span>
        <Skeleton tone="page" className="h-4 w-40" />
      </div>

      <PageLayout
        className="max-w-6xl"
        title={
          <span className="flex items-center gap-2">
            <Skeleton tone="page" className="h-7 w-64" />
            <Skeleton tone="page" className="h-5 w-16 rounded-full" />
          </span>
        }
        description={
          <span className="flex items-center gap-2">
            <Skeleton tone="page" className="h-5 w-24 rounded-full" />
            <span aria-hidden>·</span>
            <Skeleton tone="page" className="h-3.5 w-28" />
          </span>
        }
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
              <div className="grid gap-3 sm:grid-cols-2">
                <Skeleton className="h-28 w-full" />
                <Skeleton className="h-28 w-full" />
              </div>
              <EditorFieldSkeleton labelClassName="w-20">
                <Skeleton className="h-9 w-40" />
              </EditorFieldSkeleton>
              <Skeleton className="h-8 w-28" />
            </EditorSectionSkeleton>

            <EditorSectionSkeleton>
              <Skeleton className="h-28 w-full" />
              <Skeleton className="h-8 w-36" />
              <Skeleton className="h-3 w-2/3" />
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
              <Skeleton tone="page" className="mx-auto h-4 w-16" />
            </div>
          </div>
        </div>
      </PageLayout>
    </>
  );
}
