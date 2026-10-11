/*
 * Editor side of the storefront blocks. Title, icon and category come from their block.json
 * (includes/Engine/Storefront/blocks/), which PHP registers; the preview is the server's own HTML.
 */
import React from 'react';
import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';

const BLOCKS = ['yayloyalty/points-balance', 'yayloyalty/ways-to-earn'];

function preview(name: string) {
  return function Edit() {
    return (
      <div {...useBlockProps()}>
        <ServerSideRender block={name} />
      </div>
    );
  };
}

for (const name of BLOCKS) {
  registerBlockType(name, { edit: preview(name), save: () => null });
}
