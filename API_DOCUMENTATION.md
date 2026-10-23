# MailerCloud Submission API Documentation & Postman Payload Guide

This document contains copy-pasteable JSON payloads, headers, URL formats, and response examples for Postman, curl, or any REST client.

- **Base URL**: `http://127.0.0.1:8000`
- **Standard Headers**:
  - `Content-Type: application/json`
  - `Accept: application/json`

---

## Quick Reference Table

| # | Endpoint | Method | Purpose | Status Code |
|---|----------|--------|---------|-------------|
| 1 | `/api/submissions` | `POST` | Create a new submission | `201 Created` |
| 2 | `/api/submissions` | `POST` | Duplicate idempotency key | `200 OK` (Reused) |
| 3 | `/api/submissions` | `POST` | Validation Error (Missing form_id) | `400 Bad Request` |
| 4 | `/api/submissions` | `POST` | Validation Error (Invalid email) | `400 Bad Request` |
| 5 | `/api/forms/{form_id}/submissions` | `GET` | List form submissions (Page 1) | `200 OK` |
| 6 | `/api/forms/{form_id}/submissions?page=2` | `GET` | Paginated form submissions | `200 OK` |
| 7 | `/api/forms/{form_id}/aggregates/hourly` | `GET` | Live Flink hourly aggregated counts | `200 OK` |

---

## 1. Create a New Submission

### Endpoint
`POST http://127.0.0.1:8000/api/submissions`  
*(Also accessible at `POST http://127.0.0.1:8000/submissions`)*

### Headers
```
Content-Type: application/json
Accept: application/json
```

### Request Body (Copy-pasteable JSON)
```json
{
  "form_id": "contact-form",
  "idempotency_key": "lead-contact-uuid-001",
  "email": "user@example.com",
  "data": {
    "name": "John Doe",
    "subject": "Inquiry about enterprise plan",
    "message": "Hello, I would like to schedule a demo."
  }
}
```

### Expected Response (`201 Created`)
```json
{
  "form_id": "contact-form",
  "idempotency_key": "lead-contact-uuid-001",
  "email": "user@example.com",
  "data": {
    "name": "John Doe",
    "subject": "Inquiry about enterprise plan",
    "message": "Hello, I would like to schedule a demo."
  },
  "updated_at": "2026-10-23T08:58:47.000000Z",
  "created_at": "2026-10-23T08:58:47.000000Z",
  "id": 82
}
```

---

## 2. Duplicate Idempotency Key Handling

When sending the same `idempotency_key` again (even with altered data or email), the API **does not create a duplicate record**. It safely returns the existing record with `HTTP 200 OK`.

### Endpoint
`POST http://127.0.0.1:8000/api/submissions`

### Headers
```
Content-Type: application/json
Accept: application/json
```

### Request Body (Copy-pasteable JSON)
```json
{
  "form_id": "contact-form",
  "idempotency_key": "lead-contact-uuid-001",
  "email": "user_modified@example.com",
  "data": {
    "name": "John Modified",
    "message": "Duplicate attempt with different content."
  }
}
```

### Expected Response (`200 OK`)
```json
{
  "id": 82,
  "form_id": "contact-form",
  "idempotency_key": "lead-contact-uuid-001",
  "email": "user@example.com",
  "data": {
    "name": "John Doe",
    "subject": "Inquiry about enterprise plan",
    "message": "Hello, I would like to schedule a demo."
  },
  "created_at": "2026-10-23T08:58:47.000000Z",
  "updated_at": "2026-10-23T08:58:47.000000Z"
}
```

---

## 3. Validation Error: Missing `form_id`

### Endpoint
`POST http://127.0.0.1:8000/api/submissions`

### Request Body (Copy-pasteable JSON)
```json
{
  "idempotency_key": "test-key-no-form",
  "email": "alice@example.com",
  "data": {
    "feedback": "Great service!"
  }
}
```

### Expected Response (`400 Bad Request`)
```json
{
  "message": "Invalid submission data",
  "errors": {
    "form_id": [
      "The form id field is required."
    ]
  }
}
```

---

## 4. Validation Error: Invalid Email Format

### Endpoint
`POST http://127.0.0.1:8000/api/submissions`

### Request Body (Copy-pasteable JSON)
```json
{
  "form_id": "survey-form",
  "idempotency_key": "test-key-bad-email",
  "email": "not-an-email-address",
  "data": {
    "score": 10
  }
}
```

### Expected Response (`400 Bad Request`)
```json
{
  "message": "Invalid submission data",
  "errors": {
    "email": [
      "The email field must be a valid email address."
    ]
  }
}
```

---

## 5. Validation Error: Missing `idempotency_key`

### Endpoint
`POST http://127.0.0.1:8000/api/submissions`

### Request Body (Copy-pasteable JSON)
```json
{
  "form_id": "survey-form",
  "email": "alice@example.com",
  "data": {
    "score": 10
  }
}
```

### Expected Response (`400 Bad Request`)
```json
{
  "message": "Invalid submission data",
  "errors": {
    "idempotency_key": [
      "The idempotency key field is required."
    ]
  }
}
```

---

## 6. List Submissions for a Form (Paginated: 10 per page)

### Endpoint
`GET http://127.0.0.1:8000/api/forms/contact-form/submissions`  
*(Optional query parameter: `?page=1` or `?page=2`)*

### Headers
```
Accept: application/json
```

### Expected Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 82,
      "form_id": "contact-form",
      "idempotency_key": "lead-contact-uuid-001",
      "email": "user@example.com",
      "data": {
        "name": "John Doe",
        "subject": "Inquiry about enterprise plan",
        "message": "Hello, I would like to schedule a demo."
      },
      "created_at": "2026-10-23T08:58:47.000000Z",
      "updated_at": "2026-10-23T08:58:47.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1
  }
}
```

---

## 7. Get Hourly Aggregates for a Form (Live Flink Aggregations)

### Endpoint
`GET http://127.0.0.1:8000/api/forms/contact-form/aggregates/hourly`

### Headers
```
Accept: application/json
```

### Expected Response (`200 OK`)
```json
{
  "form_id": "contact-form",
  "aggregates": [
    {
      "id": 1,
      "form_id": "contact-form",
      "hour_start": "2026-10-23 14:00:00",
      "submission_count": 80000,
      "created_at": "2026-10-23T08:30:00.000000Z",
      "updated_at": "2026-10-23T09:15:00.000000Z"
    }
  ]
}
```

---

## cURL Commands for Terminal Testing

### 1. Submit Record
```bash
curl -i -X POST http://127.0.0.1:8000/api/submissions \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"form_id":"curl-test","idempotency_key":"key-curl-100","email":"curl@test.local","data":{"note":"Testing via curl"}}'
```

### 2. Submit Duplicate
```bash
curl -i -X POST http://127.0.0.1:8000/api/submissions \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"form_id":"curl-test","idempotency_key":"key-curl-100","email":"curl@test.local","data":{"note":"Testing via curl"}}'
```

### 3. Fetch Submissions (Paginated)
```bash
curl -i http://127.0.0.1:8000/api/forms/curl-test/submissions?page=1
```

### 4. Fetch Hourly Aggregates
```bash
curl -i http://127.0.0.1:8000/api/forms/curl-test/aggregates/hourly
```
