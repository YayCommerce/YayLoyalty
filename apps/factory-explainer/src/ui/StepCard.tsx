import { useFactory } from '../store';
import { COMPILED, entryAt } from '../timeline';

/** The current call, what it checks or writes, and what comes out; shown while a station works. Hovering it holds the loop. */
export function StepCard() {
  const entry = useFactory( ( s ) => entryAt( COMPILED.entries, s.time ) );
  const card = entry.step && 'work' === entry.phase ? entry.step.card : null;
  const hold = useFactory( ( s ) => s.hoverHold );

  return (
    <aside className={ `step-card${ card ? ' is-on' : '' }` } data-testid="step-card" aria-hidden={ ! card } onPointerEnter={ () => hold( true ) } onPointerLeave={ () => hold( false ) }>
      { card && (
        <>
          <code className="fn">{ card.fn }</code>
          <ul>
            { card.lines.map( ( line ) => (
              <li key={ line } className={ line.startsWith( '✓' ) ? 'pass' : line.startsWith( '✗' ) ? 'fail' : undefined }>{ line }</li>
            ) ) }
          </ul>
          <div className="out">→ { card.out }</div>
        </>
      ) }
    </aside>
  );
}
