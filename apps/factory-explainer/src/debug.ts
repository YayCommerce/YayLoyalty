import * as THREE from 'three';
import { BOXES, STATIONS, type PackageId, type Scene, type StationId } from './factory.config';
import { rig } from './scene/rig';
import { useFactory } from './store';
import { COMPILED, boxPose, entryAt } from './timeline';

type Rect = { x: number; y: number; width: number; height: number };

/** Dev-only handle for the screenshot harness and manual checks. */
export interface FactoryDebug {
  seekScene( id: Scene[ 'id' ] ): void;
  seekTime( t: number ): void;
  pause(): void;
  state(): { time: number; paused: boolean; hoverHeld: boolean; selected: StationId | null; entry: string };
  entries(): { start: number; end: number; scene: string; phase: string; at: StationId | null; gate: string | null }[];
  labelRects(): Record<string, Rect>;
  machineRects(): Record<string, Rect>;
  boxPoint( id: PackageId ): { x: number; y: number } | null;
}

/** Projects world points to CSS pixels relative to the page. */
function project( points: THREE.Vector3[] ): Rect | null {
  const { camera, canvas } = rig;
  if ( ! camera || ! canvas ) {
    return null;
  }
  const box = canvas.getBoundingClientRect();
  const xs: number[] = [];
  const ys: number[] = [];
  for ( const p of points ) {
    const v = p.clone().project( camera );
    xs.push( box.left + ( v.x + 1 ) / 2 * box.width );
    ys.push( box.top + ( 1 - v.y ) / 2 * box.height );
  }
  const x = Math.min( ...xs );
  const y = Math.min( ...ys );
  return { x, y, width: Math.max( ...xs ) - x, height: Math.max( ...ys ) - y };
}

const handle: FactoryDebug = {
  seekScene: ( id ) => useFactory.getState().seekScene( id ),
  seekTime: ( t ) => useFactory.getState().seekTime( t ),
  pause: () => useFactory.setState( { paused: true, hoverHeld: false } ),
  state: () => {
    const s = useFactory.getState();
    const e = entryAt( COMPILED.entries, s.time );
    return { time: s.time, paused: s.paused, hoverHeld: s.hoverHeld, selected: s.selected, entry: `${ e.scene.id } ${ e.phase } ${ e.step?.card.fn ?? '' }` };
  },
  entries: () => COMPILED.entries.map( ( e ) => ( { start: e.start, end: e.end, scene: e.scene.id, phase: e.phase, at: e.step ? e.step.pulse ?? e.step.at ?? null : null, gate: e.step?.gate ?? null } ) ),
  labelRects: () => {
    const out: Record<string, Rect> = {};
    document.querySelectorAll<HTMLElement>( '.station-label[data-station]' ).forEach( ( el ) => {
      const r = el.getBoundingClientRect();
      out[ el.dataset.station! ] = { x: r.x, y: r.y, width: r.width, height: r.height };
    } );
    return out;
  },
  machineRects: () => {
    const out: Record<string, Rect> = {};
    for ( const s of STATIONS ) {
      const [ w, d, h ] = s.size;
      const corners: THREE.Vector3[] = [];
      for ( const dx of [ -w / 2, w / 2 ] ) {
        for ( const dz of [ -d / 2, d / 2 ] ) {
          for ( const y of [ 0, h + 0.2 ] ) {
            corners.push( new THREE.Vector3( s.position[ 0 ] + dx, y, s.position[ 1 ] + dz ) );
          }
        }
      }
      const r = project( corners );
      if ( r ) {
        out[ s.id ] = r;
      }
    }
    return out;
  },
  boxPoint: ( id ) => {
    if ( ! BOXES.some( ( b ) => b.id === id ) ) {
      return null;
    }
    const p = boxPose( id, useFactory.getState().time ).position;
    const r = project( [ new THREE.Vector3( p[ 0 ], p[ 1 ] + 0.25, p[ 2 ] ) ] );
    return r ? { x: r.x, y: r.y } : null;
  },
};

( window as unknown as { __factory: FactoryDebug } ).__factory = handle;
