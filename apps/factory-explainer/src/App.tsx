import { useEffect } from 'react';
import { ACTORS, BOXES, CONVEYORS, STATIONS } from './factory.config';
import { Actor } from './scene/Actor';
import { BoxItem } from './scene/BoxItem';
import { Clock } from './scene/Clock';
import { Conveyor } from './scene/Conveyor';
import { Floor, RejectBin } from './scene/Floor';
import { Stage } from './scene/Stage';
import { Station } from './scene/Station';
import { useFactory } from './store';
import { StationPanel } from './ui/StationPanel';
import { StepCard } from './ui/StepCard';
import { Toolbar } from './ui/Toolbar';
import './ui/ui.css';

export function App() {
  // Space toggles play, except on a focused button, which Space already presses.
  useEffect( () => {
    const onKey = ( e: KeyboardEvent ) => {
      if ( ' ' !== e.key || ( e.target instanceof HTMLElement && 'BUTTON' === e.target.tagName ) ) {
        return;
      }
      e.preventDefault();
      useFactory.getState().toggle();
    };
    document.addEventListener( 'keydown', onKey );
    return () => document.removeEventListener( 'keydown', onKey );
  }, [] );

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
      <Toolbar />
      <StepCard />
      <StationPanel />
    </div>
  );
}
