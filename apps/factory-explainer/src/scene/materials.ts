import * as THREE from 'three';
import type { StationType } from '../factory.config';

/** One accent per machine, HSL saturation ≤ 60 %. */
export const ACCENTS: Record<StationType, string> = {
  intake: '#3f9e96',
  scoring: '#7f6bb8',
  gate: '#c99b45',
  ledger: '#5b63b0',
  warehouse: '#8a8f98',
  vault: '#8a8f98',
  return: '#b65050',
  unbuilt: '#6f9fd8',
};

export type MaterialKind = 'graphite' | 'grey' | 'metal' | 'accent' | 'cardboard' | 'floor';

const KINDS: Record<MaterialKind, { color: string; roughness: number; metalness: number }> = {
  graphite: { color: '#4b5260', roughness: 0.6, metalness: 0 },
  grey: { color: '#a9afb8', roughness: 0.65, metalness: 0 },
  metal: { color: '#9aa1ab', roughness: 0.5, metalness: 0.6 },
  accent: { color: '#8a8f98', roughness: 0.55, metalness: 0 },
  cardboard: { color: '#c8a27a', roughness: 0.8, metalness: 0 },
  floor: { color: '#ece7df', roughness: 0.8, metalness: 0 },
};

/** A fresh material per call, so dimming one station never dims another. Metalness only on `metal`: the press, the vault and the rollers. */
export function material( kind: MaterialKind, color?: string ): THREE.MeshStandardMaterial {
  const k = KINDS[ kind ];
  return new THREE.MeshStandardMaterial( { color: color ?? k.color, roughness: k.roughness, metalness: k.metalness } );
}
