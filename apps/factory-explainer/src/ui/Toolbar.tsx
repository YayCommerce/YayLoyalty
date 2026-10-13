import { Fragment, useEffect, useRef, type CSSProperties } from 'react';
import { SCENES, STATIONS, type Scene } from '../factory.config';
import { ACCENTS } from '../scene/materials';
import { useFactory } from '../store';
import { COMPILED, entryAt } from '../timeline';

/** The scene's steps as chips: done, current and next. Clicking one jumps to that step's work. */
function Tracker( { scene, current }: { scene: Scene; current: number } ) {
  return (
    <nav className="tracker" aria-label="Steps in this scene" data-testid="step-tracker">
      { scene.steps.map( ( step, i ) => {
        const station = STATIONS.find( ( s ) => s.id === ( step.pulse ?? step.at ) );
        const state = i < current ? 'is-done' : i === current ? 'is-current' : 'is-next';
        const work = COMPILED.entries.find( ( e ) => e.scene === scene && e.step === step && 'work' === e.phase );
        return (
          <Fragment key={ i }>
            { i > 0 && <span className="arrow" aria-hidden="true">→</span> }
            <button
              type="button"
              className={ state }
              style={ { '--accent': station ? ACCENTS[ station.type ] : undefined } as CSSProperties }
              aria-current={ i === current ? 'step' : undefined }
              onClick={ () => work && useFactory.getState().seekTime( work.start ) }
            >
              <span className="num">{ i < current ? '✓' : i + 1 }</span>
              { station?.label ?? step.label }
            </button>
          </Fragment>
        );
      } ) }
    </nav>
  );
}

/** Play/Pause, Restart, the scene list, the caption and the step tracker; the progress bar follows the playhead without re-rendering. */
export function Toolbar() {
  const paused = useFactory( ( s ) => s.paused );
  const entry = useFactory( ( s ) => entryAt( COMPILED.entries, s.time ) );
  const bar = useRef<HTMLSpanElement>( null );

  useEffect( () => useFactory.subscribe( ( s ) => {
    if ( bar.current ) {
      bar.current.style.width = `${ ( s.time / s.duration * 100 ).toFixed( 2 ) }%`;
    }
  } ), [] );

  return (
    <header className="toolbar" data-testid="toolbar">
      <div className="controls">
        <span className="brand">Points Factory</span>
        <button type="button" data-testid="play" onClick={ () => useFactory.getState().toggle() }>{ paused ? 'Play' : 'Pause' }</button>
        <button type="button" data-testid="restart" onClick={ () => useFactory.getState().restart() }>Restart</button>
        <nav className="scenes" aria-label="Scenes">
          { SCENES.map( ( scene ) => (
            <button key={ scene.id } type="button" data-testid={ scene.id } aria-current={ scene.id === entry.scene.id ? 'step' : undefined } onClick={ () => useFactory.getState().seekScene( scene.id ) }>
              { scene.caption }
            </button>
          ) ) }
        </nav>
      </div>
      <div className="caption" data-testid="caption" aria-live="polite">
        <span className="scene-title">{ entry.scene.caption }</span>
        { entry.step && <code>{ entry.step.label }</code> }
        <Tracker scene={ entry.scene } current={ 'intro' === entry.phase ? -1 : 'hold' === entry.phase ? entry.scene.steps.length : entry.step ? entry.scene.steps.indexOf( entry.step ) : -1 } />
        <div className="progress" aria-hidden="true"><span ref={ bar } /></div>
      </div>
    </header>
  );
}
