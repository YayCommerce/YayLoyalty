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
    await page.goto( '/' );
    await page.waitForFunction( () => Boolean( ( window as unknown as { __factory?: FactoryDebug } ).__factory?.machineRects()[ 'vault' ] ) );

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
    }

    expect( errors ).toEqual( [] );
  } );
}
