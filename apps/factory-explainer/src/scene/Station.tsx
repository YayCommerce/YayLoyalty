import type { ComponentType } from 'react';
import type { ThreeEvent } from '@react-three/fiber';
import type { Station as StationData, StationType } from '../factory.config';
import { useFactory } from '../store';
import { Blueprint } from './stations/Blueprint';
import { Gate } from './stations/Gate';
import { Intake } from './stations/Intake';
import { Ledger } from './stations/Ledger';
import { Return } from './stations/Return';
import { Scoring } from './stations/Scoring';
import { Vault } from './stations/Vault';
import { Warehouse } from './stations/Warehouse';

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
  return (
    <group position={ [ s.position[ 0 ], 0, s.position[ 1 ] ] } { ...handlers }>
      <Silhouette s={ s } />
    </group>
  );
}
