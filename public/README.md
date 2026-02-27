# This directory is the Apache document root for production.

# It is populated by deploy.sh — do not edit files here directly.

#

# Structure after deploy:

# public/

# ├── .htaccess ← SPA routing + API rewrite (committed)

# ├── index.html ← built by Vite (gitignored)

# ├── assets/ ← built by Vite (gitignored)

# └── api/ ← symlink to ../backend/web/ (created by deploy.sh)
