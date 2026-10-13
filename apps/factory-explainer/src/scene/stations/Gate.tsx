import { useMemo, useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import type { Station } from '../../factory.config';
import { propPose } from '../../timeline';
import { useFactory } from '../../store';
import { ACCENTS, material } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

const DENY = new THREE.Color( '#b84a3d' );

/** ProgramGate: a signal post and a barrier arm across the belt that lifts on a pass and flashes red on a deny. */
export function Gate( { s }: { s: Station } ) {
  const arm = useRef<THREE.Group>( null );
  const armMaterial = useMemo( () => material( 'accent', ACCENTS.gate ), [] );
  const base = useMemo( () => new THREE.Color( ACCENTS.gate ), [] );
  const d = s.size[ 1 ];
  const reach = d - 0.3;
  useFrame( () => {
    const p = propPose( useFactory.getState().time );
    if ( arm.current ) {
      arm.current.rotation.x = p.gateArm;
    }
    armMaterial.color.copy( base ).lerp( DENY, p.gateDeny );
  } );
  return (
    <group>
      <Plinth s={ s } />
      <Kit name="warning-traffic" fit={ [ 0.36, 1.4, 0.36 ] } kind="graphite" position={ [ 0.3, PLINTH, -( d / 2 - 0.2 ) ] } />
      <Part args={ [ 0.22, 0.9, 0.22 ] } position={ [ 0.3, PLINTH + 0.45, d / 2 - 0.15 ] } kind="graphite" />
      <group ref={ arm } position={ [ 0.3, PLINTH + 0.95, -( d / 2 - 0.2 ) ] }>
        <RoundedBox args={ [ 0.14, 0.14, reach ] } radius={ 0.06 } smoothness={ 3 } position={ [ 0, 0, reach / 2 ] } material={ armMaterial } castShadow />
      </group>
    </group>
  );
}
