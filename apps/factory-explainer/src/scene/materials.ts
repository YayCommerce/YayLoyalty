import * as THREE from 'three';
import type { StationType } from '../factory.config';

/** One bright accent per machine, for the cartoon look. */
export const ACCENTS: Record<StationType, string> = {
  intake: '#1fb5a5',
  scoring: '#8e5cf0',
  gate: '#ffb52e',
  ledger: '#4c6ef5',
  warehouse: '#c3ccd8',
  vault: '#c3ccd8',
  return: '#ef4b4b',
  unbuilt: '#4aa3ff',
};

/** The outline colour drawn around every solid. */
export const INK = '#1d2230';

export type MaterialKind = 'graphite' | 'grey' | 'metal' | 'accent' | 'cardboard' | 'floor';

const KINDS: Record<MaterialKind, string> = {
  graphite: '#4b5670',
  grey: '#cfd6e0',
  metal: '#b8c4d4',
  accent: '#c3ccd8',
  cardboard: '#e0a96d',
  floor: '#fff3df',
};

/** Three flat light bands for every toon material. */
const BANDS = ( () => {
  const t = new THREE.DataTexture( new Uint8Array( [ 110, 190, 255 ] ), 3, 1, THREE.RedFormat );
  t.minFilter = THREE.NearestFilter;
  t.magFilter = THREE.NearestFilter;
  t.needsUpdate = true;
  return t;
} )();

/** A fresh toon material per call, so dimming one station never dims another. */
export function material( kind: MaterialKind, color?: string ): THREE.MeshToonMaterial {
  return new THREE.MeshToonMaterial( { color: color ?? KINDS[ kind ], gradientMap: BANDS } );
}

/** Props for an inline JSX toon material, sharing the same bands. */
export const toon = { gradientMap: BANDS } as const;
