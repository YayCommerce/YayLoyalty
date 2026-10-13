import { useEffect, useRef } from 'react';
import { SCENES } from '../factory.config';
import { useFactory } from '../store';
import { COMPILED, entryAt } from '../timeline';

/** Play/Pause, Restart, the scene list and the caption; the progress bar follows the playhead without re-rendering. */
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
        <div className="progress" aria-hidden="true"><span ref={ bar } /></div>
      </div>
    </header>
  );
}
