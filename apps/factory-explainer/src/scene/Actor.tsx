import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import { CONSOLE_Y, type Actor as ActorData } from '../factory.config';
import { actorPose } from '../timeline';
import { useFactory } from '../store';

/** A simple figure that walks between home and its dock; the admin stands at a console. */
export function Actor( { actor }: { actor: ActorData } ) {
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
    <>
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
      { actor.console && (
        <RoundedBox args={ [ 0.9, CONSOLE_Y, 0.8 ] } radius={ 0.08 } position={ [ actor.dock[ 0 ], CONSOLE_Y / 2, actor.dock[ 1 ] ] } castShadow receiveShadow>
          <meshStandardMaterial color="#a9afb8" roughness={ 0.65 } />
        </RoundedBox>
      ) }
    </>
  );
}
