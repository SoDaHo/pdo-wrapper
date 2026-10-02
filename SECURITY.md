# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.4.x   | yes       |
| < 1.4   | no        |

## Reporting a vulnerability

Report vulnerabilities privately through GitHub's private vulnerability reporting:
https://github.com/SoDaHo/pdo-wrapper/security/advisories/new

Please do not open a public issue for a security problem. Include the affected version, the database
driver (MySQL/MariaDB, PostgreSQL or SQLite) and, if you have one, a minimal reproduction.

## Scope

The library binds every value as a prepared-statement parameter and quotes every identifier it is given.
`Database::raw()`, `query()`, `execute()`, `getPdo()`, and the condition SQL of `whereRaw()` and
`insertWhen()` (only their bindings are bound) pass SQL through unchanged by design and are documented
as unsafe for untrusted input; a report that needs untrusted input in one of those calls is a usage
error in the application, not a vulnerability in the library.

Likewise outside the library's reach, and documented in the README:

- **Parameters in logs.** The `query` and `error` hooks and `QueryException::getDebugMessage()` carry
  the SQL and the bound parameters as passed, secrets included. An application that writes them to a
  log or an error page unredacted leaks them itself.
- **PDO options.** `options` replace the secure defaults (native prepared statements, exceptions,
  no multi-statements on MySQL/MariaDB). Switching to emulated prepares, multi-statements or a
  runtime `SET NAMES` is the application's decision and risk.
- **Identifiers from request input.** Quoting keeps a column or table name from becoming SQL; it does
  not decide which column a request may read, filter or sort by. That needs a whitelist in the
  application.
