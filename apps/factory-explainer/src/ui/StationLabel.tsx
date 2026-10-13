import { Html } from '@react-three/drei';
import type { Station } from '../factory.config';
import { PLINTH } from '../scene/stations/Kit';
import { useFactory } from '../store';
import { useLateMount } from './useLateMount';

/**
 * The station's name above its machine, anchored over the top face's back corner: the highest point on screen in the iso view, so the label never covers its own machine.
 * Hovering it adds the type, the class file and a one-line description, and highlights the station.
 */
export function StationLabel( { s }: { s: Station } ) {
  const hovered = useFactory( ( st ) => st.hovered === s.id );
  if ( ! useLateMount() ) {
    return null;
  }
  return (
    <Html position={ [ -s.size[ 0 ] / 2, PLINTH + s.size[ 2 ] + s.labelHeight, -s.size[ 1 ] / 2 ] } occlude zIndexRange={ [ 4, 0 ] } style={ { pointerEvents: 'none' } }>
      <div
        className={ `station-label${ hovered ? ' is-hover' : '' }${ s.built ? '' : ' is-unbuilt' }` }
        data-station={ s.id }
        onPointerEnter={ () => useFactory.getState().setHovered( s.id ) }
        onPointerLeave={ () => useFactory.getState().setHovered( null ) }
        onClick={ () => useFactory.getState().openPanel( s.id ) }
      >
        <span className="name">{ s.label }</span>
        { ! s.built && <span className="tag">not built yet</span> }
        { hovered && (
          <span className="more">
            <span className="role">{ s.role }</span>
            <code>{ s.file }</code>
            <span className="desc">{ s.description }</span>
          </span>
        ) }
      </div>
    </Html>
  );
}
