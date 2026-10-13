import { use } from 'react';
import { GLTFLoader, type GLTF } from 'three/examples/jsm/loaders/GLTFLoader.js';

const KEYS = [
  'scanner-high', 'hopper-square', 'machine', 'cog-a', 'warning-traffic', 'piston-square', 'structure-medium',
  'machine-fortified', 'machine-window', 'conveyor-stripe', 'cone', 'box-small', 'screen-small',
] as const;

export type ModelKey = typeof KEYS[ number ];

/** Kenney Factory Kit models. */
export const MODELS = Object.fromEntries(
  KEYS.map( ( key ) => [ key, `${ import.meta.env.BASE_URL }models/${ key }.glb` ] )
) as Record<ModelKey, string>;

const loader = new GLTFLoader();
const cache = new Map<ModelKey, Promise<GLTF | null>>();

/** Loads once per key; a failed load resolves to null so callers can draw their fallback. */
export function loadModel( key: ModelKey ): Promise<GLTF | null> {
  let promise = cache.get( key );
  if ( ! promise ) {
    promise = loader.loadAsync( MODELS[ key ] ).catch( ( error: unknown ) => {
      console.warn( `[factory] model ${ key } failed to load`, error );
      return null;
    } );
    cache.set( key, promise );
  }
  return promise;
}

/** Suspends while the model loads, then returns it, or null when loading failed. Clone before adding it to the scene. */
export function useModel( key: ModelKey ): GLTF | null {
  return use( loadModel( key ) );
}
