import { Fragment, useEffect, useRef, type CSSProperties } from 'react';
import { SCENES, STATIONS, type Scene } from '../factory.config';
import { ACCENTS } from '../scene/materials';
import { useFactory } from '../store';
import { COMPILED, entryAt } from '../timeline';

/** The toolbar's icons, drawn in the current text colour. */
const ICONS = {
  play: <path d="M8 5.5v13l10.5-6.5z" fill="currentColor" />,
  pause: <path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor" />,
  restart: <path d="M12 5a7 7 0 1 1-6.6 4.7M5 4.5v5h5" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />,
};

function Icon( { name }: { name: keyof typeof ICONS } ) {
  return <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">{ ICONS[ name ] }</svg>;
}

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

/** Play/Pause, Restart, the Steps | Code switch, the scene list, the caption and the step tracker; the progress bar follows the playhead without re-rendering. */
export function Toolbar() {
  const paused = useFactory( ( s ) => s.paused );
  const view = useFactory( ( s ) => s.view );
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
        <button type="button" className="icon-btn is-play" data-testid="play" aria-label={ paused ? 'Play' : 'Pause' } title={ paused ? 'Play (Space)' : 'Pause (Space)' } onClick={ () => useFactory.getState().toggle() }>
          <Icon name={ paused ? 'play' : 'pause' } />
        </button>
        <button type="button" className="icon-btn" data-testid="restart" aria-label="Restart" title="Restart" onClick={ () => useFactory.getState().restart() }>
          <Icon name="restart" />
        </button>
        <span className="view-toggle" role="group" aria-label="Bubble shows" data-testid="view-toggle" title="Switch with C">
          { ( [ 'steps', 'code' ] as const ).map( ( v ) => (
            <button key={ v } type="button" data-view={ v } aria-pressed={ v === view } onClick={ () => useFactory.getState().setView( v ) }>
              { 'steps' === v ? 'Steps' : 'Code' }
            </button>
          ) ) }
        </span>
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
