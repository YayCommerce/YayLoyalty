/**
 * Build-time constants injected by Vite's `define`.
 *
 * `__IS_PRO__` is the ONLY pro gate in the SPA. No pro flag is
 * localized from PHP: one zip carries both the PHP and the JS bundle, so build
 * type is never ambiguous and there is nothing for a runtime flag to
 * disambiguate. Licence state is deliberately not an input — an expired licence
 * stops updates, not features.
 *
 * Because it is a literal substituted at each usage site, `if (__IS_PRO__)`
 * collapses at build time and the dead branch is dropped from the bundle. That
 * is what keeps pro feature code out of the Lite download rather than
 * merely hidden inside it.
 */
declare const __IS_PRO__: boolean;
