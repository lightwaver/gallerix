# Config container JSON files

Place these JSON files in the Azure Blob container specified by AZURE_CONTAINER_CONFIG (default: `config`).
All permission checks are implemented in `src/Authorizer.php`.

## Principals

Every role list below (global and per gallery) contains entries of two kinds:

- a role name, e.g. `"member"` — matches every user that has this role in `users.json`
- a user principal, e.g. `"@alice"` — matches exactly that user (case-insensitive)

Roles starting with `@` assigned to a user are ignored, so a role can never impersonate a user.

## users.json

```json
[
  {
    "username": "admin",
    "passwordHash": "$2y$10$hash...",
    "roles": ["admin"]
  },
  {
    "username": "alice",
    "passwordHash": "$2y$10$hash...",
    "roles": ["member"]
  }
]
```

Password hash generation (PHP):

```
php -r "echo password_hash('your-password', PASSWORD_BCRYPT) . PHP_EOL;"
```

Users are re-read on every request: deleting a user or changing roles applies immediately, and changing
the password invalidates existing sessions.

## roles.json

```json
{
  "global": {
    "admin": ["admin"],
    "createGallery": ["admin", "member"]
  }
}
```

| Key | Meaning |
|---|---|
| `admin` | System admins: access to Settings / `/api/admin/*` and full access to every gallery. The role `admin` is always a system admin, even if missing here. |
| `createGallery` | May create galleries via `POST /api/galleries`. |

Other keys (e.g. `view`/`upload` from older versions) are kept but have no effect.

## galleries.json

```json
[
  {
    "name": "summer-camp-2025",
    "title": "Summer Camp 2025",
    "description": "Photos and videos from camp",
    "public": false,
    "roles": {
      "view": ["member"],
      "upload": ["leaders"],
      "admin": ["@alice"]
    }
  }
]
```

- `name`: folder in the data container. New galleries need a slug (`a-z`, `0-9`, `-`, `_`, max 64 chars).
- `public`: `true` makes the gallery viewable without login (listed on the login page).
  Legacy: `"public"` inside `roles.view` is still recognized and converted to the flag on the next save.
- `roles` are hierarchical:

| Level | Grants |
|---|---|
| `view` | see the gallery and its files |
| `upload` | view + upload files |
| `admin` | upload + edit title/description/public/roles of this gallery and delete files |

System admins always have full access, so they don't need to be listed.

## Creating a gallery via API

- `POST /api/galleries` with JSON body `{ "name"?, "title"?, "description"?, "public"?, "roles"?: { "view"?, "upload"?, "admin"? } }`
- Requires `global.createGallery` (or system admin). Fails with 409 if the name already exists.
- Defaults: the creator's roles get `view` and `upload`, the creator (`@username`) gets `admin`.
  Role lists in the request are added to these defaults.
