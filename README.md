# Gallerix

Small photo & video gallery for a scouts group using:
- PHP backend API (serving auth, gallery listing, uploads)
- Azure Blob Storage (config and data containers)
- React frontend (Vite)

## Azure setup
- Create two containers in your storage account:
  - `config` (or set `AZURE_CONTAINER_CONFIG`) for JSON configuration files
  - `data` (or set `AZURE_CONTAINER_DATA`) for galleries; each gallery is a folder, e.g. `summer-camp-2025/IMG_1.jpg`
- Upload the following JSON files to the `config` container. See `backend-php/CONFIG_SCHEMAS.md` for details:
  - `users.json` (array of users with passwordHash and roles)
  - `roles.json` (role configuration for global permissions)
  - `galleries.json` (list of galleries with per-gallery roles for view/upload/admin)

## Backend (PHP)
Configure environment in `backend-php/.env` (copy from `.env.example`). Either set `AZURE_STORAGE_CONNECTION_STRING` or the account/key/endpoints.

Run locally on macOS:

1. Install dependencies
   - PHP >= 8.1 and Composer
2. From `backend-php/`:

```
composer install
php -S 0.0.0.0:8000 -t public
```

## Frontend (React / Vite)

From `frontend-react/`:

```
npm install
npm run dev
```

Vite dev server proxies `/api/*` to `http://localhost:8000`.

## Authentication
- Users authenticate with username/password (password hashes in users.json).
- Backend returns a JWT; frontend stores it in localStorage.
- Authorizations are checked server-side per gallery using roles in `galleries.json`.

## Uploads
- Uploads go directly to the `data` container under the selected gallery folder.
- Backend sets content type if provided by the browser.

## Notes
- This is a minimal MVP. Consider adding thumbnails generation, presigned URLs, pagination, and admin UI to manage users/galleries.
initial readmes