/*
 * Editor side of the storefront blocks. Title and category come from their block.json
 * (includes/Engine/Storefront/blocks/), which PHP registers; the icon from blocks.icons; the preview is the server's own HTML.
 */
import React from 'react';
import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';
import { pointsBalanceIcon, waysToEarnIcon, waysToRedeemIcon } from './blocks.icons';

const BLOCKS: Record<string, React.ReactElement> = {
  'yayloyalty/points-balance': pointsBalanceIcon,
  'yayloyalty/ways-to-earn': waysToEarnIcon,
  'yayloyalty/ways-to-redeem': waysToRedeemIcon,
};

function preview(name: string) {
  return function Edit() {
    return (
      <div {...useBlockProps()}>
        <ServerSideRender block={name} />
      </div>
    );
  };
}

for (const [name, icon] of Object.entries(BLOCKS)) {
  registerBlockType(name, { icon, edit: preview(name), save: () => null });
}
