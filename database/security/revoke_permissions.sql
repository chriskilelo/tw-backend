-- NFR-SEC-004 / CLAUDE.md Section 4, Rule 3: audit_logs, inquiry_notes,
-- directive_notes, and alert_versions are append-only tables. The
-- application already enforces this at the model layer (IsAppendOnly
-- throws on save() if updated_at would be set), but that is not a
-- substitute for a database-level guarantee — this revokes the
-- application's own database role's ability to UPDATE or DELETE rows in
-- these four tables at all, so a bug, a compromised app process, or a
-- privileged-but-careless ad-hoc query run as the app role cannot alter or
-- remove an audit trail entry.
--
-- IMPORTANT: these statements must be run manually, once, directly on the
-- production database server before go-live. They cannot be run via a
-- Laravel migration, because the application's own database role does not
-- have the GRANT privilege on itself (a role cannot grant or revoke its
-- own privileges from within a session authenticated as that same role).
--
-- This project's database is PostgreSQL, not MySQL (TDD-ADR-004, CLAUDE.md
-- Section 2) — written in PostgreSQL REVOKE syntax rather than the
-- MySQL-style `REVOKE ... ON db.table FROM 'user'@'%'` form. PostgreSQL has
-- no cross-database dotted notation (a session only ever connects to one
-- database at a time, so these statements must be run while connected to
-- the production database itself, not referenced by name), and PostgreSQL
-- roles carry no '@host' component.
--
-- Production role name confirmed at first deployment (10.241.18.41,
-- 2026-09-23): "tradewatch" (not the "tradewatch_app" placeholder this file
-- previously used) — see tw-backend/.env, DB_USERNAME.

REVOKE UPDATE, DELETE ON TABLE audit_logs FROM tradewatch;
REVOKE UPDATE, DELETE ON TABLE inquiry_notes FROM tradewatch;
REVOKE UPDATE, DELETE ON TABLE directive_notes FROM tradewatch;
REVOKE UPDATE, DELETE ON TABLE alert_versions FROM tradewatch;
