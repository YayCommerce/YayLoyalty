import { useEffect, type ComponentRef, type ReactNode } from 'react';
import * as THREE from 'three';
import { Canvas, useThree } from '@react-three/fiber';
import { MapControls } from '@react-three/drei';

/** Floor bounds in world units (the 2D floor, 0–1240 × 30–530, divided by 40). */
export const FLOOR = { x0: 0, x1: 31, z0: 0.75, z1: 13.25 };
const TARGET = new THREE.Vector3( ( FLOOR.x0 + FLOOR.x1 ) / 2, 0, ( FLOOR.z0 + FLOOR.z1 ) / 2 );
const DISTANCE = 40;
const PAN_MARGIN = 4;

/** The zoom at which the floor's corners fill 90 % of the canvas, seen from the iso camera. */
function fitZoom( camera: THREE.Camera, width: number, height: number ): number {
  const view = camera.matrixWorldInverse;
  const xs: number[] = [];
  const ys: number[] = [];
  for ( const x of [ FLOOR.x0, FLOOR.x1 ] ) {
    for ( const z of [ FLOOR.z0, FLOOR.z1 ] ) {
      const p = new THREE.Vector3( x, 0, z ).applyMatrix4( view );
      xs.push( p.x );
      ys.push( p.y );
    }
  }
  const spanX = Math.max( ...xs ) - Math.min( ...xs );
  const spanY = Math.max( ...ys ) - Math.min( ...ys );
  return 0.9 * Math.min( width / spanX, height / spanY );
}

/** Places the orthographic camera at the true iso angle, fits the floor, and keeps pan and zoom in bounds. */
function IsoRig() {
  const camera = useThree( ( s ) => s.camera ) as THREE.OrthographicCamera;
  const size = useThree( ( s ) => s.size );
  // makeDefault registers the controls on the store, so the effect re-runs once they exist.
  const controls = useThree( ( s ) => s.controls ) as ComponentRef<typeof MapControls> | null;

  useEffect( () => {
    camera.position.copy( TARGET ).add( new THREE.Vector3( DISTANCE, DISTANCE, DISTANCE ) );
    camera.lookAt( TARGET );
    camera.updateMatrixWorld();
    const zoom = fitZoom( camera, size.width, size.height );
    camera.zoom = zoom;
    camera.near = 0.1;
    camera.far = 200;
    camera.updateProjectionMatrix();
    const c = controls;
    if ( c ) {
      c.target.copy( TARGET );
      c.minZoom = zoom * 0.6;
      c.maxZoom = zoom * 2.5;
      c.update();
    }
  }, [ camera, controls, size.width, size.height ] );

  // Pans move target and camera together; clamping the target clamps both.
  const clamp = () => {
    const c = controls;
    if ( ! c ) {
      return;
    }
    const t = c.target;
    const x = THREE.MathUtils.clamp( t.x, FLOOR.x0 - PAN_MARGIN, FLOOR.x1 + PAN_MARGIN );
    const z = THREE.MathUtils.clamp( t.z, FLOOR.z0 - PAN_MARGIN, FLOOR.z1 + PAN_MARGIN );
    if ( x !== t.x || z !== t.z || t.y !== 0 ) {
      const shift = new THREE.Vector3( x - t.x, -t.y, z - t.z );
      t.add( shift );
      camera.position.add( shift );
    }
  };

  return <MapControls makeDefault enableRotate={ false } screenSpacePanning onChange={ clamp } />;
}

export function Stage( { children }: { children?: ReactNode } ) {
  return (
    <Canvas shadows={ { type: THREE.PCFSoftShadowMap } } orthographic dpr={ [ 1, 2 ] } camera={ { position: [ 55, 40, 47 ] } }>
      <color attach="background" args={ [ '#f4f1ec' ] } />
      <IsoRig />
      { children ?? (
        <>
          <ambientLight intensity={ 0.8 } />
          <mesh rotation-x={ -Math.PI / 2 } position={ [ TARGET.x, 0, TARGET.z ] }>
            <planeGeometry args={ [ FLOOR.x1 - FLOOR.x0, FLOOR.z1 - FLOOR.z0 ] } />
            <meshStandardMaterial color="#ece6dc" roughness={ 0.8 } />
          </mesh>
        </>
      ) }
    </Canvas>
  );
}
