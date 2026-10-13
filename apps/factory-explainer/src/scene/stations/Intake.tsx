import type { Station } from '../../factory.config';
import { ACCENTS } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

/** A loading dock: a hopper on the back half and a scanner arch the belt runs through. */
export function Intake( { s }: { s: Station } ) {
  const [ w, d, h ] = s.size;
  return (
    <group>
      <Plinth s={ s } />
      <Kit name="hopper-square" fit={ [ 1.1, h, d * 0.45 ] } kind="grey" position={ [ -w * 0.22, PLINTH, -d * 0.27 ] } />
      <Kit name="scanner-high" fit={ [ 0.5, h + 0.25, d ] } kind="graphite" position={ [ w * 0.28, PLINTH, 0 ] } />
      <Part args={ [ 0.56, 0.1, d * 0.9 ] } position={ [ w * 0.28, PLINTH + h + 0.05, 0 ] } kind="accent" color={ ACCENTS.intake } radius={ 0.04 } />
    </group>
  );
}
