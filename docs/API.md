# API route overview

Base URL during XAMPP development:

```text
http://localhost/donorconnect/api
```

## Public

- `GET /health`
- `GET /auth/csrf`
- `POST /auth/register`
- `POST /auth/login`
- `GET /institutions`

## Authenticated

- `GET /auth/me`
- `POST /auth/logout`
- `GET /dashboard`
- `GET /notifications`
- `PATCH /notifications/{id}/read`
- `PATCH /notifications/read-all`
- `GET /campaigns`

## Donor

- `GET /donor/profile`
- `PUT /donor/profile`
- `PATCH /donor/availability`
- `GET /donor/activity`
- `POST /donor/matches/{matchId}/respond`
- `POST /campaigns/{id}/join`

## Staff and administrator

- `GET /donors`
- `GET /donors/{id}`
- `PATCH /donors/{id}/verify`
- `POST /donors/{id}/eligibility`
- `POST /donors/{id}/donations`
- `POST /donors/{id}/deferrals`
- `PATCH /deferrals/{id}/close`
- `POST /campaigns`
- `PATCH /campaigns/{id}/status`
- `POST /campaigns/{id}/invite`
- `GET /reports/overview`
- `POST /requests/{id}/match`
- `POST /requests/{id}/notify`

## Hospital, staff and administrator

- `GET /requests`
- `POST /requests`
- `GET /requests/{id}`
- `PATCH /requests/{id}/status`

## Administrator

- `POST /institutions`
- `GET /admin/users`
- `POST /admin/users`
- `PATCH /admin/users/{id}/status`

All authenticated POST, PUT, PATCH and DELETE requests require the current `X-CSRF-Token` header.
