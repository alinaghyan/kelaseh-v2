# Security & Scale Checklist (Production Readiness)

Date: 2026-02-21
Scope: `kelaseh-v2`

## P0 (Must before national rollout)

- [x] Remove heavy schema migration from hot path (`action=session`).
- [x] Prevent session fixation (`session_regenerate_id(true)` on successful login).
- [x] Add stronger login abuse protection (DB-backed failed-login rate limiting by login/IP).
- [x] Add yearly atomic counter table for `new_case_code` generation to reduce collision risk.
- [x] Add critical DB indexes for heavy query paths.
- [x] Add unique index for `new_case_code` after duplicate check.
- [x] Align DB config to existing database (`kelaseh_v2`).

## P1 (Should implement in deployment/infrastructure phase)

- [ ] Create dedicated DB user with least privilege (do not use `root` in production).
- [ ] Enforce HTTPS end-to-end with valid TLS certs.
- [ ] Put app behind reverse proxy/WAF with global rate limiting.
- [ ] Add central log aggregation and alerting (5xx rate, slow query rate, auth anomalies).
- [ ] Add scheduled backups + restore drill (RPO/RTO verified).
- [ ] Enable DB slow query log and tune top N expensive queries.
- [ ] Add application-level health checks and readiness checks.

## P2 (Scale optimization)

- [ ] Replace deep `OFFSET` pagination with keyset/cursor pagination for large lists.
- [ ] Move broad text search (`LIKE %...%`) to dedicated search strategy (full-text/search engine).
- [ ] Add read replicas for reporting/exports.
- [ ] Partition/archive old records for long-term data growth.
- [ ] Add async job queue for expensive tasks (bulk exports/notifications).

## Notes

- This checklist separates code-level hardening from infra-level controls.
- P1/P2 items are mandatory for true national-scale reliability and security.
