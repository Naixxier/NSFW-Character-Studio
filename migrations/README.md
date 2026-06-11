# Database Migrations

PostgreSQL is the production runtime database. SQLite remains useful for local development and explicit migration rehearsal, but the server no longer keeps a SQLite snapshot as live data.

## Files

- `migrations/pgsql/001_initial.sql`: PostgreSQL schema.
- `scripts/migrate-sqlite-to-pgsql.php`: copies supported SQLite tables into PostgreSQL and runs the PostgreSQL schema first.

## Docker Runtime

The normal Docker stack includes both services:

```bash
docker compose up -d db app
```

The app uses PostgreSQL when:

```env
DB_DRIVER=pgsql
DATABASE_URL=pgsql://character_studio:character_studio@db:5432/character_studio
```

## Rehearse Or Repeat SQLite To PostgreSQL Migration

Run from an environment that can reach the target PostgreSQL database:

```bash
SQLITE_SOURCE_PATH=/path/to/planner.sqlite \
DATABASE_URL=pgsql://character_studio:character_studio@localhost:5432/character_studio \
php scripts/migrate-sqlite-to-pgsql.php
```

Set `MIGRATION_TRUNCATE_TARGET=1` only when intentionally replacing the target database contents.

## Notes

- Do not copy a stale SQLite file over the server database unless intentionally running a controlled migration rehearsal.
- The migration script currently copies core planner, metadata, gallery, jobs, and asset tables.
- LoRA variant/reference tables exist in the PostgreSQL schema and are created by the app for SQLite fallback.
