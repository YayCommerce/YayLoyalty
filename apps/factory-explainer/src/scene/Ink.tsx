import { Outlines } from '@react-three/drei';
import { INK } from './materials';

const WIDTH = 2; // outline width in pixels

/** The cartoon ink line around its parent mesh. */
export function Ink( { width = WIDTH }: { width?: number } ) {
  // drei's thickness is in pixels when `screenspace` is off; with it on, it is in object units.
  return <Outlines thickness={ width } color={ INK } toneMapped={ false } />;
}
