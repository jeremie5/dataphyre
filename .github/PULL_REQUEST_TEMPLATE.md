## Summary

- 

## Type

- [ ] Runtime/module behavior
- [ ] Documentation
- [ ] Release/public metadata
- [ ] Embedded-install configuration
- [ ] Test or fixture

## Compatibility

- [ ] Backward compatible
- [ ] Breaking change
- [ ] Not sure

## Verification

- [ ] `./dev/tools/release_check`
- [ ] `./dev/tools/check_export -WarnOnly -WarningLimit 200`
- [ ] `./dev/tools/prepare_export -Output <prepared-export>` when release/export files changed
- [ ] `./dev/tools/check_export -Root <prepared-export>` when release/export files changed
- [ ] `./dev/tools/check_source` (`-Php <path-to-php>` or `DATAPHYRE_PHP` is OK) for a local CI-equivalent source-checkout pass
- [ ] `./dev/tools/lint_php.ps1` (`-Php <path-to-php>` or `DATAPHYRE_PHP` is OK for local runs)
- [ ] MCP self-test/live validation covered by `check_source` when MCP or release tooling changed
- [ ] Relevant module tests or reproduction notes
- [ ] Documentation links checked

## Notes

Mention any embedded-install impact, module status change, or follow-up work.
