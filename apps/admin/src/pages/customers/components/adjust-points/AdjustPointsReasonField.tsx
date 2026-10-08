import { Button } from '@/components/ui/button';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { REASON_LABEL, REASON_PLACEHOLDER, REASON_PRESETS } from '../customers-adjust-points.copy';

/** The required internal reason, with quick picks that replace the text. */
export function AdjustPointsReasonField({ reason, onChange }: { reason: string; onChange: (reason: string) => void }) {
  return (
    <Field>
      <FieldLabel htmlFor="adjust-points-reason">
        {REASON_LABEL()}
        <span className="text-destructive" aria-hidden>
          *
        </span>
      </FieldLabel>
      <FieldContent>
        <div className="flex flex-wrap gap-1.5">
          {REASON_PRESETS().map((preset) => (
            <Button
              key={preset.label}
              variant={reason === preset.text ? 'primary-outline' : 'outline'}
              size="sm"
              className="h-7"
              onClick={() => onChange(preset.text)}
            >
              {preset.label}
            </Button>
          ))}
        </div>
        <Textarea
          id="adjust-points-reason"
          required
          value={reason}
          onChange={(event) => onChange(event.target.value)}
          placeholder={REASON_PLACEHOLDER()}
        />
      </FieldContent>
    </Field>
  );
}
