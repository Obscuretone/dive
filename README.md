# Dive

Dive is a todo app for turning vague work into smaller, doable next actions.

The backend stores tasks and subtasks. The frontend gives you a compact task list and detail view. The AI splitter uses Mistral to create practical subtasks, with shared cooldown handling for provider rate limits.

## Stack

- Laravel 12 API
- SQLite for local development
- React 18 with Vite
- Mistral chat completions for task splitting

## Layout

- `backend/` - Laravel API
- `frontend/` - React client

## Prerequisites

- PHP 8.2 or newer
- Composer
- Node 26 or newer
- npm
- A Mistral API key

## Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Set the database values in `backend/.env`:

```bash
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/dive-monorepo/backend/database/database.sqlite
```

Set the Mistral values in `backend/.env`:

```bash
MISTRAL_API_KEY=
MISTRAL_MODEL=mistral-large-latest
MISTRAL_ENDPOINT=https://api.mistral.ai/v1/chat/completions
MISTRAL_TIMEOUT=30
```

Run migrations and start the API:

```bash
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8080
```

The API will be available at `http://127.0.0.1:8080/api`.

## Frontend Setup

```bash
cd frontend
npm install
cp .env.example .env 2>/dev/null || printf 'VITE_DIVE_API=http://localhost:8080/api\n' > .env
npm start -- --port 3001
```

The frontend will be available at `http://localhost:3001`.

By default, the frontend expects the API at `http://localhost:8080/api`. Override it in `frontend/.env`:

```bash
VITE_DIVE_API=http://localhost:8080/api
```

## AI Splitting

Dive sends the selected task, its ancestors, and any existing subtasks to Mistral and expects a JSON response containing subtasks. The backend creates those subtasks in order and returns them to the frontend.

When Mistral returns `429`, Dive reads `Retry-After`, stores a local cooldown, and returns the retry window to the frontend. That keeps repeated split attempts from hammering the provider while the key is cooling down.

## API

```bash
GET    /api/tasks
POST   /api/tasks
GET    /api/tasks/{taskId}
PATCH  /api/tasks/{taskId}
DELETE /api/tasks/{taskId}
POST   /api/tasks/{taskId}/split
```

## Checks

Backend:

```bash
cd backend
php artisan test --testsuite=Feature
composer audit
```

Frontend:

```bash
cd frontend
npm test
npm run build
npm audit
```
