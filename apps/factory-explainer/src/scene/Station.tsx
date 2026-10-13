import { useRef, type ComponentType } from 'react';
import * as THREE from 'three';
import { useFrame, type ThreeEvent } from '@react-three/fiber';
import { Select } from '@react-three/postprocessing';
import type { Station as StationData, StationType } from '../factory.config';
import { useFactory } from '../store';
import { StationLabel } from '../ui/StationLabel';
import { Blueprint } from './stations/Blueprint';
import { Gate } from './stations/Gate';
import { Intake } from './stations/Intake';
import { StatusLed } from './stations/Kit';
import { Ledger } from './stations/Ledger';
import { Return } from './stations/Return';
import { Scoring } from './stations/Scoring';
import { Vault } from './stations/Vault';
import { Warehouse } from './stations/Warehouse';

const DIM = 0.55;
const DIM_RATE = 10; // per second; hover is UI feedback, not story time

/**
 * Eases each material's `userData.dim` toward `factor` and sets its colour to that share of its base colour.
 * A material marked `userData.animated` sets its own colour each frame and applies `dim` itself.
 */
function dimTowards( group: THREE.Group, factor: number, k: number ) {
  group.traverse( ( o ) => {
    if ( ! ( o instanceof THREE.Mesh ) ) {
      return;
    }
    for ( const m of Array.isArray( o.material ) ? o.material : [ o.material ] ) {
      // Toon solids only: the ink outlines are shader materials and stay dark.
      if ( ! ( m instanceof THREE.MeshToonMaterial ) ) {
        continue;
      }
      const dim = THREE.MathUtils.lerp( m.userData.dim ?? 1, factor, k );
      m.userData.dim = dim;
      if ( ! m.userData.animated ) {
        const base = ( m.userData.base ??= m.color.clone() ) as THREE.Color;
        m.color.copy( base ).multiplyScalar( dim );
      }
    }
  } );
}

const SILHOUETTES: Record<StationType, ComponentType<{ s: StationData }>> = {
  intake: Intake,
  scoring: Scoring,
  gate: Gate,
  ledger: Ledger,
  warehouse: Warehouse,
  vault: Vault,
  return: Return,
  unbuilt: Blueprint,
};

/** A station at its config position, drawn by the silhouette for its type. */
export function Station( { station: s }: { station: StationData } ) {
  // Hover and click target the whole station; the topmost hit wins.
  const handlers = {
    onPointerOver: ( e: ThreeEvent<PointerEvent> ) => {
      e.stopPropagation();
      useFactory.getState().setHovered( s.id );
      document.body.style.cursor = 'pointer';
    },
    onPointerOut: () => {
      if ( useFactory.getState().hovered === s.id ) {
        useFactory.getState().setHovered( null );
      }
      document.body.style.cursor = '';
    },
    onClick: ( e: ThreeEvent<MouseEvent> ) => {
      e.stopPropagation();
      useFactory.getState().openPanel( s.id );
    },
  };
  const Silhouette = SILHOUETTES[ s.type ];
  const machine = useRef<THREE.Group>( null );
  const outlined = useFactory( ( st ) => st.hovered === s.id );
  useFrame( ( _, dt ) => {
    const hovered = useFactory.getState().hovered;
    if ( machine.current ) {
      dimTowards( machine.current, hovered && hovered !== s.id ? DIM : 1, 1 - Math.exp( -DIM_RATE * dt ) );
    }
  } );
  return (
    <group position={ [ s.position[ 0 ], 0, s.position[ 1 ] ] } { ...handlers }>
      <Select enabled={ outlined }>
        <group ref={ machine }>
          <Silhouette s={ s } />
          { s.built && <StatusLed s={ s } /> }
        </group>
      </Select>
      <StationLabel s={ s } />
    </group>
  );
}
