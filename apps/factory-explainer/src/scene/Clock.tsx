import { useFrame } from '@react-three/fiber';
import { useFactory } from '../store';

/** Advances the scripted playhead once per frame. */
export function Clock() {
  useFrame( ( _, delta ) => useFactory.getState().tick( delta ) );
  return null;
}
