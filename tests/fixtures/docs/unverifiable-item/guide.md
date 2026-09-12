# Fixture maintainer guide

## Prerequisites

- Install pinned dependencies with `npm ci`.
- Start the local WordPress runtime with `npm run env:start`.

## Routine commands

- Step 1 - run `npm run test` and keep the output.
- Step 2 - apply the reviewed corpus with `wp lps import apply <package>`.
- Step 3 - run `npm run qa:a11y` and attach the report.
- Step 4 - verify the documentation with `node tests/docs/docs-checker.mjs`.
- Step 5 - run the PHP contracts with `tools/composer test`.

## Role boundary

| Role | Allowed actions (policy) |
| --- | --- |
| contributor | `create`, `edit`, `submit` |
| publisher | `create`, `edit`, `submit`, `review`, `publish`, `unpublish`, `archive`, `redirect` |

## References

The pinned runtime is recorded in [the runtime note](../../../../docs/architecture/runtime.md) and
the executable role contract is `tests/fixtures/governance/role-collection-matrix.json`.
The site settings contract lives in
`wp-content/plugins/lps-content-model/includes/class-contracts.php`.

The accessibility contact setting `accessibility_contact` is a production setting.
