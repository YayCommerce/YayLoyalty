import { useMemo } from 'react';
import { Edges, Line, RoundedBox } from '@react-three/drei';
import type { Station } from '../../factory.config';
import { ACCENTS, toon } from '../materials';

/** A station not built yet: a translucent blue wireframe over a dashed floor zone. No box ever enters it. */
export function Blueprint( { s }: { s: Station } ) {
  const [ w, d, h ] = s.size;
  const zone = useMemo( () => {
    const x = w / 2 + 0.25;
    const z = d / 2 + 0.25;
    return [ [ -x, 0.02, -z ], [ x, 0.02, -z ], [ x, 0.02, z ], [ -x, 0.02, z ], [ -x, 0.02, -z ] ] as [ number, number, number ][];
  }, [ w, d ] );
  return (
    <group>
      <Line points={ zone } color={ ACCENTS.unbuilt } lineWidth={ 2 } dashed dashSize={ 0.3 } gapSize={ 0.2 } />
      <RoundedBox args={ [ w * 0.8, h, d * 0.6 ] } radius={ 0.1 } position={ [ 0, h / 2, -d * 0.15 ] }>
        <meshToonMaterial { ...toon } color={ ACCENTS.unbuilt } transparent opacity={ 0.22 } depthWrite={ false } />
        <Edges color={ ACCENTS.unbuilt } />
      </RoundedBox>
    </group>
  );
}
