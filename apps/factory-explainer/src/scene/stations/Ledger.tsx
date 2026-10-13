import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import type { Station } from '../../factory.config';
import { propPose } from '../../timeline';
import { useFactory } from '../../store';
import { ACCENTS } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

const HEAD = 0.5;
const LOWEST = 0.8;

/** LedgerService: a stamping press, two columns and a beam over the belt, whose head drops on each write. */
export function Ledger( { s }: { s: Station } ) {
  const head = useRef<THREE.Group>( null );
  const [ w, d, h ] = s.size;
  const beam = PLINTH + h;
  const highest = beam - HEAD - 0.05;
  useFrame( () => {
    if ( head.current ) {
      const press = propPose( useFactory.getState().time ).pressHead;
      head.current.position.y = highest - press * ( highest - LOWEST );
      // Squash at the bottom of the stroke.
      head.current.scale.set( 1 + 0.12 * press ** 4, 1 - 0.18 * press ** 4, 1 + 0.12 * press ** 4 );
    }
  } );
  return (
    <group>
      <Plinth s={ s } />
      { [ -1, 1 ].map( ( k ) => (
        <Part key={ k } args={ [ 0.4, h, 0.4 ] } position={ [ 0, PLINTH + h / 2, k * ( d / 2 - 0.3 ) ] } kind="graphite" />
      ) ) }
      <Part args={ [ 0.7, 0.4, d - 0.2 ] } position={ [ 0, beam + 0.2, 0 ] } kind="graphite" />
      <Part args={ [ 0.72, 0.1, d * 0.7 ] } position={ [ 0, beam + 0.42, 0 ] } kind="accent" color={ ACCENTS.ledger } radius={ 0.04 } />
      <Part args={ [ w * 0.3, h * 0.7, d * 0.35 ] } position={ [ -w / 2 + w * 0.17, PLINTH + h * 0.35, -d / 2 + d * 0.2 ] } kind="grey" />
      <group ref={ head } position={ [ 0, highest, 0 ] }>
        <Part args={ [ 0.18, 0.9, 0.18 ] } position={ [ 0, HEAD + 0.45, 0 ] } kind="metal" radius={ 0.06 } />
        <Kit name="piston-square" fit={ [ 0.8, HEAD, 0.8 ] } kind="metal" stretch />
      </group>
    </group>
  );
}
