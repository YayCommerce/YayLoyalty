interface NpsScaleProps {
  value: number | null;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
}

const NPS_BANDS: { upTo: number; color: string }[] = [
  { upTo: 2, color: '#ef4444' },
  { upTo: 4, color: '#f97316' },
  { upTo: 6, color: '#eab308' },
  { upTo: Infinity, color: '#22c55e' },
];

function colorForValue(n: number): string {
  return NPS_BANDS.find((band) => n <= band.upTo)?.color ?? '#22c55e';
}

export function NpsScale({ value, onChange, min = 1, max = 10 }: NpsScaleProps) {
  const numbers = Array.from({ length: max - min + 1 }, (_, i) => min + i);

  return (
    <div className="relative flex items-center justify-between py-1">
      <div
        className="pointer-events-none absolute top-1/2 h-0.75 -translate-y-1/2 rounded-full"
        style={{
          left: '14px',
          right: '14px',
          background:
            'linear-gradient(to right, #ef4444 0%, #ef4444 15%, #f97316 30%, #f97316 40%, #eab308 50%, #eab308 60%, #22c55e 70%, #22c55e 100%)',
        }}
      />
      {numbers.map((n) => {
        const color = colorForValue(n);
        const selected = value === n;
        return (
          <button
            key={n}
            type="button"
            className="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 text-[0.8em] font-bold transition-colors duration-150"
            style={{
              borderColor: color,
              backgroundColor: selected ? color : '#fff',
              color: selected ? '#fff' : 'transparent',
            }}
            onClick={() => onChange(n)}
          >
            {n}
          </button>
        );
      })}
    </div>
  );
}
