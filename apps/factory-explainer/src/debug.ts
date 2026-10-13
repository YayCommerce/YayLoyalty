import type { Scene, StationId } from './factory.config';
import { useFactory } from './store';
import { COMPILED, entryAt } from './timeline';

/** Dev-only handle for the screenshot harness and manual checks. */
export interface FactoryDebug {
  seekScene( id: Scene[ 'id' ] ): void;
  seekTime( t: number ): void;
  pause(): void;
  state(): { time: number; paused: boolean; entry: string };
  entries(): { start: number; end: number; scene: string; phase: string; at: StationId | null }[];
  labelRects(): Record<string, DOMRect>;
  machineRects(): Record<string, DOMRect>;
}

const handle: FactoryDebug = {
  seekScene: ( id ) => useFactory.getState().seekScene( id ),
  seekTime: ( t ) => useFactory.getState().seekTime( t ),
  pause: () => useFactory.setState( { paused: true, hoverHeld: false } ),
  state: () => {
    const s = useFactory.getState();
    const e = entryAt( COMPILED.entries, s.time );
    return { time: s.time, paused: s.paused, entry: `${ e.scene.id } ${ e.phase } ${ e.step?.card.fn ?? '' }` };
  },
  entries: () => COMPILED.entries.map( ( e ) => ( { start: e.start, end: e.end, scene: e.scene.id, phase: e.phase, at: e.step ? e.step.pulse ?? e.step.at ?? null : null } ) ),
  labelRects: () => ( {} ),
  machineRects: () => ( {} ),
};

( window as unknown as { __factory: FactoryDebug } ).__factory = handle;
