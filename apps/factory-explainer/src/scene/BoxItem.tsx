import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import type { PackageId } from '../factory.config';
import { boxPose, type Tint } from '../timeline';
import { useFactory } from '../store';
import { Ink } from './Ink';
import { toon } from './materials';

const SIZE = 0.5;
const TINTS: Record<Tint, string> = { earn: '#f0b46a', adjust: '#7fb2ff', rejected: '#a3abb8', refund: '#ff7a6b' };

/** A cardboard box; it follows boxPose, so a seek shows the same frame as playback. Its tag is drawn by WorldTags. */
export function BoxItem( { id }: { id: PackageId } ) {
  const group = useRef<THREE.Group>( null );
  const material = useRef<THREE.MeshToonMaterial>( null );
  const visible = useFactory( ( s ) => boxPose( id, s.time ).visible );
  const tint = useFactory( ( s ) => boxPose( id, s.time ).tint );

  useFrame( () => {
    const p = boxPose( id, useFactory.getState().time );
    group.current?.position.set( p.position[ 0 ], p.position[ 1 ] + SIZE / 2 * ( 1 - p.squash ), p.position[ 2 ] );
    group.current?.scale.set( 1 + p.squash / 2, 1 - p.squash, 1 + p.squash / 2 );
  } );

  return (
    <group ref={ group } visible={ visible }>
      <RoundedBox
        args={ [ SIZE, SIZE, SIZE ] }
        radius={ 0.05 }
        smoothness={ 3 }
        castShadow
        receiveShadow
      >
        <meshToonMaterial ref={ material } { ...toon } color={ TINTS[ tint ] } />
        <Ink />
      </RoundedBox>
      <RoundedBox args={ [ SIZE + 0.01, 0.06, 0.12 ] } radius={ 0.02 } position={ [ 0, SIZE / 2 - 0.02, 0 ] }>
        <meshToonMaterial { ...toon } color="#f4e6c8" />
      </RoundedBox>
    </group>
  );
}
