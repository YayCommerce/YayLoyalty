import { useRef } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { Edges, RoundedBox } from '@react-three/drei';
import type { Station as StationData, StationType } from '../factory.config';
import { propPose } from '../timeline';
import { useFactory } from '../store';

/** One accent per machine, HSL saturation ≤ 60 %. */
export const ACCENTS: Record<StationType, string> = {
  intake: '#3f9e96',
  scoring: '#7f6bb8',
  gate: '#c99b45',
  ledger: '#5b63b0',
  warehouse: '#8a8f98',
  vault: '#8a8f98',
  return: '#b65050',
  unbuilt: '#6f9fd8',
};

const PLINTH = 0.2;
const BODY = '#a9afb8';
const GRAPHITE = '#4b5260';
const DENY = new THREE.Color( '#c0392b' );

/** The gate: two posts and an arm across the belt that lifts on a pass and flashes red on a deny. */
function GateParts( { s }: { s: StationData } ) {
  const arm = useRef<THREE.Group>( null );
  const material = useRef<THREE.MeshStandardMaterial>( null );
  const base = new THREE.Color( ACCENTS.gate );
  const [ w, d ] = s.size;
  useFrame( () => {
    const p = propPose( useFactory.getState().time );
    if ( arm.current ) {
      arm.current.rotation.x = p.gateArm;
    }
    material.current?.color.copy( base ).lerp( DENY, p.gateDeny );
  } );
  return (
    <group>
      { [ -1, 1 ].map( ( k ) => (
        <RoundedBox key={ k } args={ [ 0.25, 1.1, 0.25 ] } radius={ 0.06 } position={ [ 0.3, 0.55 + PLINTH, k * ( d / 2 - 0.15 ) ] } castShadow>
          <meshStandardMaterial color={ GRAPHITE } roughness={ 0.6 } />
        </RoundedBox>
      ) ) }
      <group ref={ arm } position={ [ 0.3, 0.95 + PLINTH, -( d / 2 - 0.15 ) ] }>
        <RoundedBox args={ [ 0.14, 0.14, d - 0.3 ] } radius={ 0.05 } position={ [ 0, 0, ( d - 0.3 ) / 2 ] } castShadow>
          <meshStandardMaterial ref={ material } color={ ACCENTS.gate } roughness={ 0.55 } />
        </RoundedBox>
      </group>
      <RoundedBox args={ [ w * 0.4, 0.08, d ] } radius={ 0.03 } position={ [ -w * 0.25, PLINTH + 0.04, 0 ] }>
        <meshStandardMaterial color={ ACCENTS.gate } roughness={ 0.7 } />
      </RoundedBox>
    </group>
  );
}

/** The scoring dial on the housing's front face; it turns while EarningService works. */
function Dial( { s }: { s: StationData } ) {
  const dial = useRef<THREE.Group>( null );
  const [ , d, h ] = s.size;
  useFrame( () => {
    if ( dial.current ) {
      dial.current.rotation.z = -propPose( useFactory.getState().time ).dial;
    }
  } );
  return (
    <group ref={ dial } position={ [ 0, PLINTH + h * 0.6, -d / 2 + d * 0.45 + 0.03 ] }>
      <mesh rotation-x={ Math.PI / 2 } castShadow>
        <cylinderGeometry args={ [ 0.45, 0.45, 0.06, 40 ] } />
        <meshStandardMaterial color={ ACCENTS.scoring } roughness={ 0.5 } />
      </mesh>
      <RoundedBox args={ [ 0.08, 0.38, 0.05 ] } radius={ 0.02 } position={ [ 0, 0.17, 0.05 ] }>
        <meshStandardMaterial color="#f4f1ec" roughness={ 0.5 } />
      </RoundedBox>
    </group>
  );
}

/** The ledger press head over the box spot; it drops on each write. */
function PressHead( { s }: { s: StationData } ) {
  const head = useRef<THREE.Group>( null );
  const [ w, , h ] = s.size;
  const top = PLINTH + h + 0.2;
  useFrame( () => {
    if ( head.current ) {
      head.current.position.y = top - propPose( useFactory.getState().time ).pressHead * ( top - 0.95 );
    }
  } );
  return (
    <group ref={ head } position={ [ 0, top, 0 ] }>
      <RoundedBox args={ [ w * 0.45, 0.3, 0.9 ] } radius={ 0.08 } castShadow>
        <meshStandardMaterial color={ GRAPHITE } roughness={ 0.5 } metalness={ 0.6 } />
      </RoundedBox>
    </group>
  );
}

/**
 * A station in beveled primitives: a low plinth the belt runs onto, and a housing on the back half,
 * so a box resting at the station's centre stays in view of the iso camera.
 */
export function Station( { station: s }: { station: StationData } ) {
  const [ w, d, h ] = s.size;
  const unbuilt = 'unbuilt' === s.type;
  const housingDepth = d * 0.45;

  if ( unbuilt ) {
    return (
      <group position={ [ s.position[ 0 ], 0, s.position[ 1 ] ] }>
        <RoundedBox args={ [ w, h, d ] } radius={ 0.1 } position={ [ 0, h / 2, 0 ] }>
          <meshStandardMaterial color={ ACCENTS.unbuilt } transparent opacity={ 0.18 } roughness={ 0.6 } depthWrite={ false } />
          <Edges color={ ACCENTS.unbuilt } />
        </RoundedBox>
      </group>
    );
  }

  return (
    <group position={ [ s.position[ 0 ], 0, s.position[ 1 ] ] }>
      <RoundedBox args={ [ w, PLINTH, d ] } radius={ 0.06 } position={ [ 0, PLINTH / 2, 0 ] } receiveShadow castShadow>
        <meshStandardMaterial color={ GRAPHITE } roughness={ 0.7 } />
      </RoundedBox>
      { 'gate' !== s.type && (
        <>
          <RoundedBox args={ [ w, h, housingDepth ] } radius={ 0.1 } smoothness={ 3 } position={ [ 0, PLINTH + h / 2, -d / 2 + housingDepth / 2 ] } castShadow receiveShadow>
            <meshStandardMaterial color={ BODY } roughness={ 0.65 } metalness={ 'vault' === s.type ? 0.5 : 0 } />
          </RoundedBox>
          <RoundedBox args={ [ w * 0.9, 0.12, 0.04 ] } radius={ 0.02 } position={ [ 0, PLINTH + h - 0.18, -d / 2 + housingDepth + 0.02 ] }>
            <meshStandardMaterial color={ ACCENTS[ s.type ] } roughness={ 0.6 } />
          </RoundedBox>
        </>
      ) }
      { 'gate' === s.type && <GateParts s={ s } /> }
      { 'scoring' === s.type && <Dial s={ s } /> }
      { 'ledger' === s.type && <PressHead s={ s } /> }
    </group>
  );
}
