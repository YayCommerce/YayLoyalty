import { Suspense, useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import { CONSOLE_Y, type Actor as ActorData } from '../factory.config';
import { useModel } from '../models';
import { actorPose } from '../timeline';
import { useFactory } from '../store';
import { Kit, Pending } from './stations/Kit';
import { Worker } from './Worker';

/** The stand-in figure: a capsule that bobs while it walks. */
function Capsule( { actor }: { actor: ActorData } ) {
  const group = useRef<THREE.Group>( null );
  useFrame( () => {
    const time = useFactory.getState().time;
    const p = actorPose( actor.id, time );
    const g = group.current;
    if ( g ) {
      g.position.set( p.position[ 0 ], p.walking ? Math.abs( Math.sin( time * 9 ) ) * 0.06 : 0, p.position[ 1 ] );
      g.rotation.y = p.heading;
    }
  } );
  return (
    <group ref={ group }>
      <mesh position={ [ 0, 0.55, 0 ] } castShadow>
        <capsuleGeometry args={ [ 0.22, 0.6, 6, 16 ] } />
        <meshStandardMaterial color={ actor.color } roughness={ 0.7 } />
      </mesh>
      <mesh position={ [ 0, 1.22, 0 ] } castShadow>
        <sphereGeometry args={ [ 0.2, 24, 16 ] } />
        <meshStandardMaterial color="#e9c9a8" roughness={ 0.7 } />
      </mesh>
    </group>
  );
}

function Figure( { actor }: { actor: ActorData } ) {
  const gltf = useModel( 'worker' );
  return gltf ? <Worker actor={ actor } gltf={ gltf } /> : <Capsule actor={ actor } />;
}

/** A customer who walks between home and its dock, or the admin standing at a console. The capsule stands in while the worker loads, or if it fails. */
export function Actor( { actor }: { actor: ActorData } ) {
  return (
    <>
      <Suspense fallback={ <Pending><Capsule actor={ actor } /></Pending> }>
        <Figure actor={ actor } />
      </Suspense>
      { actor.console && (
        <group position={ [ actor.dock[ 0 ], 0, actor.dock[ 1 ] ] }>
          <RoundedBox args={ [ 0.9, CONSOLE_Y, 0.8 ] } radius={ 0.08 } position={ [ 0, CONSOLE_Y / 2, 0 ] } castShadow receiveShadow>
            <meshStandardMaterial color="#a9afb8" roughness={ 0.65 } />
          </RoundedBox>
          <Kit name="screen-small" fit={ [ 0.6, 0.55, 0.45 ] } kind="graphite" position={ [ 0, CONSOLE_Y, 0 ] } rotation={ -Math.PI / 2 } />
        </group>
      ) }
    </>
  );
}
