import { useEffect } from 'react';
import { StatsGl } from '@react-three/drei';
import { ACTORS, BOXES, CONVEYORS, STATIONS } from './factory.config';
import { Actor } from './scene/Actor';
import { BoxItem } from './scene/BoxItem';
import { Clock } from './scene/Clock';
import { Conveyor } from './scene/Conveyor';
import { Floor, RejectBin } from './scene/Floor';
import { Props } from './scene/Props';
import { Stage } from './scene/Stage';
import { Station } from './scene/Station';
import { useFactory } from './store';
import { StationPanel } from './ui/StationPanel';
import { StepCard } from './ui/StepCard';
import { Toolbar } from './ui/Toolbar';
import './ui/ui.css';

/** True when the browser can create a WebGL context; checked once, before the canvas mounts. */
const WEBGL = ( () => {
  try {
    const gl = document.createElement( 'canvas' ).getContext( 'webgl2' ) ?? document.createElement( 'canvas' ).getContext( 'webgl' );
    gl?.getExtension( 'WEBGL_lose_context' )?.loseContext();
    return Boolean( gl );
  } catch {
    return false;
  }
} )();

const PERF = new URLSearchParams( window.location.search ).has( 'perf' );

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

  if ( ! WEBGL ) {
    return <p className="no-webgl" role="alert">This explainer needs WebGL.</p>;
  }

  return (
    <div className="app">
      <Stage>
        { PERF && <StatsGl className="perf-stats" clearStatsGlStyle /> }
        <Clock />
        <Floor />
        <RejectBin />
        <Props />
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
