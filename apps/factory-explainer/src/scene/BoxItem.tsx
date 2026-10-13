import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { Html, RoundedBox } from '@react-three/drei';
import type { PackageId } from '../factory.config';
import { boxPose, type Tint } from '../timeline';
import { useFactory } from '../store';
import { useLateMount } from '../ui/useLateMount';

const SIZE = 0.5;
const TINTS: Record<Tint, string> = { earn: '#c8a27a', adjust: '#9fb0c8', rejected: '#9ca3af', refund: '#c97a6e' };

/** A cardboard box with its event tag; it follows boxPose, so a seek shows the same frame as playback. */
export function BoxItem( { id }: { id: PackageId } ) {
  const group = useRef<THREE.Group>( null );
  const material = useRef<THREE.MeshStandardMaterial>( null );
  const tag = useFactory( ( s ) => boxPose( id, s.time ).tag );
  const visible = useFactory( ( s ) => boxPose( id, s.time ).visible );
  const tint = useFactory( ( s ) => boxPose( id, s.time ).tint );
  const tagReady = useLateMount();

  useFrame( () => {
    const p = boxPose( id, useFactory.getState().time );
    group.current?.position.set( p.position[ 0 ], p.position[ 1 ] + SIZE / 2, p.position[ 2 ] );
  } );

  return (
    <group ref={ group } visible={ visible }>
      <RoundedBox
        args={ [ SIZE, SIZE, SIZE ] }
        radius={ 0.05 }
        smoothness={ 3 }
        castShadow
        receiveShadow
        onPointerOver={ ( e ) => {
          e.stopPropagation();
          if ( visible ) {
            useFactory.getState().hoverHold( true );
          }
        } }
        onPointerOut={ () => useFactory.getState().hoverHold( false ) }
      >
        <meshStandardMaterial ref={ material } color={ TINTS[ tint ] } roughness={ 0.8 } />
      </RoundedBox>
      <RoundedBox args={ [ SIZE + 0.01, 0.06, 0.12 ] } radius={ 0.02 } position={ [ 0, SIZE / 2 - 0.02, 0 ] }>
        <meshStandardMaterial color="#e8dcc8" roughness={ 0.6 } />
      </RoundedBox>
      { /* Kept mounted and hidden by CSS: unmounting drei's Html mid-render, on a seek or a loop wrap, logs React errors. */ }
      { tagReady && (
        <Html center position={ [ 0, SIZE / 2 + 0.35, 0 ] } style={ { pointerEvents: 'none', display: visible ? undefined : 'none' } }>
          <span className="box-tag">{ tag }</span>
        </Html>
      ) }
    </group>
  );
}
