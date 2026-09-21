# Hosted tracking fixture

`tracking.js` is an unmodified copy of the built runtime asset identified in
`provenance.json`. The runtime checkout was clean at the recorded commit. Its
Vite manifest maps `resources/js/tracking.js` to `public/build/tracking.js`.
The source entry, core implementation, build configuration, lockfile and asset
were inspected and hashed. An in-memory build of the tracked entry with the
runtime’s installed Vite 8.3.0 on Node 24.21.0 reproduced the asset byte for byte
(`configFile: false`, `envDir: false`, `publicDir: false`, `build.write: false`,
`build.rolldownOptions.input` set to the source entry, output name `tracking.js`).
No runtime files were written. This is local runtime provenance, not deployed-URL
verification; deployed bytes remain a release/integration gate.

`HostedScriptContractTest` renders the real package Blade directive and sends
HTML over stdin to `tests/Support/Tracking/run-hosted-script.mjs`. The runner
derives dataset values from that HTML, checks the fixture hash, and executes
the built asset in a new Node VM for each browser context. Only browser I/O is
controlled: cookie jar, clock, timers and fetch. There is no real tracking call,
browser storage, or network download. Deferred responses and explicit timer
callbacks prove provisional writes and timeout behavior without elapsed sleeps.

Run with **Node 24 on PATH**:

```sh
vendor/bin/pest tests/Feature/Tracking
```

Missing Node, a different Node major, or a changed fixture hash fails the suite;
none is silently skipped. The SQLite CI matrix installs Node 24, including on
Windows, and runs this suite as part of the full tests. No npm dependencies are
needed. Tests/fixtures are already excluded from Composer archives.

For a deliberate refresh, verify the runtime commit, clean source state, build
entry and bytes, copy the complete built asset unchanged, update provenance,
and run the contract suite. Never edit the fixture to make a test pass. A
separate release check must compare the real public hosted URL to this fixture.
