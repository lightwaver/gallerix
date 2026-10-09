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
- Set storage connection + `JWT_SECRET` (random, at least 32 bytes, e.g. `openssl rand -base64 48`)

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
- Public galleries: Galleries with `"public": true` are visible without login and listed on the Login screen (the legacy form — `public` inside `roles.view` — is still recognized and converted on the next save). Logged-in users see them in their gallery list as well.
- Media proxy endpoints:
  - `public/image.php` streams original files with auth/role checks. Public galleries allow access without a token; for private ones the API hands out short-lived signed URLs (`e`/`sig` parameters, bound to gallery + file) after its permission check. API clients may alternatively send an `Authorization: Bearer` header. Session tokens are never placed in URLs or cookies.
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
- Roles & permissions (all checks live in `backend-php/src/Authorizer.php`, details in `backend-php/CONFIG_SCHEMAS.md`):
  - System admins (roles in `roles.json` → `global.admin`, plus always the role `admin`) access Settings and have full access to every gallery.
  - Global `createGallery` controls who can create galleries. The creator's roles may view/upload, and the creator (`@username`) manages the new gallery.
  - Per gallery, `view` / `upload` / `admin` are hierarchical: upload includes view, admin (= manage) includes upload.
  - Gallery managers can edit title, description, visibility and roles of their gallery and delete files — directly in the gallery view. Changes that would remove their own manage access are rejected.
  - Role lists may contain role names or `@username` to grant a single user.
  - Sessions: roles are re-read from `users.json` on every request, so deleting a user, changing roles or changing the password takes effect immediately (a password change invalidates existing sessions).
  - Admin Settings UI for users/roles/galleries. User passwords can be entered in plaintext in the UI; backend hashes them.
- Uploads: only images, videos and PDFs are accepted; the type is detected from the file contents (not the browser-supplied type). Filenames are sanitized and existing files are never overwritten — duplicates get a ` (1)` suffix. Media is served with `nosniff` and a sandboxing CSP.
- Gallery names: new galleries need a slug name (`a-z`, `0-9`, `-`, `_`, max 64 chars); it is derived from the title when omitted. Creating a gallery whose name already exists fails with 409.
- Gallery cover image: The first image’s preview is used as the title image in lists (via a signed URL for private galleries).
- Storage cleanup: Deleting a gallery via Admin also removes its blobs from both `data` and `thumbs` containers.
- Security:
  - Failed logins are throttled per IP + username and per IP (HTTP 429 with `Retry-After`); unknown usernames take as long as wrong passwords.
  - Config writes are conditional on the blob ETag: if two requests change `users.json`/`roles.json`/`galleries.json` at the same time, the second one gets HTTP 409 instead of silently overwriting the first.
  - Password hashes are never sent to the browser. New users need a password (min. 8 characters) and a username of letters, digits, `.`, `_`, `-`; admins cannot delete their own account.
  - 500 responses contain no internal details (see `APP_DEBUG`); errors are logged server-side.
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
- `roles.json` — global permissions (`admin`, `createGallery`)
- `galleries.json` — list of galleries with `public` flag and per-gallery `roles.view/upload/admin`

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
- `JWT_SECRET` (required; at least 32 bytes — the backend refuses to run otherwise)
- `JWT_ISSUER` (default: gallerix)
- `JWT_EXPIRES_IN` (seconds; default: 86400)
- `THUMB_MAX_SIZE` and `PREVIEW_MAX_SIZE` (pixel bounds)
- `MEDIA_URL_TTL` (seconds; default: 7200) — minimum validity of signed media URLs (rounded up to the full hour)
- `PUBLIC_BASE_URL` (optional, e.g., `/api` or full origin; used to prefix media URLs)
- `CORS_ALLOWED_ORIGINS` (optional, comma separated, e.g. `https://gallery.example.com`) — only needed when the frontend runs on a different origin than the API; without it no CORS headers are sent
- `APP_DEBUG` (optional; `true` adds exception messages to 500 responses — never enable in production)
- `LOGIN_MAX_ATTEMPTS` (default 5, per IP + username), `LOGIN_MAX_ATTEMPTS_PER_IP` (default 30), `LOGIN_THROTTLE_WINDOW` (seconds, default 900) — failed-login throttling; state is kept in the system temp directory

### API endpoints
Auth & data:
- POST `/api/login` → `{ token, user }`
- POST `/api/logout` → `{ ok }` (clears the legacy media cookie of older versions)
- GET `/api/me` → `{ user: { username, roles, isAdmin, canCreateGallery } }` (requires Authorization; login returns the same `user` shape)
- GET `/api/galleries` → `{ galleries: [{ name, title, description, public, coverUrl }], canCreate }` (requires Authorization)
- POST `/api/galleries` → `{ gallery }` — body `{ title?, name?, description?, public?, roles?: { view?, upload?, admin? } }` (requires `createGallery`; 409 if the name exists)
- GET `/api/public-galleries` → `{ galleries: [...] }` (no auth)
- GET `/api/galleries/:name/items` → `{ gallery: { name, title, description, public, canView, canUpload, canManage, roles? }, items: [...] }` (public allowed, optional auth for permissions; `roles` only for managers)
- PATCH `/api/galleries/:name` → `{ gallery }` — body with any of `title`, `description`, `public`, `roles` (requires gallery manage)
- DELETE `/api/galleries/:name/items/:file` → `{ ok }` — deletes the file and its cached thumbnails (requires gallery manage)
- POST `/api/galleries/:name/upload` — multipart form with `file` (requires upload)

Admin (system admin):
- `/api/admin/users` (GET/POST/DELETE)
- `/api/admin/roles` (GET/PUT)
- `/api/admin/galleries` (GET/POST/PUT/DELETE)

Media proxy:
- `GET /image.php?g=<gallery>&f=<file>&e=<expiry>&sig=<signature>` — original download/stream with role checks
- `GET /thumb.php?g=<gallery>&f=<file>&s=thumb|preview&e=<expiry>&sig=<signature>` — generates/serves cached images

### Reverse proxy
The frontend uses only `GET`, `POST` and `DELETE` (updates are sent as `POST`), so proxies with method restrictions such as `limit_except GET POST DELETE` keep working. `PUT`/`PATCH` are accepted by the API as well.

### Tests
End-to-end tests run the real backend against [Azurite](https://github.com/Azure/Azurite) (Azure Storage emulator) and exercise login/throttling, permissions, uploads, signed media URLs, gallery management and concurrent config writes:

```
backend-php/tests/e2e/run.sh          # requires podman; DOCKER=docker for docker
```

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