import { useEffect, useMemo, useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { clone } from 'three/examples/jsm/utils/SkeletonUtils.js';
import type { GLTF } from 'three/examples/jsm/loaders/GLTFLoader.js';
import type { Actor } from '../factory.config';
import { actorPose } from '../timeline';
import { useFactory } from '../store';
import { material } from './materials';

const HEIGHT = 1.45;
const WALK_RATE = 0.7; // clip speed that matches the 0.75 units/s walk
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

/** The Quaternius worker in the actor's colour; its clips play from story time, so a seek shows the right frame. */
export function Worker( { actor, gltf }: { actor: Actor; gltf: GLTF } ) {
  const group = useRef<THREE.Group>( null );
  const { root, mixer, walk, idle } = useMemo( () => {
    const r = clone( gltf.scene );
    // The 2D page's actor colours are 80–92 % saturated; mixing toward the machine grey keeps the palette calm.
    const body = material( 'accent', `#${ new THREE.Color( actor.color ).lerp( new THREE.Color( '#a9afb8' ), 0.35 ).getHexString() }` );
    body.roughness = 0.7;
    const joints = material( 'graphite' );
    r.traverse( ( o ) => {
      if ( o instanceof THREE.Mesh ) {
        const pick = ( m: THREE.Material ) => ( /joint/i.test( m.name ) ? joints : body );
        o.material = Array.isArray( o.material ) ? o.material.map( pick ) : pick( o.material );
        o.castShadow = true;
      }
    } );
    r.scale.setScalar( HEIGHT / new THREE.Box3().setFromObject( r ).getSize( new THREE.Vector3() ).y );
    const m = new THREE.AnimationMixer( r );
    const clip = ( re: RegExp ) => m.clipAction( gltf.animations.find( ( a ) => re.test( a.name ) )! );
    return { root: r, mixer: m, walk: clip( /walk/i ), idle: clip( /idle/i ) };
  }, [ gltf, actor.color ] );

  useEffect( () => {
    walk.play();
    idle.play();
    return () => {
      mixer.stopAllAction();
    };
  }, [ mixer, walk, idle ] );

  useFrame( () => {
    const time = useFactory.getState().time;
    const p = actorPose( actor.id, time );
    const b = blendedPose( actor.id, time );
    walk.setEffectiveWeight( b.walk );
    idle.setEffectiveWeight( 1 - b.walk );
    walk.time = ( time * WALK_RATE ) % walk.getClip().duration;
    idle.time = time % idle.getClip().duration;
    mixer.update( 0 );
    group.current?.position.set( p.position[ 0 ], 0, p.position[ 1 ] );
    group.current?.rotation.set( 0, b.heading, 0 );
  } );

  return (
    <group ref={ group }>
      <primitive object={ root } />
    </group>
  );
}
