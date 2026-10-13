import { Bloom, EffectComposer, N8AO, SMAA, ToneMapping } from '@react-three/postprocessing';
import { ToneMappingMode } from 'postprocessing';

/** Ambient occlusion, bloom above luminance 1 (only the status LEDs reach it), ACES tone mapping and SMAA. */
export function Effects() {
  return (
    <EffectComposer multisampling={ 0 }>
      <N8AO aoRadius={ 0.9 } distanceFalloff={ 0.6 } intensity={ 2.2 } quality="medium" halfRes />
      <Bloom luminanceThreshold={ 1 } luminanceSmoothing={ 0.1 } intensity={ 0.7 } mipmapBlur />
      <ToneMapping mode={ ToneMappingMode.ACES_FILMIC } />
      <SMAA />
    </EffectComposer>
  );
}
