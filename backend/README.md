# Dive Backend

Laravel API for tasks, subtasks, and Mistral-powered task splitting.

## Endpoints

- `GET /api/tasks`
- `POST /api/tasks`
- `GET /api/tasks/{taskId}`
- `PATCH /api/tasks/{taskId}`
- `DELETE /api/tasks/{taskId}`
- `POST /api/tasks/{taskId}/split`

## Development

```bash
touch database/database.sqlite
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/database.sqlite" php artisan migrate
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/database.sqlite" php artisan serve --host=127.0.0.1 --port=8080
```

## Tests

```bash
php artisan test
```
