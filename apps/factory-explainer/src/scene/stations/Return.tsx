import type { Station } from '../../factory.config';
import { ACCENTS } from '../materials';
import { Kit, Part, Plinth, PLINTH } from './Kit';

/** OrderReversalListener: a machine with a red return chute on the side the refund arrives from. */
export function Return( { s }: { s: Station } ) {
  const [ w, d, h ] = s.size;
  const depth = d * 0.45;
  return (
    <group>
      <Plinth s={ s } />
      <Kit name="machine-window" fit={ [ w * 0.55, h, depth ] } kind="grey" position={ [ -w * 0.18, PLINTH, -d / 2 + depth / 2 ] } stretch />
      <group position={ [ w * 0.3, PLINTH + h * 0.45, -d / 2 + depth / 2 ] } rotation-z={ -0.5 }>
        <Kit name="conveyor-stripe" fit={ [ 1.3, 0.3, 0.8 ] } kind="accent" color={ ACCENTS.return } stretch />
      </group>
      <Part args={ [ 0.3, h * 0.45, 0.3 ] } position={ [ w * 0.3 + 0.35, PLINTH + h * 0.22, -d / 2 + depth / 2 ] } kind="graphite" />
    </group>
  );
}
