# Integration Matrix

This folder contains a 10-project Composer Plugin test matrix for cstore.

## Layout

- `projects/project-01` ... `projects/project-10`: test apps that install dependencies via Composer with cstore plugin enabled.
- `private-packages/acme-private-toolkit`: local private package fixture consumed by `project-05`.
- `results/`: generated matrix run logs and latest markdown summary.

## Test Plan

1. Run all 10 projects using Composer Plugin flow (`composer update`) with cstore enabled.
2. Verify every project completes successfully.
3. Verify one project installs a private package (`acme/private-toolkit`).
4. Verify shared packages are hard-linked from the global store.
5. Record store size, package count, and per-project timings.

## Run

```bash
./bin/run-integration-matrix --clean
```

This command:

- uses isolated HOME at `/tmp/cstore-integration-home` by default
- supports overriding HOME via `CSTORE_TEST_HOME=/custom/path`
- rebuilds dependencies for each project
- writes logs to `integration/results/project-*.log`
- writes summary to `integration/results/latest-summary.md`
