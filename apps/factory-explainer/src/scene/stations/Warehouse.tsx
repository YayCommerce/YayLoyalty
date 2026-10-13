import type { Station } from '../../factory.config';
import { Kit, Part, Plinth, PLINTH } from './Kit';

const SHELVES = [ 0.35, 0.8, 1.25 ];

/** PendingPoints: shelving racks on the back half, stacked with cartons; the pending box waits on the plinth in front. */
export function Warehouse( { s }: { s: Station } ) {
  const [ w, d, h ] = s.size;
  const depth = d * 0.4;
  const z = -d / 2 + depth / 2 + 0.05;
  return (
    <group>
      <Plinth s={ s } />
      { [ -1, 1 ].map( ( k ) => (
        <Kit key={ k } name="structure-medium" fit={ [ 0.18, h, depth ] } kind="graphite" position={ [ k * ( w / 2 - 0.15 ), PLINTH, z ] } stretch />
      ) ) }
      { SHELVES.map( ( y ) => (
        <Part key={ y } args={ [ w - 0.3, 0.06, depth ] } position={ [ 0, PLINTH + y, z ] } kind="grey" radius={ 0.025 } />
      ) ) }
      { SHELVES.slice( 0, 2 ).flatMap( ( y, i ) => [ -0.8, 0, 0.8 ].slice( i ).map( ( x ) => (
        <Kit key={ `${ y }:${ x }` } name="box-small" fit={ [ 0.5, 0.36, 0.42 ] } kind="cardboard" position={ [ x, PLINTH + y + 0.03, z ] } />
      ) ) ) }
    </group>
  );
}
