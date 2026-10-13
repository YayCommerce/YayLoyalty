import { CONSOLE_Y, type Actor as ActorData } from '../factory.config';
import { CartoonWorker } from './CartoonWorker';
import { Kit, Part } from './stations/Kit';

/** A customer who walks between home and its dock, or the admin standing at a console. */
export function Actor( { actor }: { actor: ActorData } ) {
  return (
    <>
      <CartoonWorker actor={ actor } />
      { actor.console && (
        <group position={ [ actor.dock[ 0 ], 0, actor.dock[ 1 ] ] }>
          <Part args={ [ 0.9, CONSOLE_Y, 0.8 ] } radius={ 0.08 } position={ [ 0, CONSOLE_Y / 2, 0 ] } kind="grey" />
          <Kit name="screen-small" fit={ [ 0.6, 0.55, 0.45 ] } kind="graphite" position={ [ 0, CONSOLE_Y, 0 ] } rotation={ -Math.PI / 2 } />
        </group>
      ) }
    </>
  );
}
