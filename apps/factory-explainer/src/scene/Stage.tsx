import { useEffect, type ComponentRef, type ReactNode } from 'react';
import * as THREE from 'three';
import { Canvas, useThree } from '@react-three/fiber';
import { MapControls } from '@react-three/drei';
import { Selection } from '@react-three/postprocessing';
import { Effects } from './Effects';
import { Lighting } from './Lighting';
import { rig } from './rig';
import { useFactory } from '../store';

/** Floor bounds in world units (the 2D floor, 0–1240 × 30–530, divided by 40). */
export const FLOOR = { x0: 0, x1: 31, z0: 0.75, z1: 13.25 };
export const TARGET = new THREE.Vector3( ( FLOOR.x0 + FLOOR.x1 ) / 2, 0, ( FLOOR.z0 + FLOOR.z1 ) / 2 );
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
  const canvas = useThree( ( s ) => s.gl.domElement );
  // makeDefault registers the controls on the store, so the effect re-runs once they exist.
  const controls = useThree( ( s ) => s.controls ) as ComponentRef<typeof MapControls> | null;

  useEffect( () => {
    camera.position.copy( TARGET ).add( new THREE.Vector3( DISTANCE, DISTANCE, DISTANCE ) );
    camera.lookAt( TARGET );
    camera.updateMatrixWorld();
    // Fit the floor into the space below the toolbar, and centre it there with a view offset.
    const toolbar = document.querySelector( '[data-testid="toolbar"]' );
    const top = toolbar ? Math.max( 0, toolbar.getBoundingClientRect().bottom - canvas.getBoundingClientRect().top + 8 ) : 0;
    camera.setViewOffset( size.width, size.height, 0, -top / 2, size.width, size.height );
    const zoom = fitZoom( camera, size.width, size.height - top );
    camera.zoom = zoom;
    camera.near = 0.1;
    camera.far = 200;
    camera.updateProjectionMatrix();
    rig.camera = camera;
    rig.canvas = canvas;
    const c = controls;
    if ( c ) {
      c.target.copy( TARGET );
      c.minZoom = zoom * 0.6;
      c.maxZoom = zoom * 2.5;
      c.update();
    }
  }, [ camera, canvas, controls, size.width, size.height ] );

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
    // Hovering anywhere over the factory holds the loop, so the scene can be read; leaving resumes it.
    <div className="stage" onPointerEnter={ () => useFactory.getState().hoverHold( true ) } onPointerLeave={ () => useFactory.getState().hoverHold( false ) }>
    <Canvas shadows={ { type: THREE.PCFShadowMap } } orthographic dpr={ [ 1, 1.25 ] } camera={ { position: [ 55, 40, 47 ] } }>
      <color attach="background" args={ [ '#f4f1ec' ] } />
      <IsoRig />
      <Lighting />
      { /* Selection wraps the stations' Selects and the composer's Outline. */ }
      <Selection>
        <Effects />
        { children }
      </Selection>
    </Canvas>
    </div>
  );
}
