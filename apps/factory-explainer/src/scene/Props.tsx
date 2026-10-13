import { useMemo, type ComponentType } from 'react';
import { PROPS, type Prop } from '../factory.config';
import { Ink } from './Ink';
import { material } from './materials';
import { Kit, Part } from './stations/Kit';

const WOOD = '#d9a66b';
const CONE = '#ff8a3d';
const TRAY = 6;

/** A wooden pallet with two cartons on it. */
function Pallet() {
  return (
    <group>
      { [ -0.42, 0, 0.42 ].map( ( z ) => <Part key={ `r${ z }` } args={ [ 1.2, 0.1, 0.14 ] } position={ [ 0, 0.05, z ] } kind="cardboard" color={ WOOD } radius={ 0.03 } /> ) }
      { [ -0.4, -0.13, 0.13, 0.4 ].map( ( x ) => <Part key={ `s${ x }` } args={ [ 0.22, 0.05, 1 ] } position={ [ x, 0.125, 0 ] } kind="cardboard" color={ WOOD } radius={ 0.02 } /> ) }
      <Kit name="box-small" fit={ [ 0.55, 0.4, 0.46 ] } kind="cardboard" position={ [ -0.27, 0.15, 0.1 ] } />
      <Kit name="box-small" fit={ [ 0.5, 0.36, 0.42 ] } kind="cardboard" position={ [ 0.3, 0.15, -0.12 ] } rotation={ 0.3 } />
    </group>
  );
}

/** A parked forklift; its forks point along local +x. */
function Forklift() {
  const tyre = useMemo( () => material( 'graphite' ), [] );
  return (
    <group>
      <Part args={ [ 1, 0.45, 0.8 ] } position={ [ -0.1, 0.38, 0 ] } kind="grey" />
      <Part args={ [ 0.35, 0.3, 0.7 ] } position={ [ -0.45, 0.75, 0 ] } kind="grey" />
      { [ [ -0.5, -0.33 ], [ -0.5, 0.33 ], [ 0.2, -0.33 ], [ 0.2, 0.33 ] ].map( ( [ x, z ] ) => (
        <Part key={ `${ x }${ z }` } args={ [ 0.05, 0.75, 0.05 ] } position={ [ x, 0.98, z ] } kind="graphite" radius={ 0.02 } />
      ) ) }
      <Part args={ [ 0.8, 0.05, 0.75 ] } position={ [ -0.15, 1.37, 0 ] } kind="graphite" radius={ 0.02 } />
      <Part args={ [ 0.08, 1.3, 0.6 ] } position={ [ 0.45, 0.7, 0 ] } kind="graphite" radius={ 0.03 } />
      { [ -0.18, 0.18 ].map( ( z ) => <Part key={ z } args={ [ 0.7, 0.04, 0.1 ] } position={ [ 0.82, 0.08, z ] } kind="graphite" radius={ 0.015 } /> ) }
      { [ [ -0.4, -0.42 ], [ -0.4, 0.42 ], [ 0.25, -0.42 ], [ 0.25, 0.42 ] ].map( ( [ x, z ] ) => (
        <mesh key={ `${ x }${ z }` } position={ [ x, 0.17, z ] } rotation-x={ Math.PI / 2 } material={ tyre } castShadow>
          <cylinderGeometry args={ [ 0.17, 0.17, 0.12, 20 ] } />
          <Ink />
        </mesh>
      ) ) }
    </group>
  );
}

/** A low cable tray along local x, on short feet. */
function CableTray() {
  return (
    <group>
      <Part args={ [ TRAY, 0.04, 0.4 ] } position={ [ 0, 0.18, 0 ] } kind="grey" radius={ 0.015 } />
      { [ -0.19, 0.19 ].map( ( z ) => <Part key={ z } args={ [ TRAY, 0.1, 0.03 ] } position={ [ 0, 0.23, z ] } kind="grey" radius={ 0.012 } /> ) }
      { [ -0.08, 0, 0.08 ].map( ( z ) => <Part key={ `c${ z }` } args={ [ TRAY - 0.1, 0.06, 0.06 ] } position={ [ 0, 0.23, z ] } kind="graphite" radius={ 0.028 } /> ) }
      { [ -2.8, -1.4, 0, 1.4, 2.8 ].map( ( x ) => <Part key={ `f${ x }` } args={ [ 0.06, 0.16, 0.3 ] } position={ [ x, 0.08, 0 ] } kind="graphite" radius={ 0.02 } /> ) }
    </group>
  );
}

const DRAW: Record<Prop[ 'kind' ], ComponentType> = {
  pallet: Pallet,
  forklift: Forklift,
  cone: () => <Kit name="cone" fit={ [ 0.36, 0.38, 0.36 ] } kind="accent" color={ CONE } />,
  'cable-tray': CableTray,
};

/** Low-contrast set dressing from the config. */
export function Props() {
  return (
    <>
      { PROPS.map( ( p, i ) => {
        const Draw = DRAW[ p.kind ];
        return (
          <group key={ i } position={ [ p.position[ 0 ], 0, p.position[ 1 ] ] } rotation-y={ p.rotation }>
            <Draw />
          </group>
        );
      } ) }
    </>
  );
}
