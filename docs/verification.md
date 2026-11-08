# Verification record — 7 November 2026 (platform edition)

## Checked in the authoring environment

| Check | Outcome |
| --- | --- |
| PHP syntax (`php -l` across PHP source and scripts) | Passed |
| PHP validation and helper tests (`php tests/run.php`) | **44 / 44 passed** |
| Python HTTP smoke test syntax | Passed (`py_compile`) |
| OpenAPI YAML parse | Passed |
| Login route served with PHP built-in server | HTTP 200 |
| Desktop login layout at 1440 px | Rendered; no horizontal overflow |
| Mobile login layout at 390 px | Rendered; no horizontal overflow |
| Removal of old dashboard API prompt and technical footer | Confirmed in source |

## Requires the user's Docker Desktop and MySQL

The current isolated environment does **not** have a running MySQL server or PHP PDO MySQL driver. The following checks **have not** been executed for this platform edition:

- Actual MySQL schema bootstrap and populated data flows
- New capacity-lock and release integration checks
- Complete authenticated browser suite (projects, catalog, analytics, release)
- Docker Compose startup and GitHub Actions
- Visual responsive verification of all authenticated pages

These have been implemented in test code and a Windows verification script. Their result is **pending** until the user runs `verify-before-github.ps1` in the new version and completes `docs/test-plan.md`. Previous test results for v1 do not automatically validate v2.

Do not describe this repository as production-grade or as fully tested until the remaining checks have been performed.
