import { useMemo, useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import { ACTORS, type Actor } from '../factory.config';
import { actorPose } from '../timeline';
import { useFactory } from '../store';
import { Ink } from './Ink';
import { material } from './materials';

const SKIN = '#ffd23f';
const STRIDE = 9; // radians of walk cycle per second of story time
const BLEND = 0.3; // seconds over which walk and idle cross-fade, and a turn eases
const SAMPLES = 6;

/** Walk weight and heading at `time`, averaged over the last BLEND seconds, so both ease and stay pure functions of time. */
function blendedPose( id: Actor[ 'id' ], time: number ) {
  let walk = 0;
  let sx = 0;
  let sz = 0;
  for ( let i = 0; i < SAMPLES; i++ ) {
    const p = actorPose( id, Math.max( 0, time - ( BLEND * i ) / ( SAMPLES - 1 ) ) );
    walk += p.walking ? 1 : 0;
    sx += Math.sin( p.heading );
    sz += Math.cos( p.heading );
  }
  return { walk: walk / SAMPLES, heading: Math.atan2( sx, sz ) };
}

/** 0 while the eyes are shut for a blink, 1 otherwise; each worker blinks on its own beat. */
function eyesOpen( time: number, offset: number ): number {
  return ( time + offset ) % 3.7 < 0.13 ? 0.12 : 1;
}

/** A goggle on the front of the head: a metal rim, a white eye and a pupil. */
function Goggle( { x, eye }: { x: number; eye: ( el: THREE.Group | null ) => void } ) {
  const rim = useMemo( () => material( 'metal' ), [] );
  const white = useMemo( () => material( 'grey', '#ffffff' ), [] );
  const pupil = useMemo( () => material( 'graphite', '#1d2230' ), [] );
  return (
    <group position={ [ x, 0.95, 0.29 ] }>
      <mesh rotation-x={ Math.PI / 2 } material={ rim } castShadow>
        <cylinderGeometry args={ [ 0.13, 0.13, 0.09, 28 ] } />
        <Ink />
      </mesh>
      <group ref={ eye } position={ [ 0, 0, 0.04 ] }>
        <mesh material={ white } scale={ [ 1, 1, 0.5 ] }>
          <sphereGeometry args={ [ 0.1, 20, 14 ] } />
        </mesh>
        <mesh material={ pupil } position={ [ 0, 0, 0.045 ] }>
          <sphereGeometry args={ [ 0.042, 14, 10 ] } />
        </mesh>
      </group>
    </group>
  );
}

/** A chunky yellow cartoon worker in overalls of the actor's colour, with one or two goggles; every motion follows story time. */
export function CartoonWorker( { actor }: { actor: Actor } ) {
  const root = useRef<THREE.Group>( null );
  const body = useRef<THREE.Group>( null );
  const arms = useRef<( THREE.Group | null )[]>( [] );
  const legs = useRef<( THREE.Group | null )[]>( [] );
  const eyes = useRef<( THREE.Group | null )[]>( [] );
  const index = ACTORS.findIndex( ( a ) => a.id === actor.id );
  const twoEyes = 1 === index % 2;
  const m = useMemo( () => ( {
    skin: material( 'accent', SKIN ),
    cloth: material( 'accent', actor.color ),
    dark: material( 'graphite' ),
  } ), [ actor.color ] );

  useFrame( () => {
    const time = useFactory.getState().time;
    const p = actorPose( actor.id, time );
    const b = blendedPose( actor.id, time );
    const phase = time * STRIDE + index;
    const swing = Math.sin( phase ) * b.walk;
    root.current?.position.set( p.position[ 0 ], Math.abs( Math.sin( phase ) ) * 0.08 * b.walk, p.position[ 1 ] );
    root.current?.rotation.set( 0, b.heading, 0 );
    if ( body.current ) {
      body.current.rotation.z = swing * 0.12;
      body.current.scale.y = 1 + ( 1 - b.walk ) * 0.025 * Math.sin( time * 2.2 + index );
    }
    arms.current.forEach( ( a, i ) => a && ( a.rotation.x = ( i ? -1 : 1 ) * swing * 0.9 ) );
    legs.current.forEach( ( l, i ) => l && ( l.rotation.x = ( i ? 1 : -1 ) * swing * 0.7 ) );
    const open = eyesOpen( time, index * 0.9 );
    eyes.current.forEach( ( e ) => e && ( e.scale.y = open ) );
  } );

  return (
    <group ref={ root }>
      { [ -1, 1 ].map( ( k, i ) => (
        <group key={ k } ref={ ( el ) => { legs.current[ i ] = el; } } position={ [ k * 0.13, 0.3, 0 ] }>
          <mesh position={ [ 0, -0.12, 0 ] } material={ m.cloth } castShadow>
            <cylinderGeometry args={ [ 0.085, 0.085, 0.2, 16 ] } />
            <Ink />
          </mesh>
          <RoundedBox args={ [ 0.17, 0.09, 0.26 ] } radius={ 0.04 } position={ [ 0, -0.25, 0.04 ] } material={ m.dark } castShadow>
            <Ink />
          </RoundedBox>
        </group>
      ) ) }
      <group ref={ body }>
        <mesh position={ [ 0, 0.74, 0 ] } material={ m.skin } castShadow>
          <capsuleGeometry args={ [ 0.32, 0.48, 8, 24 ] } />
          <Ink />
        </mesh>
        <mesh position={ [ 0, 0.47, 0 ] } material={ m.cloth } castShadow>
          <cylinderGeometry args={ [ 0.335, 0.32, 0.3, 28 ] } />
          <Ink />
        </mesh>
        <RoundedBox args={ [ 0.34, 0.22, 0.08 ] } radius={ 0.03 } position={ [ 0, 0.68, 0.29 ] } material={ m.cloth }>
          <Ink />
        </RoundedBox>
        { [ -1, 1 ].map( ( k ) => (
          <RoundedBox key={ k } args={ [ 0.05, 0.3, 0.04 ] } radius={ 0.015 } position={ [ k * 0.15, 0.86, 0.27 ] } rotation={ [ -0.25, 0, 0 ] } material={ m.cloth } />
        ) ) }
        <mesh position={ [ 0, 0.95, 0 ] } rotation-x={ Math.PI / 2 } material={ m.dark }>
          <torusGeometry args={ [ 0.325, 0.03, 8, 40 ] } />
        </mesh>
        { ( twoEyes ? [ -0.13, 0.13 ] : [ 0 ] ).map( ( x, i ) => (
          <Goggle key={ x } x={ x } eye={ ( el ) => { eyes.current[ i ] = el; } } />
        ) ) }
        <mesh position={ [ 0, 0.77, 0.3 ] } rotation={ [ 0, 0, Math.PI ] } material={ m.dark }>
          <torusGeometry args={ [ 0.07, 0.014, 6, 16, Math.PI ] } />
        </mesh>
        { [ -0.07, 0, 0.07 ].map( ( x ) => (
          <mesh key={ x } position={ [ x, 1.2, 0 ] } rotation-z={ x * 3 } material={ m.dark }>
            <cylinderGeometry args={ [ 0.008, 0.008, 0.12, 6 ] } />
          </mesh>
        ) ) }
        { [ -1, 1 ].map( ( k, i ) => (
          <group key={ k } ref={ ( el ) => { arms.current[ i ] = el; } } position={ [ k * 0.35, 0.72, 0 ] } rotation-z={ k * 0.25 }>
            <mesh position={ [ 0, -0.15, 0 ] } material={ m.skin } castShadow>
              <capsuleGeometry args={ [ 0.065, 0.2, 6, 12 ] } />
              <Ink />
            </mesh>
            <mesh position={ [ 0, -0.31, 0 ] } material={ m.dark }>
              <sphereGeometry args={ [ 0.08, 14, 10 ] } />
              <Ink />
            </mesh>
          </group>
        ) ) }
      </group>
    </group>
  );
}
