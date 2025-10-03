# Config container JSON files

Place these JSON files in the Azure Blob container specified by AZURE_CONTAINER_CONFIG (default: `config`).

users.json
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

roles.json
{
  "global": {
    "view": ["admin", "member"],
    "upload": ["admin"],
    "admin": ["admin"]
  }
}

galleries.json
[
  {
    "name": "summer-camp-2025",
    "title": "Summer Camp 2025",
    "description": "Photos and videos from camp",
    "roles": {
      "view": ["admin", "member"],
      "upload": ["admin", "member"],
      "admin": ["admin"]
    }
  }
]
