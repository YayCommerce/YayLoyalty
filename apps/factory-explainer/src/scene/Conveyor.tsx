import { useMemo, useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { BELT_Y, CONVEYORS, type Conveyor as ConveyorData } from '../factory.config';
import { beltCurve } from '../timeline';
import { reduced, useFactory } from '../store';
import { Ink } from './Ink';
import { material } from './materials';

const WIDTH = 0.7;
const BEVEL = 0.04;
const SAMPLES_PER_UNIT = 6;
const TREAD = 0.18; // world units between treads
const SCROLL = 0.8; // world units per second of story time
const ROLLER = 0.09;

/** A belt along the curve, swept from a profile with bevelled top edges; v runs along the belt in treads. */
function beltGeometry( curve: THREE.CatmullRomCurve3 ): THREE.BufferGeometry {
  const n = Math.max( 2, Math.ceil( curve.getLength() * SAMPLES_PER_UNIT ) );
  const h = WIDTH / 2;
  const profile = [ [ -h, 0.05 ], [ -h, BELT_Y - BEVEL ], [ -h + BEVEL, BELT_Y ], [ h - BEVEL, BELT_Y ], [ h, BELT_Y - BEVEL ], [ h, 0.05 ] ];
  const length = curve.getLength();
  const positions: number[] = [];
  const uvs: number[] = [];
  const index: number[] = [];
  for ( let i = 0; i <= n; i++ ) {
    const u = i / n;
    const p = curve.getPointAt( u );
    const tangent = curve.getTangentAt( u );
    const side = new THREE.Vector3( -tangent.z, 0, tangent.x ).normalize();
    profile.forEach( ( [ s, y ], k ) => {
      positions.push( p.x + side.x * s, y, p.z + side.z * s );
      uvs.push( k / ( profile.length - 1 ), ( u * length ) / TREAD );
    } );
    if ( i < n ) {
      for ( let k = 0; k < profile.length - 1; k++ ) {
        const a = i * profile.length + k;
        const b = a + profile.length;
        index.push( a, b, a + 1, a + 1, b, b + 1 );
      }
    }
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute( 'position', new THREE.Float32BufferAttribute( positions, 3 ) );
  g.setAttribute( 'uv', new THREE.Float32BufferAttribute( uvs, 2 ) );
  g.setIndex( index );
  g.computeVertexNormals();
  return g;
}

/** One greyscale tread tile shared by every belt; each belt's material colour tints it. */
const tread = ( () => {
  const canvas = document.createElement( 'canvas' );
  canvas.width = 8;
  canvas.height = 32;
  const ctx = canvas.getContext( '2d' );
  if ( ctx ) {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect( 0, 0, 8, 32 );
    ctx.fillStyle = '#dcdcdc';
    ctx.fillRect( 0, 0, 8, 3 );
  }
  const t = new THREE.CanvasTexture( canvas );
  t.wrapS = THREE.RepeatWrapping;
  t.wrapT = THREE.RepeatWrapping;
  t.colorSpace = THREE.SRGBColorSpace;
  t.magFilter = THREE.LinearFilter;
  t.anisotropy = 4;
  return t;
} )();

/** Story-time distance the belts have travelled; zero under reduced motion, so they stand still. */
function travel(): number {
  return reduced ? 0 : useFactory.getState().time * SCROLL;
}

/** A metal end roller across the belt, spinning with the tread. */
function Roller( { at, tangent }: { at: THREE.Vector3; tangent: THREE.Vector3 } ) {
  const spin = useRef<THREE.Group>( null );
  const metal = useMemo( () => material( 'metal' ), [] );
  useFrame( () => {
    if ( spin.current ) {
      spin.current.rotation.z = -travel() / ROLLER;
    }
  } );
  return (
    <group position={ [ at.x, BELT_Y - ROLLER + 0.012, at.z ] } rotation-y={ Math.atan2( -tangent.z, tangent.x ) }>
      <group ref={ spin }>
        <mesh rotation-x={ Math.PI / 2 } material={ metal } castShadow>
          <cylinderGeometry args={ [ ROLLER, ROLLER, WIDTH + 0.04, 20 ] } />
          <Ink />
        </mesh>
      </group>
    </group>
  );
}

const COLORS = { main: '#5a6683', reject: '#8a94a6', return: '#ef4b4b' };

export function Conveyor( { conveyor }: { conveyor: ConveyorData } ) {
  const curve = useMemo( () => beltCurve( conveyor ), [ conveyor ] );
  const geometry = useMemo( () => beltGeometry( curve ), [ curve ] );
  // Belts that meet at a station overlap there; a per-belt depth offset keeps them from z-fighting.
  const layer = CONVEYORS.indexOf( conveyor ) + 1;
  const belt = useMemo( () => {
    const m = material( 'graphite', COLORS[ conveyor.kind ] );
    m.map = tread;
    m.side = THREE.DoubleSide;
    m.polygonOffset = true;
    m.polygonOffsetFactor = -layer;
    m.polygonOffsetUnits = -layer;
    return m;
  }, [ conveyor.kind, layer ] );
  useFrame( () => {
    tread.offset.y = -travel() / TREAD;
  } );
  return (
    <>
      <mesh geometry={ geometry } material={ belt } castShadow receiveShadow>
        <Ink />
      </mesh>
      <Roller at={ curve.getPointAt( 0 ) } tangent={ curve.getTangentAt( 0 ) } />
      <Roller at={ curve.getPointAt( 1 ) } tangent={ curve.getTangentAt( 1 ) } />
    </>
  );
}
