import type * as THREE from 'three';

/** The live camera and canvas, registered by the stage so dev tooling can project world points to the screen. */
export const rig: { camera: THREE.Camera | null; canvas: HTMLCanvasElement | null } = { camera: null, canvas: null };
