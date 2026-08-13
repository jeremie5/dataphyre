#!/usr/bin/env node
'use strict';

// Backward-compatible Panel integration entrypoint. Datadoc owns the browser
// portal regression because it owns the renderer and browser runtime.
if(!process.argv.includes('--allow-empty-content-assets')){process.argv.push('--allow-empty-content-assets');}
await import('../../datadoc/testing/datadoc_documentation_portal_browser_regression.js');
