import { useMemo } from 'react';
import * as THREE from 'three';
import { BELT_Y, type Conveyor as ConveyorData } from '../factory.config';
import { beltCurve } from '../timeline';

const WIDTH = 0.7;
const SAMPLES_PER_UNIT = 6;

/** A belt-shaped solid along the curve: top, two sides and rounded ends come from a swept rectangle profile. */
function beltGeometry( curve: THREE.CatmullRomCurve3 ): THREE.BufferGeometry {
  const n = Math.max( 2, Math.ceil( curve.getLength() * SAMPLES_PER_UNIT ) );
  const profile = [ [ -WIDTH / 2, 0.05 ], [ -WIDTH / 2, BELT_Y ], [ WIDTH / 2, BELT_Y ], [ WIDTH / 2, 0.05 ] ];
  const positions: number[] = [];
  const index: number[] = [];
  for ( let i = 0; i <= n; i++ ) {
    const u = i / n;
    const p = curve.getPointAt( u );
    const tangent = curve.getTangentAt( u );
    const side = new THREE.Vector3( -tangent.z, 0, tangent.x ).normalize();
    for ( const [ s, y ] of profile ) {
      positions.push( p.x + side.x * s, y, p.z + side.z * s );
    }
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
  g.setIndex( index );
  g.computeVertexNormals();
  return g;
}

const COLORS = { main: '#5b6270', reject: '#7a7f88', return: '#b04a4a' };

export function Conveyor( { conveyor }: { conveyor: ConveyorData } ) {
  const geometry = useMemo( () => beltGeometry( beltCurve( conveyor ) ), [ conveyor ] );
  return (
    <mesh geometry={ geometry } castShadow receiveShadow>
      <meshStandardMaterial color={ COLORS[ conveyor.kind ] } roughness={ 0.7 } side={ THREE.DoubleSide } />
    </mesh>
  );
}
