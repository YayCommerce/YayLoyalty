import { create } from 'zustand';
import type { Scene, StationId } from './factory.config';
import { COMPILED } from './timeline';

interface FactoryState {
  time: number;
  duration: number;
  paused: boolean;
  hoverHeld: boolean;
  resumeAfterPanel: boolean;
  selected: StationId | null;
  hovered: StationId | null;
  view: View;
  tick( dt: number ): void;
  toggle(): void;
  restart(): void;
  seekScene( id: Scene[ 'id' ] ): void;
  seekTime( t: number ): void;
  hoverHold( on: boolean ): void;
  openPanel( id: StationId ): void;
  closePanel(): void;
  setHovered( id: StationId | null ): void;
  setView( view: View ): void;
}

/** What the step bubble shows: the card's steps in words, or the PHP behind them. */
export type View = 'steps' | 'code';

const VIEW_KEY = 'factory-view';

/** The viewer's last choice of view, if the browser kept it. */
function savedView(): View {
  try {
    return 'code' === window.localStorage.getItem( VIEW_KEY ) ? 'code' : 'steps';
  } catch {
    return 'steps';
  }
}

/** True under prefers-reduced-motion: the app loads paused on the final state, and the belts and rollers never move. */
export const reduced = typeof window !== 'undefined' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

/** The scripted clock and the pause rules: the user's own Play/Pause wins over a hover, and a panel resumes what it paused. */
export const useFactory = create<FactoryState>()( ( set, get ) => ( {
  time: reduced ? COMPILED.duration - 0.001 : 0,
  duration: COMPILED.duration,
  paused: reduced,
  hoverHeld: false,
  resumeAfterPanel: false,
  selected: null,
  hovered: null,
  view: typeof window !== 'undefined' ? savedView() : 'steps',

  // A long frame, such as a tab coming back, advances at most 0.1 s, so nothing jumps.
  tick: ( dt ) => {
    if ( get().paused ) {
      return;
    }
    set( { time: ( get().time + Math.min( dt, 0.1 ) ) % COMPILED.duration } );
  },
  toggle: () => set( { hoverHeld: false, paused: ! get().paused } ),
  restart: () => set( { time: 0, paused: false, hoverHeld: false } ),
  seekScene: ( id ) => set( { time: COMPILED.sceneStart[ id ] } ),
  seekTime: ( t ) => set( { time: Math.min( Math.max( t, 0 ), COMPILED.duration - 0.001 ) } ),
  hoverHold: ( on ) => {
    const s = get();
    if ( on && ! s.paused ) {
      set( { hoverHeld: true, paused: true } );
    }
    if ( ! on && s.hoverHeld ) {
      set( { hoverHeld: false, paused: false } );
    }
  },
  openPanel: ( id ) => {
    const s = get();
    set( { selected: id, resumeAfterPanel: ! s.paused || s.hoverHeld, hoverHeld: false, paused: true } );
  },
  closePanel: () => {
    const s = get();
    set( { selected: null, paused: s.resumeAfterPanel ? false : s.paused, resumeAfterPanel: false } );
  },
  setHovered: ( id ) => set( { hovered: id } ),
  setView: ( view ) => {
    set( { view } );
    try {
      window.localStorage.setItem( VIEW_KEY, view );
    } catch {
      // A blocked store only forgets the choice on reload.
    }
  },
} ) );
