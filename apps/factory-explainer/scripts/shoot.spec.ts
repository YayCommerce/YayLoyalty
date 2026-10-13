import { expect, test, type Page } from '@playwright/test';
import type { FactoryDebug } from '../src/debug';

// SHOOT_VIEWPORTS is a comma list such as 1440x900,390x844.
const VIEWPORTS = ( process.env.SHOOT_VIEWPORTS ?? '1440x900' ).split( ',' ).map( ( v ) => v.split( 'x' ).map( Number ) as [ number, number ] );

// Each shot lands on a work entry, so its step card is up: scene 1's first step, scene 3's first step, and scene 4's gate deny.
const SHOTS: { scene: string; pick: ( e: ReturnType<FactoryDebug[ 'entries' ]>[ number ] ) => boolean }[] = [
  { scene: 'scene-1', pick: ( e ) => 'scene-1' === e.scene && 'work' === e.phase },
  { scene: 'scene-3', pick: ( e ) => 'scene-3' === e.scene && 'work' === e.phase },
  { scene: 'scene-4', pick: ( e ) => 'scene-4' === e.scene && 'work' === e.phase && 'deny' === e.gate },
];

type Rect = { x: number; y: number; width: number; height: number };
const intersects = ( a: Rect, b: Rect ) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;

async function frames( page: Page, n: number ) {
  await page.evaluate( ( count ) => new Promise<void>( ( done ) => {
    let left = count;
    const step = () => ( --left <= 0 ? done() : requestAnimationFrame( step ) );
    requestAnimationFrame( step );
  } ), n );
}

for ( const [ width, height ] of VIEWPORTS ) {
  test( `shoot ${ width }x${ height }`, async ( { page } ) => {
    const errors: string[] = [];
    page.on( 'console', ( m ) => {
      if ( 'error' === m.type() ) {
        errors.push( m.text() );
      }
    } );
    page.on( 'pageerror', ( e ) => errors.push( e.message ) );

    await page.setViewportSize( { width, height } );
    // networkidle covers the environment HDR, which loadModel does not track.
    await page.goto( '/', { waitUntil: 'networkidle' } );
    await page.waitForFunction( () => Boolean( ( window as unknown as { __factory?: FactoryDebug } ).__factory?.machineRects()[ 'vault' ] ) );
    // Kit models stand in as boxes until they load; shoot only the loaded scene.
    await page.waitForFunction( () => 0 === ( window as unknown as { __factory: FactoryDebug } ).__factory.loading(), undefined, { timeout: 60_000 } );

    for ( const shot of SHOTS ) {
      await page.evaluate( ( source ) => {
        const f = ( window as unknown as { __factory: FactoryDebug } ).__factory;
        const pick = new Function( 'e', `return (${ source })(e)` ) as ( e: unknown ) => boolean;
        const entry = f.entries().find( ( e ) => pick( e ) )!;
        f.pause();
        f.seekTime( entry.start + 1.6 );
      }, shot.pick.toString() );
      await frames( page, 3 );
      await page.waitForTimeout( 400 );
      await page.screenshot( { path: `shots/${ width }-${ shot.scene }.png` } );
      await expect( page.getByTestId( 'step-card' ) ).toHaveClass( /is-on/ );

      // Labels never overlap each other, and none covers its own machine.
      const { labels, machines } = await page.evaluate( () => {
        const f = ( window as unknown as { __factory: FactoryDebug } ).__factory;
        return { labels: f.labelRects(), machines: f.machineRects() };
      } );
      const ids = Object.keys( labels );
      expect( ids.length, `${ shot.scene }: visible labels` ).toBeGreaterThan( 0 );
      for ( let i = 0; i < ids.length; i++ ) {
        for ( let j = i + 1; j < ids.length; j++ ) {
          expect( intersects( labels[ ids[ i ] ], labels[ ids[ j ] ] ), `${ shot.scene }: ${ ids[ i ] } label overlaps ${ ids[ j ] } label` ).toBe( false );
        }
        expect( intersects( labels[ ids[ i ] ], machines[ ids[ i ] ] ), `${ shot.scene }: ${ ids[ i ] } label covers its machine` ).toBe( false );
      }
    }

    expect( errors ).toEqual( [] );
  } );
}
