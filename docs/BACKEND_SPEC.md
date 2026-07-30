# Backend work requested by the Consultant Panel

Seven tickets. Each one says **what to change**, shows the **JSON before and
after**, and gives an **acceptance test** you can run with curl.

Nothing here breaks existing clients: every change is an *added* field or a
*new* endpoint.

| # | Ticket | Endpoint | Effort | Priority |
|---|---|---|---|---|
| T1 | Add review author + timestamp | `GET …/checklists/{cid}/progress` | S | **1 — blocking** |
| T2 | Document the review POST | `POST …/task-reviews` | S | **2** |
| T3 | Add completion fields to the day view | `GET …/shops/{id}/tasks` | M | **3** |
| T4 | Multi-shop material complaints | new endpoint | M | 4 |
| T5 | Multi-shop monthly P&L | new endpoint | M | 4 |
| T6 | Owner validation of a review | new endpoint | M | 5 |
| T7 | Cache headers on read-only aggregates | 5 endpoints | S | 5 |

**Why round trips matter.** The panel's HTTP client times out after **10 s per
call**. A screen needing N sequential calls can take N × 10 s and be cut off by
the gateway. Turning "N calls" into "1 call" is worth more to us than making any
single call faster.

---

## T1 — Review author and timestamp

**Endpoint:** `GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=YYYY-MM-DD`

### What to change

Add three fields to each object in `tasks[]`.

### Today

```json
{
  "task_id": 1216,
  "status": "DONE",
  "review_id": 55,
  "review_is_accepted": 1,
  "review_rating": 4,
  "review_comment": "RAS"
}
```

### Wanted

```json
{
  "task_id": 1216,
  "status": "DONE",
  "review_id": 55,
  "review_is_accepted": 1,
  "review_rating": 4,
  "review_comment": "RAS",

  "review_by": 7,
  "review_by_name": "Sam V.",
  "reviewed_at": "2026-07-30 11:24:03"
}
```

| Field | Type | Null when |
|---|---|---|
| `review_by` | int | no review |
| `review_by_name` | string | no review |
| `reviewed_at` | datetime `Y-m-d H:i:s` | no review |

`review_by_name` should follow the same convention as the existing
`completed_by` field, which already returns a display name.

### Why

The panel has to show **who** checked a task — that is the point of supervising
consultants. Today it can only display "Reviewed", with no name. It currently
keeps a local journal to guess the author, which only works for reviews made
through the panel and requires a writable panel database.

### Acceptance

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" \
  | jq '.tasks[] | select(.review_id != null)
        | {task_id, review_id, review_by, review_by_name, reviewed_at}'
```

Every task with a `review_id` must return a non-null `review_by`,
`review_by_name` and `reviewed_at`.

---

## T2 — Document the review POST

**Endpoint:** `POST /consultant/shops/{shopId}/task-reviews`

### What to change

Nothing in the code — we need the **contract**.

### What we send today

The panel guesses, and sends duplicate field names to cover the possibilities:

```json
{
  "shop_id": 4,
  "checklist_id": 44,
  "task_id": 1216,
  "review_date": "2026-07-30",
  "completion_id": 169,
  "rating": 4,            "review_rating": 4,
  "comment": "RAS",       "review_comment": "RAS",
  "is_accepted": true,    "review_is_accepted": true
}
```

### What we need from you

1. the **required** fields and their types;
2. the **optional** fields;
3. the success response body;
4. the validation-error response body — ideally naming the offending fields;
5. whether a second POST on the same task **updates** the review or creates a
   second one.

### Why

We cannot clean up the duplicated payload until we know which names are real.

---

## T3 — Completion fields on the day view

**Endpoint:** `GET /consultant/shops/{shopId}/tasks?date=YYYY-MM-DD`

### What to change

Add these fields to each object in `tasks[]`. **They already exist** on the
`/progress` endpoint — this is about exposing them here too.

### Today

```json
{
  "task_id": 1216,
  "task_name": "Vérifier si le magasin est propre.",
  "checklist_name": "CO-01 — Ouverture",
  "status": "DONE",
  "completed_by": "Nathan C.",
  "completed_at": "2026-07-30 09:09:34",
  "note": "",
  "is_mandatory": 1,
  "requires_photo": 1,
  "execution_time": "06:30:00",
  "frequency": "DAILY",
  "priority": 5
}
```

### Wanted — add

```json
{
  "checklist_id": 44,
  "completion_id": 169,
  "attachment_id": 393,
  "attachment_filename": "task_photo_1785402563686.jpg",
  "review_id": 55,
  "review_rating": 4,
  "review_is_accepted": 1,
  "review_comment": "RAS"
}
```

plus `review_by`, `review_by_name`, `reviewed_at` once T1 is done.

### Why

Without them the panel cannot show the photo of a completed task, nor its
review, from this screen.

**Cost today:** for every page load the panel calls
`GET /consultant/shops/{id}/checklists`, then `/checklists/{cid}/progress` for
**each checklist of the day**, and joins on `task_id`. That is **2 extra round
trips minimum**, more if the shop has several checklists.

### Acceptance

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/tasks?date=2026-07-30" | jq '.tasks[0] | keys'
```

Must include `checklist_id`, `completion_id`, `attachment_id`,
`attachment_filename`, `review_id`.

---

## T4 — Material complaints for all shops

**Existing endpoint:** `GET /shops/{shopId}/material-complaints` — one shop.

### What to create

```
GET /consultant/shops/material-complaints?status=OPEN
```

`status` optional. Same shape as your other batch endpoints:

```json
{
  "shops": [
    {
      "shop_id": 1,
      "complaints": [
        { "id": 10, "reported_at": "2026-07-28 08:12:00", "status": "OPEN" }
      ]
    },
    { "shop_id": 2, "complaints": [] }
  ]
}
```

Each complaint object: exactly what the single-shop endpoint returns today.

### Why

The network view needs every shop. The panel currently fires one request per
shop in a parallel batch — 25 shops means 25 concurrent requests on your side
for one screen.

### Acceptance

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/material-complaints" | jq '.shops | length'
```

Must equal the number of shops the consultant may see — same
`consultant_shop_assignment` rule as your other endpoints.

---

## T5 — Monthly P&L for all shops

**Existing endpoint:** `GET /consultant/shops/{shopId}/pnl/monthly?from=&to=` (P2) — one shop.

### What to create

```
GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07
```

```json
{
  "shops": [
    {
      "shop_id": 1,
      "months": [
        { "month": "2025-08", "turnover": 52000, "material": 18000,
          "labour": 15000, "overhead": 9000, "result": 10000 }
      ]
    }
  ]
}
```

Same month object as the existing single-shop endpoint.

### Why

The valuation screen needs 12 months of P&L for **every** shop. Today that is
one request per shop, fired in parallel. This is the exact multi-shop pattern
you already built for P1 (`/consultant/shops/monthly-sales`) — same shape,
different payload.

---

## T6 — Owner validation of a review

A supervisor ("Owner") approves the review a consultant made. There is no place
for this in the API today.

### Option A — preferred

Add to each reviewed task in `/progress`:

```json
{
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by": 99,
  "owner_validated_by_name": "Marc O."
}
```

and an endpoint to set them:

```
POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate
{ "validated": true }
```

Response:

```json
{ "success": true,
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by_name": "Marc O." }
```

`{"validated": false}` clears the three fields.

### Option B

Tell us it is out of scope, and the panel keeps storing it locally.

### Why

Same limitation as T1: stored in the panel database only, therefore invisible to
any other client, and lost if that database is unavailable.

---

## T7 — Cache headers on read-only aggregates

**Endpoints:** `/consultant/shops/monthly-sales`, `/consultant/shops/sales-kpis`,
`/consultant/targets`, `/consultant/shops/category-sales`,
`/consultant/shops/pnl-summary`

### What to change

Send `Cache-Control` and `ETag`:

```
Cache-Control: private, max-age=300
ETag: "abc123"
```

Suggested `max-age`: **86400** when the requested window lies entirely in the
past — a closed month never changes — and **300** for anything covering today.

### Why

None of these endpoints send cache headers, so the panel guesses TTLs (60 s to
30 min depending on the endpoint) and can be wrong in both directions: stale
data, or a request that was never needed. Proper headers let every client skip
whole requests instead of guessing.

---

## What each ticket changes for the panel

| Ticket | What we can delete | What we gain |
|---|---|---|
| T1 | the local review journal | the real author, for every review |
| T2 | the duplicated field names | a payload we can trust |
| T3 | 2 round trips per page load | photo and review on the day view |
| T4 | 25 concurrent requests | 1 request |
| T5 | N parallel requests | 1 request |
| T6 | a local table | validation visible to all clients |
| T7 | our TTL guesses | fewer requests, fresher data |

---

## Reference — endpoints the panel already uses

For context, so nothing here surprises you.

**Working batch endpoints delivered on 30/07:**

| Ref | Endpoint | Used by |
|---|---|---|
| P0 | `GET /consultant/shops/{id}/pnl/daily` | profitability heat map |
| P1 | `GET /consultant/shops/monthly-sales` | Trends |
| P2 | `GET /consultant/shops/{id}/pnl/monthly` | Valuation — see T5 |
| P3 | `GET /consultant/shops/sales-kpis` | Trends, reports, valuation |
| P4 | `GET /consultant/shops/{id}/margin-heatmap?split=weekly` | heat map |
| P6a | `GET /consultant/targets?year=&month=` | Trends |
| P6b | `GET /consultant/shops/{id}/targets/range` | single shop, many months |
| P7 | `GET /consultant/shops/category-sales` | report comparison |
| P8 | `GET /consultant/shops/pnl-summary` | valuation |

**Everything else the panel calls:**

```
/consultant/shops                              /consultant/metric-definitions
/consultant/shops/{id}/targets                 /consultant/note-types
/consultant/shops/{id}/targets/copy            /consultant/notes/{id}
/consultant/shops/{id}/notes                   /consultant/comments/{id}
/consultant/shops/{id}/employees/{id}/notes    /consultant/tasks
/consultant/shops/{id}/checklists              /consultant/tasks/completions/{id}
/consultant/shops/{id}/checklists/{cid}/progress
/consultant/shops/{id}/task-reviews            /consultant/network/tasks/ranking
/shops/{id}/employees                          /shops/{id}/material-complaints
/attachments/{id}/presigned-url
/cases/{id}  ·  /cases/{id}/status  ·  /cases/{id}/consultant
/cases/{id}/meeting  ·  /cases/{id}/meetings  ·  /cases/{id}/reply
/cases/{id}/eligible-consultants
```
