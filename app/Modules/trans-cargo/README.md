# Backend: Trans Cargo Module (Scaffold)

This folder is reserved for module-based organization in Laravel.

Current status:
- API routes remain in the existing `backend/routes/api.php`
- Controllers/services stay in existing locations for now

Next steps:
- Create module route groups and service providers
- Move controllers by feature (cargo, jobs, trips, etc.) into this module

## Now supported: multi-module scaffolding
This repository is now scaffolding additional services as separate modules (e.g. `garage`).
Follow the same structure:
- `backend/app/Modules/<module>/README.md`
- module-specific migrations under `backend/database/migrations/`
- module controller scaffolds and `/api/<module>/...` routes

