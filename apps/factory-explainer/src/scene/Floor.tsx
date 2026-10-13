import { useMemo } from 'react';
import * as THREE from 'three';
import { RoundedBox } from '@react-three/drei';
import { REJECT_BIN, STATIONS } from '../factory.config';
import { Ink } from './Ink';
import { material, toon } from './materials';
import { FLOOR } from './Stage';

const MARGIN = 1.2;
const PX = 64; // texture pixels per world unit
const GATE_STRIPE = 0.4;
const INSET = 0.12; // the slab's bevel radius

/** Floor bounds including the margin: the slab's footprint in world units. */
const SLAB = { x0: FLOOR.x0 - MARGIN, x1: FLOOR.x1 + MARGIN, z0: FLOOR.z0 - MARGIN, z1: FLOOR.z1 + MARGIN };

/** Paints the floor once: tile grid, lane lines, the gate's safety stripes and a zone name in front of each station. */
function floorTexture(): THREE.CanvasTexture {
  const w = SLAB.x1 - SLAB.x0;
  const d = SLAB.z1 - SLAB.z0;
  const canvas = document.createElement( 'canvas' );
  canvas.width = Math.round( w * PX );
  canvas.height = Math.round( d * PX );
  const ctx = canvas.getContext( '2d' );
  const texture = new THREE.CanvasTexture( canvas );
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.anisotropy = 8;
  if ( ! ctx ) {
    return texture;
  }
  // World x, z to canvas pixels.
  const X = ( x: number ) => ( x - SLAB.x0 ) * PX;
  const Z = ( z: number ) => ( z - SLAB.z0 ) * PX;

  ctx.fillStyle = '#fff3df';
  ctx.fillRect( 0, 0, canvas.width, canvas.height );

  ctx.strokeStyle = '#f2e2c6';
  ctx.lineWidth = 1.5;
  for ( let x = Math.ceil( SLAB.x0 ); x <= SLAB.x1; x++ ) {
    ctx.beginPath();
    ctx.moveTo( X( x ), 0 );
    ctx.lineTo( X( x ), canvas.height );
    ctx.stroke();
  }
  for ( let z = Math.ceil( SLAB.z0 ); z <= SLAB.z1; z++ ) {
    ctx.beginPath();
    ctx.moveTo( 0, Z( z ) );
    ctx.lineTo( canvas.width, Z( z ) );
    ctx.stroke();
  }

  // Lane lines: the customers' walkway, and the machine floor.
  ctx.strokeStyle = '#e6cfa8';
  ctx.lineWidth = 0.07 * PX;
  ctx.setLineDash( [ 0.5 * PX, 0.3 * PX ] );
  ctx.strokeRect( X( -0.7 ), Z( 1.6 ), 2.9 * PX, 11.3 * PX );
  ctx.setLineDash( [] );
  ctx.strokeRect( X( 2.6 ), Z( 0.95 ), 28.4 * PX, 12.1 * PX );

  // Yellow and black stripes around the gate's footprint.
  const gate = STATIONS.find( ( s ) => 'gate' === s.type );
  if ( gate ) {
    const [ gx, gz ] = gate.position;
    const [ gw, gd ] = gate.size;
    const x0 = X( gx - gw / 2 - GATE_STRIPE );
    const z0 = Z( gz - gd / 2 - GATE_STRIPE );
    const bw = ( gw + 2 * GATE_STRIPE ) * PX;
    const bd = ( gd + 2 * GATE_STRIPE ) * PX;
    ctx.save();
    ctx.beginPath();
    ctx.rect( x0, z0, bw, bd );
    ctx.clip();
    ctx.fillStyle = '#ffc233';
    ctx.fillRect( x0, z0, bw, bd );
    ctx.strokeStyle = '#2a2e38';
    ctx.lineWidth = 0.14 * PX;
    for ( let k = -bd; k < bw + bd; k += 0.4 * PX ) {
      ctx.beginPath();
      ctx.moveTo( x0 + k, z0 );
      ctx.lineTo( x0 + k + bd, z0 + bd );
      ctx.stroke();
    }
    ctx.restore();
  }

  // Zone names, painted in front of each station.
  ctx.fillStyle = '#dcc39b';
  ctx.font = `700 ${ Math.round( 0.3 * PX ) }px system-ui, -apple-system, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  for ( const s of STATIONS ) {
    const z = s.position[ 1 ] + s.size[ 1 ] / 2 + ( 'gate' === s.type ? GATE_STRIPE : 0 ) + 0.4;
    ctx.fillText( s.role.toUpperCase(), X( s.position[ 0 ] ), Z( z ) );
  }

  texture.needsUpdate = true;
  return texture;
}

/** The factory platform: a thick beveled slab whose top face is y = 0, with the painted floor on top. */
export function Floor() {
  const w = SLAB.x1 - SLAB.x0;
  const d = SLAB.z1 - SLAB.z0;
  const [ slab, paint ] = useMemo( () => {
    const m = material( 'floor', '#ffffff' );
    const map = floorTexture();
    // The texture spans the whole slab; crop it to the inset plane so the paint stays in register.
    map.repeat.set( ( w - 2 * INSET ) / w, ( d - 2 * INSET ) / d );
    map.offset.set( INSET / w, INSET / d );
    m.map = map;
    return [ material( 'floor' ), m ];
  }, [] );
  return (
    <group position={ [ ( SLAB.x0 + SLAB.x1 ) / 2, 0, ( SLAB.z0 + SLAB.z1 ) / 2 ] }>
      <RoundedBox args={ [ w, 0.4, d ] } radius={ INSET } smoothness={ 3 } position={ [ 0, -0.2, 0 ] } material={ slab } receiveShadow>
        <Ink />
      </RoundedBox>
      { /* Inset by the bevel so the paint lies on the slab's flat top. */ }
      <mesh rotation-x={ -Math.PI / 2 } position={ [ 0, 0.001, 0 ] } material={ paint } receiveShadow>
        <planeGeometry args={ [ w - 2 * INSET, d - 2 * INSET ] } />
      </mesh>
    </group>
  );
}

/** The reject bin: an open tray at the end of the gate's side belt. */
export function RejectBin() {
  const [ x, z ] = REJECT_BIN;
  return (
    <group position={ [ x, 0, z ] }>
      <RoundedBox args={ [ 1.5, 0.12, 1.5 ] } radius={ 0.04 } position={ [ 0, 0.06, 0 ] } receiveShadow>
        <meshToonMaterial { ...toon } color="#5b6270" />
        <Ink />
      </RoundedBox>
      { [ [ 0, -0.7, 1.5, 0.1 ], [ 0, 0.7, 1.5, 0.1 ], [ -0.7, 0, 0.1, 1.5 ], [ 0.7, 0, 0.1, 1.5 ] ].map( ( [ dx, dz, w, d ], i ) => (
        <RoundedBox key={ i } args={ [ w, 0.55, d ] } radius={ 0.03 } position={ [ dx, 0.3, dz ] } castShadow receiveShadow>
          <meshToonMaterial { ...toon } color="#7a8496" />
          <Ink />
        </RoundedBox>
      ) ) }
    </group>
  );
}
