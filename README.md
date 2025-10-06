# Gallerix

Small photo & video gallery using:
- PHP backend API (JWT auth, Azure Blob integration, media proxy)
- React frontend (Vite)
- Azure Blob Storage (config/data/thumbs containers)

## Quickstart

1) Prerequisites
- Azure Storage account with three containers: `config`, `data`, `thumbs`
- PHP 8.1+, Composer, Node.js 18+

2) Configure Azure config files (upload to `config` container)
- `users.json` (with bcrypt `passwordHash`), `roles.json`, `galleries.json`

3) Backend env
- Copy `backend-php/.env.example` to `backend-php/.env`
- Set storage connection + `JWT_SECRET` (change from default)

4) Run locally
```
cd backend-php && composer install
php -S 127.0.0.1:8000 -t public
```
```
cd frontend-react && npm install
npm run dev
```

5) Frontend runtime config
- Edit `frontend-react/public/gallerix.config.json` and set `{ "backendUrl": "/api" }` for same-origin with subfolder, or full API origin.

6) Static hosting (optional)
- Serve built frontend statically; map PHP API under `/api`. Set `PUBLIC_BASE_URL=/api` in backend env.

## Features
- Public galleries: Galleries with `roles.view` containing `public` are visible without login and accessible from the Login screen.
- Media proxy endpoints:
  - `public/image.php` streams original files with auth/role checks. Public galleries allow access without a token; private ones require a JWT (via Authorization header, `gallerix_token` cookie, or `?t=` query).
  - `public/thumb.php` generates and caches thumbnails and preview images on-demand using GD. Non-images return a tiny PNG placeholder.
- Thumbnails & previews:
  - Grid thumbnails sized by `THUMB_MAX_SIZE`.
  - Lightbox and cover images use `PREVIEW_MAX_SIZE` via `s=preview` and are cached as separate blobs with an `_preview` suffix in the thumbs container.
  - Legacy preview paths are supported for fallback.
- Lightbox:
  - Animated slide transitions with slideshow mode.
  - Preloads adjacent preview images.
  - Downloads always link to the original file.
  - PDFs render inline via iframe with an icon in the grid.
- Roles & permissions:
  - Global `createGallery` permission controls who can create galleries.
  - Per-gallery `view`/`upload`/`admin` roles drive server-side checks; public galleries still compute `canUpload` if a logged-in user is present.
  - Admin Settings UI for users/roles/galleries. User passwords can be entered in plaintext in the UI; backend hashes them.
- Gallery cover image: The first image’s preview is used as the title image in lists; includes a token for private galleries across API calls.
- Storage cleanup: Deleting a gallery via Admin also removes its blobs from both `data` and `thumbs` containers.
- Typography & UI:
  - Kumbh Sans as default font; headings use Extra Bold.
  - Top navigation uses button-style tabs with active state highlighting.

## Azure setup
Create containers in your storage account:
- `config` (or `AZURE_CONTAINER_CONFIG`) for JSON configuration files
- `data` (or `AZURE_CONTAINER_DATA`) for gallery files (each gallery is a folder)
- `thumbs` (or `AZURE_CONTAINER_THUMBS`) for generated thumbnails/previews

Upload the following JSON files to `config` (see `backend-php/CONFIG_SCHEMAS.md` for details):
- `users.json` — array of users with `username`, `passwordHash` (bcrypt), and `roles`
- `roles.json` — global role configuration for permissions (e.g., `createGallery`)
- `galleries.json` — list of galleries with per-gallery `roles.view/upload/admin`

## Backend (PHP)
Environment: copy `backend-php/.env.example` to `backend-php/.env` and set either `AZURE_STORAGE_CONNECTION_STRING` or the account/key/endpoints.

Run locally:

```
composer install
php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:8000 -t public
```

Key env vars:
- `AZURE_STORAGE_CONNECTION_STRING` or (`AZURE_STORAGE_BLOB_ENDPOINT`, `AZURE_STORAGE_ACCOUNT`, `AZURE_STORAGE_KEY`)
- `AZURE_CONTAINER_CONFIG` (default: config)
- `AZURE_CONTAINER_DATA` (default: data)
- `AZURE_CONTAINER_THUMBS` (default: thumbs)
- `JWT_SECRET` (required; change from default)
- `JWT_ISSUER` (default: gallerix)
- `JWT_EXPIRES_IN` (seconds; default: 86400)
- `THUMB_MAX_SIZE` and `PREVIEW_MAX_SIZE` (pixel bounds)
- `PUBLIC_BASE_URL` (optional, e.g., `/api` or full origin; used to prefix media URLs)

### API endpoints
Auth & data:
- POST `/api/login` → `{ token, user }`
- GET `/api/me` → `{ user }` (requires Authorization)
- GET `/api/galleries` → `{ galleries: [{ name, title, description, coverUrl }] }` (requires Authorization)
- GET `/api/public-galleries` → `{ galleries: [...] }` (no auth)
- GET `/api/galleries/:name/items` → `{ gallery: { title, public, canUpload }, items: [...] }` (public allowed, optional auth for permissions)
- POST `/api/galleries/:name/upload` — multipart form with `file` (requires upload role)

Admin (admin role):
- `/api/admin/users` (GET/POST/DELETE)
- `/api/admin/roles` (GET/PUT)
- `/api/admin/galleries` (GET/POST/PUT/DELETE)

Media proxy:
- `GET /image.php?g=<gallery>&f=<file>[&t=<token>]` — original download/stream with role checks
- `GET /thumb.php?g=<gallery>&f=<file>&s=thumb|preview[&t=<token>]` — generates/serves cached images

## Frontend (React / Vite)

Start dev:

```
cd frontend-react
npm install
npm run dev
```

Runtime config (`frontend-react/public/gallerix.config.json`):

```
{
  "backendUrl": "/api"
}
```

If omitted, the app calls `/api/...` on the same origin. For a different origin, set the full base URL.

## Static hosting with API subfolder
You can host the React build as a static site and run the PHP API under `/api` (same host) or a separate domain.

Set:
- Frontend runtime: `backendUrl` to `/api` or `https://api.example.com`
- Backend: `PUBLIC_BASE_URL` to `/api` or the full API origin

Configure your web server to:
- Serve static files and SPA fallback for `/` (exclude `/api`)
- Route `/api/*` to the PHP public index and `.php` handlers

## CI/CD (Azure Pipelines)
- Entry pipeline: `build/pipeline.yaml`
  - Uses a Variable Group (e.g., `gallerix-secrets`) and a Secure File for backend `.env`
  - Invokes templates:
    - `build/build.yaml` — builds frontend, installs backend deps, publishes artifact (without `.env`)
    - `build/deploy.yaml` — downloads artifact, fetches `.env` from Secure Files, uploads via SSH/SFTP using a Service Connection (e.g., `gallerix-sftp`)

Secrets handling:
- `.env` is not stored in repo or artifacts; it’s injected at deploy from Secure Files
- SSH credentials are stored in an Azure DevOps Service Connection and not logged

### Azure DevOps configuration

1) Variable Group
- Create a Variable Group, e.g., `gallerix-secrets` (match `build/pipeline.yaml`), add any non-file secrets as variables.

2) Secure Files
- Upload your backend `.env` to Library > Secure files with the name `backend-env` (or adjust `BackendEnvSecureFile` in `pipeline.yaml`).

3) Service Connection (SSH)
- Create an SSH service connection named `gallerix-sftp` pointing to your target server (recommended: SSH key). The pipeline never prints secrets.

4) Pipeline setup
- Set pipeline path to `build/pipeline.yaml`.
- Adjust target folders in `build/deploy.yaml` (`/var/www/gallerix` and `/var/www/gallerix/api`) to match your host.

5) Optional
- Add environments and approvals to the deployment job.

## Troubleshooting uploads
If uploads fail, error messages include the reason and PHP limits. Common issues:
- `UPLOAD_ERR_INI_SIZE` or `UPLOAD_ERR_FORM_SIZE` → raise `upload_max_filesize`/`post_max_size`
- Missing temp dir or disk write error → check server tmp and permissions

Example dev server with higher limits:

```
php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:8000 -t public
```

## License
MIT — see `LICENSE`.