import { useEffect, useRef, type CSSProperties } from 'react';
import * as THREE from 'three';
import { STATIONS, type Station } from '../factory.config';
import { ACCENTS } from '../scene/materials';
import { rig } from '../scene/rig';
import { PLINTH } from '../scene/stations/Kit';
import { reduced, useFactory } from '../store';
import { activeStation, cardSteps, COMPILED, entryAt, revealCount } from '../timeline';

const GAP = 16; // px between the bubble and its anchor, where the arrow sits
const EDGE = 12; // px the bubble keeps from the viewport's sides

/** The working entry at `time`, or null between stations. */
function working( time: number ) {
  const entry = entryAt( COMPILED.entries, time );
  return entry.step && 'work' === entry.phase ? entry : null;
}

/** A world point in CSS pixels on the page, through the live camera. */
function toScreen( p: THREE.Vector3 ): { x: number; y: number } | null {
  const { camera, canvas } = rig;
  if ( ! camera || ! canvas ) {
    return null;
  }
  const box = canvas.getBoundingClientRect();
  const v = p.clone().project( camera );
  return { x: box.left + ( v.x + 1 ) / 2 * box.width, y: box.top + ( 1 - v.y ) / 2 * box.height };
}

/** The bubble's anchors: over the top face's back corner, or beside its right corner. */
function anchors( s: Station ) {
  const [ w, d, h ] = s.size;
  return {
    top: new THREE.Vector3( s.position[ 0 ] - w / 2, PLINTH + h + s.labelHeight, s.position[ 1 ] - d / 2 ),
    side: new THREE.Vector3( s.position[ 0 ] + w / 2, PLINTH + h, s.position[ 1 ] - d / 2 ),
  };
}

/**
 * The step card as a speech bubble pointing at the working station. Its steps reveal one by one with the playhead,
 * so a seek shows exactly what playback shows. When it would reach the toolbar, it moves beside the machine instead.
 */
export function StepBubble() {
  const index = useFactory( ( s ) => {
    const e = working( s.time );
    return e ? COMPILED.entries.indexOf( e ) : -1;
  } );
  const shown = useFactory( ( s ) => {
    const e = working( s.time );
    return e?.step ? ( reduced ? cardSteps( e.step ).length : revealCount( e.step, ( s.time - e.start ) / ( e.end - e.start ) ) ) : 0;
  } );
  const bubble = useRef<HTMLDivElement>( null );
  const entry = index >= 0 ? COMPILED.entries[ index ] : null;
  const station = entry ? STATIONS.find( ( st ) => st.id === activeStation( entry ) ) : undefined;

  // Follow the station on screen every frame: place, flip and clamp the bubble without re-rendering.
  useEffect( () => {
    if ( ! station ) {
      return;
    }
    const { top, side } = anchors( station );
    let frame = 0;
    const place = () => {
      frame = requestAnimationFrame( place );
      const el = bubble.current;
      const above = toScreen( top );
      if ( ! el || ! above ) {
        return;
      }
      const toolbar = document.querySelector( '[data-testid="toolbar"]' )?.getBoundingClientRect().bottom ?? 0;
      const beside = above.y - GAP - el.offsetHeight < toolbar + 8;
      el.classList.toggle( 'is-side', beside );
      if ( beside ) {
        // To the right of the machine, arrow pointing left, kept below the toolbar.
        const at = toScreen( side ) ?? above;
        const y = Math.max( toolbar + 8, at.y - el.offsetHeight / 2 );
        el.style.left = `${ at.x + GAP + el.offsetWidth / 2 }px`;
        el.style.top = `${ y }px`;
        el.style.setProperty( '--arrow-y', `${ Math.min( el.offsetHeight - 20, Math.max( 20, at.y - y ) ).toFixed( 1 ) }px` );
        return;
      }
      const half = el.offsetWidth / 2;
      const shift = Math.min( 0, window.innerWidth - EDGE - ( above.x + half ) ) + Math.max( 0, EDGE - ( above.x - half ) );
      el.style.left = `${ above.x + shift }px`;
      el.style.top = `${ above.y - GAP - el.offsetHeight }px`;
      el.style.setProperty( '--shift', `${ shift.toFixed( 1 ) }px` );
    };
    place();
    return () => cancelAnimationFrame( frame );
  }, [ station ] );

  const step = entry?.step;
  const steps = step ? cardSteps( step ) : [];
  const sceneSteps = entry?.scene.steps ?? [];
  const hold = useFactory.getState().hoverHold;

  return (
    <aside
      ref={ bubble }
      className={ `step-bubble${ step ? ' is-on' : '' }` }
      data-testid="step-card"
      aria-hidden={ ! step }
      style={ { '--accent': station ? ACCENTS[ station.type ] : undefined } as CSSProperties }
      onPointerEnter={ () => hold( true ) }
      onPointerLeave={ () => hold( false ) }
    >
      { step && station && (
        <div key={ index } className="bubble-body">
          <header>
            <span className="role">{ station.role }</span>
            <span className="station">{ station.label }</span>
            <span className="count">Step { sceneSteps.indexOf( step ) + 1 } of { sceneSteps.length }</span>
          </header>
          <code className="fn">{ step.card.fn }</code>
          <ol>
            { steps.slice( 0, shown ).map( ( line, i ) => {
              const result = i === steps.length - 1;
              const kind = line.startsWith( '✓' ) ? ' pass' : line.startsWith( '✗' ) ? ' fail' : '';
              return (
                <li key={ line } className={ `${ i === shown - 1 ? 'is-new' : 'is-done' }${ result ? ' result' : '' }${ kind }` }>
                  <span className="badge">{ result ? '→' : i + 1 }</span>
                  <span className="text">{ line }</span>
                </li>
              );
            } ) }
          </ol>
        </div>
      ) }
    </aside>
  );
}
