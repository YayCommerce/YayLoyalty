import { ACTORS, BOXES, CONVEYORS, STATIONS } from './factory.config';
import { Actor } from './scene/Actor';
import { BoxItem } from './scene/BoxItem';
import { Clock } from './scene/Clock';
import { Conveyor } from './scene/Conveyor';
import { Floor, RejectBin } from './scene/Floor';
import { Stage } from './scene/Stage';
import { Station } from './scene/Station';

export function App() {
  return (
    <div className="app">
      <Stage>
        <Clock />
        <Floor />
        <RejectBin />
        { CONVEYORS.map( ( c ) => <Conveyor key={ c.id } conveyor={ c } /> ) }
        { STATIONS.map( ( s ) => <Station key={ s.id } station={ s } /> ) }
        { ACTORS.map( ( a ) => <Actor key={ a.id } actor={ a } /> ) }
        { BOXES.map( ( b ) => <BoxItem key={ b.id } id={ b.id } /> ) }
      </Stage>
    </div>
  );
}
