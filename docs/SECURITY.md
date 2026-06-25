# Security Policy

Please do not report security vulnerabilities in public GitHub issues.

Send vulnerability reports to security@dataphyre.com with:

- A clear description of the issue
- Steps to reproduce, if available
- Potential impact
- Any proof of concept or supporting details

## Agent And Diagnostic Safety

Application agents should share the smallest useful security evidence. Prefer
redacted MCP diagnostic summaries, bounded logs, route/config/schema metadata,
and reproduction shapes over raw production dumps.

Do not include credentials, API keys, private keys, cookies, signed URLs,
tenant names, customer data, billing identifiers, private hostnames, local
filesystem paths, `config/static/dpvk`, `direct_access_key`, `app_override_key`,
or full production `flight_sheet.php` contents in reports.

If a report needs sensitive proof, describe the data shape and request a private
maintainer handoff instead of posting raw artifacts. Security reports do not
require `dataphyre_mcp_verify_all`, or
Dataphyre hot-path benchmarks unless the maintainer asks for framework,
release-surface, security/governance, access-policy, or shared hot-path
evidence.

We aim to acknowledge reports within 48 hours and provide an initial response or
timeline within 7 days.

For non-security support, see [SUPPORT.md](SUPPORT.md).



