import { RoundedBox } from '@react-three/drei';
import { REJECT_BIN } from '../factory.config';
import { FLOOR } from './Stage';

const MARGIN = 1.2;

/** The factory platform: a thick beveled slab whose top face is y = 0. */
export function Floor() {
  const w = FLOOR.x1 - FLOOR.x0 + 2 * MARGIN;
  const d = FLOOR.z1 - FLOOR.z0 + 2 * MARGIN;
  return (
    <RoundedBox args={ [ w, 0.4, d ] } radius={ 0.12 } smoothness={ 3 } position={ [ ( FLOOR.x0 + FLOOR.x1 ) / 2, -0.2, ( FLOOR.z0 + FLOOR.z1 ) / 2 ] } receiveShadow>
      <meshStandardMaterial color="#efe9df" roughness={ 0.8 } />
    </RoundedBox>
  );
}

/** The reject bin: an open tray at the end of the gate's side belt. */
export function RejectBin() {
  const [ x, z ] = REJECT_BIN;
  return (
    <group position={ [ x, 0, z ] }>
      <RoundedBox args={ [ 1.5, 0.12, 1.5 ] } radius={ 0.04 } position={ [ 0, 0.06, 0 ] } receiveShadow>
        <meshStandardMaterial color="#5b6270" roughness={ 0.7 } />
      </RoundedBox>
      { [ [ 0, -0.7, 1.5, 0.1 ], [ 0, 0.7, 1.5, 0.1 ], [ -0.7, 0, 0.1, 1.5 ], [ 0.7, 0, 0.1, 1.5 ] ].map( ( [ dx, dz, w, d ], i ) => (
        <RoundedBox key={ i } args={ [ w, 0.55, d ] } radius={ 0.03 } position={ [ dx, 0.3, dz ] } castShadow receiveShadow>
          <meshStandardMaterial color="#7a7f88" roughness={ 0.7 } />
        </RoundedBox>
      ) ) }
    </group>
  );
}
