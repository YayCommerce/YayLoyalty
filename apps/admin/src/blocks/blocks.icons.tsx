/*
 * Inserter and list-view icons, in YayCheckout's rounded-outline family: 1.5 stroke, round caps and joins.
 * The inline fill beats the editor's `.block-editor-block-icon svg { fill: currentColor }`, which would fill every shape.
 */
import React from 'react';

const icon = (children: React.ReactNode) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    width="24"
    height="24"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.5}
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
    focusable="false"
    style={{ fill: 'none' }}
  >
    {children}
  </svg>
);

export const pointsBalanceIcon = icon(
  <>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7.75 13.12 10.21 15.81 10.51 13.81 12.33 14.35 14.98 12 13.63 9.65 14.98 10.19 12.33 8.19 10.51 10.88 10.21Z" />
  </>,
);
export const waysToEarnIcon = icon(
  <>
    <path d="M10 4 11.53 7.9 15.71 8.15 12.47 10.8 13.53 14.85 10 12.6 6.47 14.85 7.53 10.8 4.29 8.15 8.47 7.9Z" />
    <path d="M18 15v6M15 18h6" />
  </>,
);
export const waysToRedeemIcon = icon(
  <>
    <rect x="3.5" y="8" width="17" height="4.5" rx="1.5" />
    <path d="M5 12.5V18a2.5 2.5 0 0 0 2.5 2.5h9A2.5 2.5 0 0 0 19 18v-5.5M12 8v12.5" />
    <path d="M12 8c-1-3-4.5-4-5-2s2 2 5 2c3 0 5.5 0 5-2s-4-1-5 2Z" />
  </>,
);
