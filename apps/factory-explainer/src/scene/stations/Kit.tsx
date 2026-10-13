import { Suspense, useEffect, useMemo, useRef, type ReactNode } from 'react';
import * as THREE from 'three';
import { useFrame } from '@react-three/fiber';
import { RoundedBox } from '@react-three/drei';
import type { Station } from '../../factory.config';
import { useModel, type ModelKey } from '../../models';
import { useFactory } from '../../store';
import { activeStation, COMPILED, entryAt } from '../../timeline';
import { Ink } from '../Ink';
import { ACCENTS, material, toon, type MaterialKind } from '../materials';

export const PLINTH = 0.2;

type Vec3 = [ number, number, number ];

/** Parts thinner than this skip the ink outline: it would swamp them, and it saves draw calls. */
const INK_MIN = 0.05;

/** A beveled box in one of the shared materials, inked. */
export function Part( { args, position, rotation, kind, color, radius = 0.06, shadow = true }: {
  args: Vec3; position?: Vec3; rotation?: Vec3; kind: MaterialKind; color?: string; radius?: number; shadow?: boolean;
} ) {
  const m = useMemo( () => material( kind, color ), [ kind, color ] );
  return (
    <RoundedBox args={ args } radius={ Math.min( radius, ...args.map( ( a ) => a / 2 - 0.001 ) ) } smoothness={ 3 } position={ position } rotation={ rotation } material={ m } castShadow={ shadow } receiveShadow>
      { Math.min( ...args ) >= INK_MIN && <Ink /> }
    </RoundedBox>
  );
}

/** The low graphite plinth every built station stands on; the belt runs onto it. */
export function Plinth( { s }: { s: Station } ) {
  const [ w, d ] = s.size;
  return <Part args={ [ w, PLINTH, d ] } position={ [ 0, PLINTH / 2, 0 ] } kind="graphite" />;
}

interface KitProps { name: ModelKey; fit: Vec3; kind: MaterialKind; color?: string; position?: Vec3; rotation?: number; stretch?: boolean }

/** The stand-in for a kit model: a beveled box filling its fit box. */
function Fallback( { fit, kind, color, position = [ 0, 0, 0 ], rotation = 0 }: KitProps ) {
  return <Part args={ fit } position={ [ position[ 0 ], position[ 1 ] + fit[ 1 ] / 2, position[ 2 ] ] } rotation={ [ 0, rotation, 0 ] } kind={ kind } color={ color } />;
}

function KitModel( { name, fit, kind, color, position, rotation = 0, stretch = false }: KitProps ) {
  const gltf = useModel( name );
  const parts = useMemo( () => {
    if ( ! gltf ) {
      return null;
    }
    // Fit inside the target box, standing on y = 0 and centred on x and z.
    const root = gltf.scene.clone( true );
    const box = new THREE.Box3().setFromObject( root );
    const size = box.getSize( new THREE.Vector3() );
    const ratio = new THREE.Vector3( fit[ 0 ] / size.x, fit[ 1 ] / size.y, fit[ 2 ] / size.z );
    const scale = stretch ? ratio : new THREE.Vector3().setScalar( Math.min( ratio.x, ratio.y, ratio.z ) );
    const centre = box.getCenter( new THREE.Vector3() );
    root.position.set( -centre.x * scale.x, -box.min.y * scale.y, -centre.z * scale.z );
    root.scale.copy( scale );
    root.updateMatrixWorld( true );
    // Each mesh becomes JSX with its baked transform, so it can carry an ink outline.
    const m = material( kind, color );
    const out: { geometry: THREE.BufferGeometry; matrix: THREE.Matrix4 }[] = [];
    root.traverse( ( o ) => {
      if ( o instanceof THREE.Mesh ) {
        out.push( { geometry: o.geometry, matrix: o.matrixWorld.clone() } );
      }
    } );
    return { material: m, meshes: out };
  }, [ gltf, fit[ 0 ], fit[ 1 ], fit[ 2 ], kind, color, stretch ] );

  if ( ! parts ) {
    return <Fallback name={ name } fit={ fit } kind={ kind } color={ color } position={ position } rotation={ rotation } />;
  }
  return (
    <group position={ position } rotation-y={ rotation }>
      { parts.meshes.map( ( p, i ) => (
        <mesh key={ i } geometry={ p.geometry } material={ parts.material } matrix={ p.matrix } matrixAutoUpdate={ false } castShadow receiveShadow>
          <Ink />
        </mesh>
      ) ) }
    </group>
  );
}

/** How many models are still loading; the screenshot harness waits for zero. */
export const kitLoading = { count: 0 };

/** Renders a loading fallback and counts it in kitLoading while it is mounted. */
export function Pending( { children }: { children: ReactNode } ) {
  useEffect( () => {
    kitLoading.count++;
    return () => {
      kitLoading.count--;
    };
  }, [] );
  return children;
}

/**
 * A kit model fitted inside `fit` (width, height, depth, before `rotation`), standing on `position`: uniformly, or filling it with `stretch`.
 * While it loads, or if it fails, a beveled box of that size stands in.
 */
export function Kit( props: KitProps ) {
  return (
    <Suspense fallback={ <Pending><Fallback { ...props } /></Pending> }>
      <KitModel { ...props } />
    </Suspense>
  );
}

const LED_ON = 3;
const LED_IDLE = 0.35;

/** A status light on a short stalk at the plinth's front-left corner, in the station's accent; it glows past the bloom threshold while the station works. */
export function StatusLed( { s }: { s: Station } ) {
  const led = useRef<THREE.MeshToonMaterial>( null );
  const [ w, d ] = s.size;
  useFrame( () => {
    if ( led.current ) {
      const active = activeStation( entryAt( COMPILED.entries, useFactory.getState().time ) ) === s.id;
      led.current.emissiveIntensity = active ? LED_ON : LED_IDLE;
    }
  } );
  return (
    <group position={ [ -w / 2 + 0.22, PLINTH, d / 2 - 0.22 ] }>
      <Part args={ [ 0.08, 0.3, 0.08 ] } position={ [ 0, 0.15, 0 ] } kind="graphite" radius={ 0.03 } />
      <mesh position={ [ 0, 0.36, 0 ] }>
        <sphereGeometry args={ [ 0.08, 20, 12 ] } />
        <meshToonMaterial ref={ led } { ...toon } color={ ACCENTS[ s.type ] } emissive={ ACCENTS[ s.type ] } emissiveIntensity={ LED_IDLE } toneMapped={ false } />
      </mesh>
    </group>
  );
}
