import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import type { Station } from '../../factory.config';
import { propPose } from '../../timeline';
import { reduced, useFactory } from '../../store';
import { ACCENTS } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

/** EarningService: a machine housing with a cog dial on its front face that turns while it scores. */
export function Scoring( { s }: { s: Station } ) {
  const dial = useRef<THREE.Group>( null );
  const [ w, d, h ] = s.size;
  const depth = d * 0.45;
  useFrame( () => {
    if ( dial.current ) {
      // Under reduced motion the dial stands still, even if the user presses Play.
      dial.current.rotation.z = reduced ? 0 : -propPose( useFactory.getState().time ).dial;
    }
  } );
  return (
    <group>
      <Plinth s={ s } />
      <Kit name="machine" fit={ [ w * 0.8, h, depth ] } kind="grey" stretch position={ [ 0, PLINTH, -d / 2 + depth / 2 ] } />
      <group ref={ dial } position={ [ 0, PLINTH + h * 0.62, -d / 2 + depth + 0.08 ] }>
        <group rotation-x={ Math.PI / 2 }>
          <Kit name="cog-a" fit={ [ 0.95, 0.12, 0.95 ] } kind="accent" color={ ACCENTS.scoring } position={ [ 0, -0.06, 0 ] } />
        </group>
        <Part args={ [ 0.08, 0.36, 0.05 ] } position={ [ 0, 0.16, 0.09 ] } kind="grey" color="#f4f1ec" radius={ 0.02 } />
      </group>
    </group>
  );
}
