import * as THREE from 'three';
import { ACTORS, BELT_Y, BOXES, CONSOLE_Y, CONVEYORS, SCENES, TIMING, conveyorPoints, type Conveyor, type PackageId, type Scene, type Step, type StationId, type Vec2 } from './factory.config';

export interface Entry { start: number; end: number; scene: Scene; step: Step | null; phase: 'intro' | 'walk' | 'ride' | 'work' | 'hold'; }
export type Tint = 'earn' | 'adjust' | 'rejected' | 'refund';
type V3 = [ number, number, number ];

/** A stretch of a box's or actor's motion: along a belt curve, or eased between two points. */
interface Segment { t0: number; t1: number; from: V3; to: V3; curve?: THREE.CatmullRomCurve3; walking?: boolean; }

/** Seconds to slide a box onto a belt start, or off a belt end to its settle spot. */
const SLIDE = 0.8;

/** How far before and after a belt corner the curve starts turning. */
const CORNER = 0.45;
const ease = ( u: number ) => ( u < 0.5 ? 4 * u * u * u : 1 - Math.pow( -2 * u + 2, 3 ) / 2 );
const clamp01 = ( u: number ) => Math.min( 1, Math.max( 0, u ) );

/** Belt rides: ramp up and down over 0.4 s at most a quarter of the ride, constant speed between. */
function ride( u: number, seconds: number ): number {
  const r = Math.min( 0.25, 0.4 / seconds );
  const v = 1 / ( 1 - r );
  if ( u < r ) {
    return 0.5 * v * u * u / r;
  }
  if ( u > 1 - r ) {
    return 1 - 0.5 * v * ( 1 - u ) * ( 1 - u ) / r;
  }
  return v * ( u - r / 2 );
}

/** A belt's curve at belt height, cutting each corner by CORNER so the turn is rounded and short. */
export function beltCurve( c: Conveyor ): THREE.CatmullRomCurve3 {
  const pts = conveyorPoints( c );
  const out: THREE.Vector3[] = [];
  pts.forEach( ( p, i ) => {
    if ( 0 === i || pts.length - 1 === i ) {
      out.push( new THREE.Vector3( p[ 0 ], BELT_Y, p[ 1 ] ) );
      return;
    }
    for ( const n of [ pts[ i - 1 ], pts[ i + 1 ] ] ) {
      const d = Math.hypot( n[ 0 ] - p[ 0 ], n[ 1 ] - p[ 1 ] );
      const r = Math.min( CORNER, d / 3 ) / d;
      out.push( new THREE.Vector3( p[ 0 ] + ( n[ 0 ] - p[ 0 ] ) * r, BELT_Y, p[ 1 ] + ( n[ 1 ] - p[ 1 ] ) * r ) );
    }
  } );
  return new THREE.CatmullRomCurve3( out, false, 'centripetal', 0.5 );
}

const CURVES = new Map( CONVEYORS.map( ( c ) => [ c.id, beltCurve( c ) ] ) );
const v3 = ( p: THREE.Vector3 ): V3 => [ p.x, p.y, p.z ];
const near = ( a: V3, b: V3 ) => Math.hypot( a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ], a[ 2 ] - b[ 2 ] ) < 0.05;

function boxHome( id: PackageId ): V3 {
  const a = ACTORS.find( ( x ) => x.id === id )!;
  return [ a.dock[ 0 ], a.console ? CONSOLE_Y : BELT_Y, a.dock[ 1 ] ];
}

interface Compiled {
  entries: Entry[];
  duration: number;
  sceneStart: Record<Scene[ 'id' ], number>;
  boxes: Record<PackageId, { appear: number; segments: Segment[]; tints: [ number, Tint ][]; tags: [ number, string ][] }>;
  actors: Record<PackageId, Segment[]>;
  lifts: number[];
  denies: number[];
  presses: number[];
  dialRuns: [ number, number ][];
}

export function compileTimeline( scenes: Scene[] = SCENES ): Compiled {
  const { INTRO, WALK, HOP, SPEED, WORK, HOLD } = TIMING;
  const entries: Entry[] = [];
  const sceneStart = {} as Compiled[ 'sceneStart' ];
  const boxes = {} as Compiled[ 'boxes' ];
  const actors = {} as Compiled[ 'actors' ];
  const at = {} as Record<PackageId, V3>;
  for ( const b of BOXES ) {
    at[ b.id ] = boxHome( b.id );
    boxes[ b.id ] = { appear: Infinity, segments: [], tints: [ [ 0, b.color ] ], tags: [ [ 0, 'adjust' === b.color ? `${ b.points } · ${ b.event }` : b.event ] ] };
    actors[ b.id ] = [];
  }
  const lifts: number[] = [], denies: number[] = [], presses: number[] = [], dialRuns: [ number, number ][] = [];

  // Starts just after 0, so time 0 is the clean reset state on every loop.
  let t = 0.1;
  for ( const scene of scenes ) {
    sceneStart[ scene.id ] = t;
    entries.push( { start: t, end: t + INTRO, scene, step: null, phase: 'intro' } );
    t += INTRO;

    let walkBack: { id: PackageId; from: number } | null = null;
    if ( scene.show ) {
      const id = scene.show;
      const actor = ACTORS.find( ( a ) => a.id === id )!;
      if ( actor.console ) {
        boxes[ id ].appear = t - INTRO;
      } else {
        const home: V3 = [ actor.home[ 0 ], 0, actor.home[ 1 ] ];
        const dock: V3 = [ actor.dock[ 0 ] - 0.55, 0, actor.dock[ 1 ] ];
        actors[ id ].push( { t0: t, t1: t + WALK, from: home, to: dock, walking: true } );
        boxes[ id ].appear = t;
        boxes[ id ].segments.push( { t0: t, t1: t + WALK, from: [ home[ 0 ] + 0.35, 0.9, home[ 2 ] ], to: boxHome( id ) } );
        entries.push( { start: t, end: t + WALK, scene, step: null, phase: 'walk' } );
        t += WALK;
        walkBack = { id, from: t };
        actors[ id ].push( { t0: t, t1: t + WALK, from: dock, to: home, walking: true } );
      }
    }

    for ( const step of scene.steps ) {
      if ( step.pkg && step.conveyor ) {
        const id = step.pkg;
        const curve = CURVES.get( step.conveyor )!;
        const rideStart = t;
        const start = v3( curve.getPointAt( 0 ) );
        if ( ! near( at[ id ], start ) ) {
          boxes[ id ].segments.push( { t0: t, t1: t + SLIDE, from: at[ id ], to: start } );
          t += SLIDE;
        }
        if ( step.tint ) {
          boxes[ id ].tints.push( [ t, step.tint ] );
        }
        const seconds = Math.max( HOP, curve.getLength() / SPEED );
        boxes[ id ].segments.push( { t0: t, t1: t + seconds, from: start, to: v3( curve.getPointAt( 1 ) ), curve } );
        const leaving = CONVEYORS.find( ( c ) => c.id === step.conveyor )!.from;
        if ( 'earning-service' === leaving ) {
          const b = BOXES.find( ( x ) => x.id === id )!;
          boxes[ id ].tags.push( [ t + 0.2, `${ b.points } · ${ b.event }` ] );
        }
        t += seconds;
        at[ id ] = v3( curve.getPointAt( 1 ) );
        if ( step.settle ) {
          boxes[ id ].segments.push( { t0: t, t1: t + SLIDE, from: at[ id ], to: step.settle } );
          at[ id ] = step.settle;
          t += SLIDE;
        }
        entries.push( { start: rideStart, end: t, scene, step, phase: 'ride' } );
      }

      // The work: the card reads while the station acts; the gate opens only once its check is shown.
      if ( 'lift' === step.gate ) {
        lifts.push( t + WORK );
      }
      if ( 'deny' === step.gate ) {
        denies.push( t + 1.2 );
      }
      if ( step.press ) {
        presses.push( t + 1.4 );
      }
      if ( 'earning-service' === ( step.pulse ?? step.at ) ) {
        dialRuns.push( [ t, t + WORK ] );
      }
      entries.push( { start: t, end: t + WORK, scene, step, phase: 'work' } );
      t += WORK;
    }

    // An actor still walking home when the scene ends finishes before the hold does.
    if ( walkBack && walkBack.from + WALK > t ) {
      t = walkBack.from + WALK;
    }
    const last = scene.steps[ scene.steps.length - 1 ];
    entries.push( { start: t, end: t + HOLD, scene, step: last, phase: 'hold' } );
    t += HOLD;
  }

  return { entries, duration: t, sceneStart, boxes, actors, lifts, denies, presses, dialRuns };
}

export const COMPILED = compileTimeline();

export function entryAt( entries: Entry[], time: number ): Entry {
  for ( const e of entries ) {
    if ( time < e.end ) {
      return e;
    }
  }
  return entries[ entries.length - 1 ];
}

function along( s: Segment, time: number ): V3 {
  const raw = clamp01( ( time - s.t0 ) / ( s.t1 - s.t0 ) );
  if ( s.curve ) {
    return v3( s.curve.getPointAt( ride( raw, s.t1 - s.t0 ) ) );
  }
  const u = ease( raw );
  return [ 0, 1, 2 ].map( ( k ) => s.from[ k ] + ( s.to[ k ] - s.from[ k ] ) * u ) as V3;
}

/** The last segment that has started by `time`, or null before the first. */
function current( segments: Segment[], time: number ): Segment | null {
  let found: Segment | null = null;
  for ( const s of segments ) {
    if ( s.t0 <= time ) {
      found = s;
    }
  }
  return found;
}

function lastBefore<T>( list: [ number, T ][], time: number ): T {
  let value = list[ 0 ][ 1 ];
  for ( const [ t, v ] of list ) {
    if ( t <= time ) {
      value = v;
    }
  }
  return value;
}

export function boxPose( id: PackageId, time: number, c: Compiled = COMPILED ): { position: V3; visible: boolean; tint: Tint; tag: string } {
  const b = c.boxes[ id ];
  const s = current( b.segments, time );
  return {
    position: s ? along( s, time ) : boxHome( id ),
    visible: time >= b.appear,
    tint: lastBefore( b.tints, time ),
    tag: lastBefore( b.tags, time ),
  };
}

export function actorPose( id: PackageId, time: number, c: Compiled = COMPILED ): { position: Vec2; heading: number; walking: boolean } {
  const actor = ACTORS.find( ( a ) => a.id === id )!;
  const s = current( c.actors[ id ], time );
  if ( ! s ) {
    return { position: actor.home, heading: Math.PI / 2, walking: false };
  }
  const p = along( s, time );
  const walking = time < s.t1;
  // Heading is a rotation about y: +x is π/2. Idle actors face the belt.
  const heading = walking ? Math.atan2( s.to[ 0 ] - s.from[ 0 ], s.to[ 2 ] - s.from[ 2 ] ) : Math.PI / 2;
  return { position: [ p[ 0 ], p[ 2 ] ], heading, walking };
}

const pulse = ( time: number, start: number, length: number ) => ( time >= start && time < start + length ? Math.sin( Math.PI * ( time - start ) / length ) : 0 );

export function propPose( time: number, c: Compiled = COMPILED ): { gateArm: number; gateDeny: number; pressHead: number; dial: number } {
  let arm = 0;
  for ( const end of c.lifts ) {
    const up = ease( clamp01( ( time - ( end - 0.5 ) ) / 0.4 ) );
    const down = ease( clamp01( ( time - ( end + 0.9 ) ) / 0.4 ) );
    arm = Math.max( arm, up * ( 1 - down ) );
  }
  let deny = 0;
  for ( const s of c.denies ) {
    if ( time >= s && time < s + 1.32 ) {
      deny = 0.5 - 0.5 * Math.cos( 2 * Math.PI * ( time - s ) / 0.44 );
    }
  }
  let press = 0;
  for ( const s of c.presses ) {
    press = Math.max( press, pulse( time, s, 0.28 ) );
  }
  let dial = 0;
  for ( const [ a, b ] of c.dialRuns ) {
    dial += Math.max( 0, Math.min( time, b ) - a );
  }
  return { gateArm: arm * THREE.MathUtils.degToRad( 70 ), gateDeny: deny, pressHead: press, dial };
}

/** Which station the playhead is at, for highlighting. */
export function activeStation( e: Entry ): StationId | null {
  return e.step ? e.step.pulse ?? e.step.at ?? null : null;
}

