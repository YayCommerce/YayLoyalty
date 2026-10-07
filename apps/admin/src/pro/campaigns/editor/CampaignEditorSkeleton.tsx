import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
            <SectionSkeleton>
              <LabelledSkeleton labelClassName="w-10">
                <Skeleton className="h-9 w-full" />
              </LabelledSkeleton>
              <Skeleton className="h-3 w-3/4" />
            </SectionSkeleton>

            <SectionSkeleton>
              <div className="grid gap-3 sm:grid-cols-2">
                <Skeleton className="h-28 w-full" />
                <Skeleton className="h-28 w-full" />
              </div>
              <LabelledSkeleton labelClassName="w-20">
                <Skeleton className="h-9 w-40" />
              </LabelledSkeleton>
              <Skeleton className="h-8 w-28" />
            </SectionSkeleton>

            <SectionSkeleton>
              <Skeleton className="h-28 w-full" />
              <Skeleton className="h-8 w-36" />
              <Skeleton className="h-3 w-2/3" />
            </SectionSkeleton>
          </div>

          <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  <Skeleton className="h-4 w-36" />
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <LabelledSkeleton labelClassName="w-12">
                  <Skeleton className="h-9 w-56" />
                </LabelledSkeleton>
                {[0, 1].map((index) => (
                  <LabelledSkeleton key={index} labelClassName="w-10">
                    <div className="flex items-center gap-2">
                      <Skeleton className="h-9 w-40" />
                      <Skeleton className="h-9 w-28" />
                    </div>
                  </LabelledSkeleton>
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

/** CampaignEditorSections' numbered-card shell with the badge and title greyed out. */
function SectionSkeleton({ children }: { children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2.5 text-base">
          <Skeleton className="size-5.5 shrink-0 rounded-full" />
          <Skeleton className="h-4 w-24" />
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">{children}</CardContent>
    </Card>
  );
}

/** A vertical Field: label line above its control(s). */
function LabelledSkeleton({ labelClassName, children }: { labelClassName: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Skeleton className={`h-3.5 ${labelClassName}`} />
      {children}
    </div>
  );
}
