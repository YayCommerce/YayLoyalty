import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Textarea } from '@/components/ui/textarea';
import { REASON_LABEL, REASON_PLACEHOLDER, REASON_PRESETS } from '../customers-adjust-points.copy';

/** The required internal reason, with quick picks that replace the text. */
export function AdjustPointsReasonField({ reason, onChange }: { reason: string; onChange: (reason: string) => void }) {
  const presets = REASON_PRESETS();
  // An edited text matches no preset, so no tab shows as picked.
  const picked = presets.find((preset) => preset.text === reason)?.label ?? '';

  return (
    <Field>
      <FieldLabel htmlFor="adjust-points-reason">
        {REASON_LABEL()}
        <span className="text-destructive" aria-hidden>
          *
        </span>
      </FieldLabel>
      <FieldContent>
        <Segmented
          shape="square"
          value={picked}
          onValueChange={(label) => {
            const preset = presets.find((item) => item.label === label);
            if (preset) onChange(preset.text);
          }}
          className="w-full"
        >
          {presets.map((preset) => (
            <SegmentedItem key={preset.label} value={preset.label} className="flex-1">
              {preset.label}
            </SegmentedItem>
          ))}
        </Segmented>
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
