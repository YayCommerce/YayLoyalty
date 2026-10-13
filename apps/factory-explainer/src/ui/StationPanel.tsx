import { useEffect } from 'react';
import { STATIONS } from '../factory.config';
import { useFactory } from '../store';

function List( { title, items }: { title: string; items: string[] } ) {
  if ( ! items.length ) {
    return null;
  }
  return (
    <>
      <h3>{ title }</h3>
      <ul>{ items.map( ( i ) => <li key={ i }><code>{ i }</code></li> ) }</ul>
    </>
  );
}

/** Details for the clicked station; opening pauses the loop and closing resumes what the panel paused. */
export function StationPanel() {
  const selected = useFactory( ( s ) => s.selected );
  const s = STATIONS.find( ( x ) => x.id === selected );

  useEffect( () => {
    const onKey = ( e: KeyboardEvent ) => {
      if ( 'Escape' === e.key && useFactory.getState().selected ) {
        useFactory.getState().closePanel();
      }
    };
    document.addEventListener( 'keydown', onKey );
    return () => document.removeEventListener( 'keydown', onKey );
  }, [] );

  if ( ! s ) {
    return null;
  }
  return (
    <aside className="panel" data-testid="panel" aria-labelledby="panel-title">
      <button type="button" className="close" data-testid="panel-close" aria-label="Close" onClick={ () => useFactory.getState().closePanel() }>×</button>
      <div className="panel-role">{ s.role }{ ! s.built && <span className="panel-tag">not built yet</span> }</div>
      <h2 id="panel-title">{ s.label }</h2>
      <p>{ s.summary }</p>
      <h3>File</h3>
      <code className="file">{ s.file }</code>
      <List title="Hooks" items={ s.hooks } />
      <List title="Key methods and data" items={ s.methods } />
      <List title="Inputs" items={ s.inputs } />
      <List title="Outputs" items={ s.outputs } />
    </aside>
  );
}
