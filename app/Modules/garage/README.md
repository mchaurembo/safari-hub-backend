# Backend: Garage Module (Scaffold)

This folder is reserved for Laravel module-based organization for the **Garage Services** feature.

## Current state
- API routes are still registered in `backend/routes/api.php`
- For now, the module exposes a minimal endpoint: `GET /api/garage/ping`
- Database migrations for garage tables are scaffolded under `backend/database/migrations/`

## Next steps
1. Add module route groups and service provider
2. Move garage controllers into this module folder
3. Implement booking + technician assignment endpoints
4. Add policies/middleware (e.g. `role:garage_owner`, `role:technician`)

