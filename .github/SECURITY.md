# Security Policy

## Supported versions

The latest stable 1.x release receives security fixes. Older majors and unsupported PHP or Laravel versions are not maintained.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Email `everully@gmail.com` with:

- the affected package and version or commit;
- the impact and prerequisites;
- minimal reproduction steps or a proof of concept;
- any known mitigation;
- a safe contact method for follow-up.

Remove credentials, production customer data, raw visitor cookies, event payloads containing private data, and one-time SSO URLs. Use synthetic identifiers in reproductions.

The maintainers will acknowledge the report, investigate it privately, and coordinate remediation and disclosure with the reporter. Please allow time for a supported fix before publishing details.

## Security-sensitive areas

Reports involving credential exposure, SSO redirect validation, CSRF/authentication, cookie integrity, transaction isolation, idempotency conflicts, payload mutation, claim races, or PII in diagnostics are particularly valuable.
