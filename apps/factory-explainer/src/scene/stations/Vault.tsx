import { useMemo } from 'react';
import type { Station } from '../../factory.config';
import { material } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

/** The customers vault: a heavy safe on the back half with a round door and a spoked handle. */
export function Vault( { s }: { s: Station } ) {
  const [ w, d, h ] = s.size;
  const depth = d * 0.45;
  const face = -d / 2 + depth;
  const door = useMemo( () => material( 'metal' ), [] );
  const ring = useMemo( () => material( 'graphite' ), [] );
  return (
    <group>
      <Plinth s={ s } />
      <Kit name="machine-fortified" fit={ [ w * 0.85, h, depth ] } kind="metal" position={ [ 0, PLINTH, -d / 2 + depth / 2 ] } stretch />
      <group position={ [ 0, PLINTH + h * 0.5, face + 0.06 ] } rotation-x={ Math.PI / 2 }>
        <mesh material={ ring } castShadow>
          <cylinderGeometry args={ [ 0.78, 0.78, 0.08, 48 ] } />
        </mesh>
        <mesh material={ door } position={ [ 0, 0.06, 0 ] } castShadow>
          <cylinderGeometry args={ [ 0.66, 0.66, 0.1, 48 ] } />
        </mesh>
        <mesh material={ ring } position={ [ 0, 0.14, 0 ] }>
          <cylinderGeometry args={ [ 0.12, 0.12, 0.1, 24 ] } />
        </mesh>
      </group>
      { [ 0, 1, 2 ].map( ( i ) => (
        <Part key={ i } args={ [ 0.07, 0.62, 0.07 ] } position={ [ 0, PLINTH + h * 0.5, face + 0.27 ] } rotation={ [ 0, 0, ( i * Math.PI ) / 3 ] } kind="graphite" radius={ 0.03 } />
      ) ) }
    </group>
  );
}
