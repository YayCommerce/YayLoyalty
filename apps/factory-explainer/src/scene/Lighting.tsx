import { Suspense } from 'react';
import { ContactShadows, Environment } from '@react-three/drei';
import { FLOOR, TARGET } from './Stage';

/** Studio light: a hemisphere fill, one soft-shadowed key, contact shadows and a faint city reflection. */
export function Lighting() {
  const w = FLOOR.x1 - FLOOR.x0 + 2;
  const d = FLOOR.z1 - FLOOR.z0 + 2;
  return (
    <>
      <hemisphereLight args={ [ '#fbf8f3', '#b9b2a6', 0.8 ] } />
      <directionalLight position={ [ TARGET.x + 18, 30, TARGET.z + 10 ] } intensity={ 2.5 } castShadow shadow-mapSize={ [ 2048, 2048 ] } shadow-camera-left={ -24 } shadow-camera-right={ 24 } shadow-camera-top={ 24 } shadow-camera-bottom={ -24 } shadow-camera-far={ 120 } shadow-bias={ -0.0004 } shadow-normalBias={ 0.05 }>
        <object3D attach="target" position={ [ TARGET.x, 0, TARGET.z ] } />
      </directionalLight>
      <ContactShadows position={ [ TARGET.x, 0.005, TARGET.z ] } scale={ [ w, d ] } resolution={ 1024 } opacity={ 0.35 } blur={ 2.4 } far={ 2.5 } frames={ 1 } />
      { /* drei's "city" preset image, served locally so the page never fetches from a CDN. */ }
      <Suspense fallback={ null }>
        <Environment files={ `${ import.meta.env.BASE_URL }env/potsdamer_platz_1k.hdr` } environmentIntensity={ 0.35 } />
      </Suspense>
    </>
  );
}
