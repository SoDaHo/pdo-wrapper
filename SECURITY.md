# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.2.x   | yes       |
| < 1.2   | no        |

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
