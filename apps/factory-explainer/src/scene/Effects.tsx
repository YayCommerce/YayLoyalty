import { Bloom, EffectComposer, N8AO, Outline, SMAA, ToneMapping } from '@react-three/postprocessing';
import { ToneMappingMode } from 'postprocessing';

/** Ambient occlusion, the hovered station's outline, bloom above luminance 1 (only the status LEDs reach it), ACES tone mapping and SMAA. */
export function Effects() {
  return (
    <EffectComposer multisampling={ 0 } autoClear={ false }>
      <N8AO aoRadius={ 0.9 } distanceFalloff={ 0.6 } intensity={ 2.2 } quality="medium" halfRes />
      <Outline visibleEdgeColor={ 0x5b63b0 } hiddenEdgeColor={ 0x5b63b0 } edgeStrength={ 8 } width={ 1000 } blur />
      <Bloom luminanceThreshold={ 1 } luminanceSmoothing={ 0.1 } intensity={ 0.7 } mipmapBlur />
      <ToneMapping mode={ ToneMappingMode.ACES_FILMIC } />
      <SMAA />
    </EffectComposer>
  );
}
