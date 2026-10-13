import { useEffect, useRef, type CSSProperties } from 'react';
import * as THREE from 'three';
import { ACTORS, BOXES, type Actor, type Box, type PackageId } from '../factory.config';
import { rig } from '../scene/rig';
import { useFactory } from '../store';
import { actorPose, boxPose, COMPILED, entryAt } from '../timeline';

const HEAD = 1.55; // world height of a worker's name tag
const LID = 0.85; // world height of a box's tag above its base
const CARRY = 0.8; // world distance under which a box counts as in a worker's hands

/** What a worker is doing at `time`, in a few words, from its walk and the scene it is in. */
function actionOf( a: Actor, time: number ): string {
  const p = actorPose( a.id, time );
  const box = BOXES.find( ( b ) => b.id === a.id );
  if ( p.walking ) {
    return p.heading > 0 ? `brings the ${ box?.event ?? '' } box` : 'heads home';
  }
  const scene = entryAt( COMPILED.entries, time ).scene;
  if ( scene.show === a.id || scene.steps.some( ( s ) => s.pkg === a.id ) ) {
    // "1 · Alice places an order" → "places an order"; "3 · Alice's order is completed" → "order is completed".
    return scene.caption.replace( /^\d+ · /, '' ).replace( new RegExp( `^(${ a.name }'s |${ a.name } |The ${ a.name.toLowerCase() } )`, 'i' ), '' );
  }
  return a.console ? 'at the console' : 'waiting';
}

/** A box's pills: the event, its money value, and its points once scored. */
function BoxTag( { box }: { box: Box } ) {
  const pose = useFactory( ( s ) => {
    const p = boxPose( box.id, s.time );
    return `${ p.visible }|${ p.scored }|${ p.tint }`;
  } );
  const [ visible, scored, tint ] = pose.split( '|' );
  const points = box.points.replace( /^\+/, '' );
  return (
    <span className="box-tag" data-box={ box.id } hidden={ 'true' !== visible }>
      <span className="event">{ box.event }</span>
      { box.money && <span className="money">{ box.money }</span> }
      { 'rejected' === tint && <span className="pts is-none">✗ 0 pts</span> }
      { 'refund' === tint && <span className="pts is-minus">−{ points } pts</span> }
      { 'true' === scored && 'rejected' !== tint && 'refund' !== tint && <span className="pts">★ +{ points } pts</span> }
    </span>
  );
}

/** A worker's name and what it is doing. */
function WorkerTag( { actor }: { actor: Actor } ) {
  const action = useFactory( ( s ) => actionOf( actor, s.time ) );
  return (
    <span className="worker-tag" data-actor={ actor.id } style={ { '--who': actor.color } as CSSProperties }>
      <b>{ actor.name }</b> { action }
    </span>
  );
}

/** Worker and box tags as page elements over the canvas, placed through the live camera every frame. */
export function WorldTags() {
  const layer = useRef<HTMLDivElement>( null );

  useEffect( () => {
    const v = new THREE.Vector3();
    let frame = 0;
    const place = () => {
      frame = requestAnimationFrame( place );
      const { camera, canvas } = rig;
      const root = layer.current;
      if ( ! camera || ! canvas || ! root ) {
        return;
      }
      const box = canvas.getBoundingClientRect();
      const time = useFactory.getState().time;
      const put = ( el: HTMLElement, x: number, y: number, z: number, offset: string ) => {
        v.set( x, y, z ).project( camera );
        el.style.transform = `translate(${ ( box.left + ( v.x + 1 ) / 2 * box.width ).toFixed( 1 ) }px, ${ ( box.top + ( 1 - v.y ) / 2 * box.height ).toFixed( 1 ) }px) ${ offset }`;
      };
      const workers = ACTORS.map( ( a ) => actorPose( a.id, time ).position );
      root.querySelectorAll<HTMLElement>( '[data-actor]' ).forEach( ( el ) => {
        const p = actorPose( el.dataset.actor as PackageId, time ).position;
        put( el, p[ 0 ], HEAD, p[ 1 ], 'translate(-50%, -100%)' );
      } );
      root.querySelectorAll<HTMLElement>( '[data-box]' ).forEach( ( el ) => {
        const p = boxPose( el.dataset.box as PackageId, time ).position;
        // A box in a worker's hands would put its tag on the worker's: it goes to the box's right instead.
        const carried = workers.some( ( w ) => Math.hypot( w[ 0 ] - p[ 0 ], w[ 1 ] - p[ 2 ] ) < CARRY );
        put( el, p[ 0 ], p[ 1 ] + ( carried ? 0.25 : LID ), p[ 2 ], carried ? 'translate(18px, -50%)' : 'translate(-50%, -100%)' );
      } );
    };
    place();
    return () => cancelAnimationFrame( frame );
  }, [] );

  return (
    <div ref={ layer } className="world-tags" aria-hidden="true">
      { ACTORS.map( ( a ) => <WorkerTag key={ a.id } actor={ a } /> ) }
      { BOXES.map( ( b ) => <BoxTag key={ b.id } box={ b } /> ) }
    </div>
  );
}
