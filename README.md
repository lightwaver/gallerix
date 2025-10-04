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

## Backend endpoints
Base URL defaults to your PHP server origin (e.g., http://localhost:8000). Paths:
- POST `/api/login` — body: `{ "username": "", "password": "" }` → `{ token, user }`
- GET `/api/me` — returns current user info `{ user }` (requires Authorization header)
- GET `/api/galleries` — returns `{ galleries: [{ name, title, description }] }`
- GET `/api/galleries/:name/items` — returns `{ gallery, items: [{ name, url, type, size, contentType }] }`
- POST `/api/galleries/:name/upload` — multipart/form-data with `file` field (requires upload permission)

Media proxy (auth-protected)
Admin endpoints (admin role required)
- GET `/api/admin/users` — list users
- POST `/api/admin/users` — upsert user { username, roles[], passwordHash? }
- DELETE `/api/admin/users/:username` — remove user
- GET `/api/admin/roles` — fetch roles config
- PUT `/api/admin/roles` — replace roles config
- GET `/api/admin/galleries` — list galleries
- POST `/api/admin/galleries` — upsert a gallery
- DELETE `/api/admin/galleries/:name` — delete a gallery
- GET `/image.php?g=<gallery>&f=<filename>` — streams the blob content if the current session is authorized to view the gallery. The login endpoint sets an `HttpOnly` cookie `gallerix_token` used by the proxy.
  - You can also pass `Authorization: Bearer <token>` or `?t=<token>` for testing.

### Backend configuration (.env)
Copy `backend-php/.env.example` to `backend-php/.env` and set one of the following connection methods:
- AZURE_STORAGE_CONNECTION_STRING
  - Example: `DefaultEndpointsProtocol=https;AccountName=NAME;AccountKey=KEY;EndpointSuffix=core.windows.net`
- Or specify endpoint + key:
  - `AZURE_STORAGE_BLOB_ENDPOINT=https://NAME.blob.core.windows.net`
  - `AZURE_STORAGE_ACCOUNT=NAME`
  - `AZURE_STORAGE_KEY=...`

Other settings:
- `AZURE_CONTAINER_CONFIG` (default: config)
- `AZURE_CONTAINER_DATA` (default: data)
- `JWT_SECRET` (required; change from default)
- `JWT_ISSUER` (default: gallerix)
- `JWT_EXPIRES_IN` (seconds; default: 86400)

Troubleshooting:
- Error: "Azure storage connection not configured" → Ensure the above env vars are set and your server loads the `.env` (the dev server runs from `backend-php/` and autoloads environment in `public/index.php`). Restart the PHP dev server after changes.

## Frontend runtime configuration
You can point the React app to any backend without rebuilding by editing `frontend-react/public/gallerix.config.json`:

```
{
  "backendUrl": "http://localhost:8000"
}
```

If `backendUrl` is empty or missing, the app will call `/api/...` relative to the current origin. In development, Vite proxies `/api` to the local PHP server as defined in `vite.config.js`.

## Authentication
- Users authenticate with username/password (password hashes in users.json).
- Backend returns a JWT; frontend stores it in localStorage.
- Authorizations are checked server-side per gallery using roles in `galleries.json`.

## Uploads
- Uploads go directly to the `data` container under the selected gallery folder.
- Backend sets content type if provided by the browser.

Troubleshooting uploads
- Error details will now include a human-readable cause (e.g., exceeds upload_max_filesize) plus current ini limits.
- Common causes:
  - `UPLOAD_ERR_INI_SIZE` or `UPLOAD_ERR_FORM_SIZE` → file too large for server limits
  - Missing temp dir or disk write error → check server tmp folder and permissions
- To raise limits during local dev, you can export env vars or use a custom php.ini. Example run with larger limits:

```
php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:8000 -t public
```

## Notes
- This is a minimal MVP. Consider adding thumbnails generation, presigned URLs, pagination, and admin UI to manage users/galleries.
initial readmes