# Backend work requested by the Consultant Panel

Each ticket says **what to change**, shows the **JSON before and after**, and
gives an **acceptance test** you can run with curl.

> **In a hurry?** `DEV_HANDOFF.md` is the single to-do list — infra first, then
> every ticket with its payload inline. `BACKEND_BUILD.md` is the two-page
> version: the columns to add, the endpoints to create, and a suggested order.
> This file is the reference behind both.

Nothing here breaks existing clients: every change is an *added* field or a
*new* endpoint.

| # | Ticket | Endpoint | Effort | Priority |
|---|---|---|---|---|
| T1 | Add review author + timestamp | `GET …/checklists/{cid}/progress` | S | **1 — blocking** |
| T2 | Document the review POST | `POST …/task-reviews` | S | **2** |
| T3 | Add completion fields to the day view | `GET …/shops/{id}/tasks` | M | **3** |
| T4 | Multi-shop material complaints | new endpoint | M | 4 |
| T5a | **`material` always present on the monthly P&L** | `GET …/{id}/pnl/monthly` | S | **1 — blocking** |
| T5b | Multi-shop monthly P&L | new endpoint | M | 4 |
| T6 | Owner validation of a review | new endpoint | M | 5 |
| T7 | Cache headers on read-only aggregates | 5 endpoints | S | 5 |
| T8 | **Three endpoints, three different revenues** | 3 existing endpoints | S | **2** |
| T9 | Checklist progress over a date range | new endpoint | M | 3 |
| T10 | Product `is_pdm` + a mandatory sector | product reference data | M | 4 |
| T11 | **Every task of the network, in one call** | new endpoint | L | **3** |
| T12 | Quarterly sales history, per shop | new endpoint | M | 6 |
| T13 | **`product_id` on a task that controls a product** | 2 existing endpoints | S | **3** |

**Start with T5a and T8.** They are the only two tickets on this list about
figures being *wrong*. Everything else makes the panel faster or richer; T5a and
T8 make it correct — T5a fixes the margin, T8 fixes the revenue that margin is a
percentage of.

**Why round trips matter.** The panel's HTTP client times out after **10 s per
call**. A screen needing N sequential calls can take N × 10 s and be cut off by
the gateway. Turning "N calls" into "1 call" is worth more to us than making any
single call faster.

---

## Status — what is done, and what is left

Two lists, because they are two different kinds of fact.

The first is **verifiable from our source code**: it is what the panel already
built to live without you. The second is **not verifiable from our side** — only
a call against your API tells whether a ticket has shipped, so each line carries
the one-liner that answers it in a few seconds.

### DONE — on the panel side

Every ticket below already has a workaround in production. Nothing is waiting on
you to *function*; what waits on you is being correct, fast, or simple. The last
column is the code we delete the day the ticket lands.

| # | What the panel does today, instead | What we delete |
|---|---|---|
| T1 | Writes its own journal, `mac_task_review`, to remember who reviewed | the table, and the guessing that goes with it |
| T2 | Sends every field twice — `rating` *and* `review_rating`, `comment` *and* `review_comment`, `is_accepted` *and* `review_is_accepted` | the duplicates |
| T3 | Calls `/checklists` then one `/progress` per checklist, to find the photo and the review a day view should already carry | 1 + C calls per shop screen |
| T4 | One parallel call per shop for material complaints | the fan-out |
| T5a | Re-reads a whole month day by day when `material` is missing | the fallback, and the margin doubt |
| T5b | N parallel monthly P&L calls | the fan-out |
| T6 | Stores the Owner's validation in local columns of `mac_task_review` | those columns |
| T7 | Guesses its own cache TTLs, per endpoint | our guesses |
| T8 | Picks a revenue source per screen, by hand | the per-screen preference |
| T9 | Keeps `mac_checklist_day_snapshot` and freezes closed days itself | the table **and** its freezing logic |
| T10 | Nothing — there is no sector and no PDM flag anywhere today | nothing; this one only adds |
| T11 | Issues 1 + N + N + M calls (31 for 5 shops, 181 for 30) and joins them on `task_id` | the fan-out **and** the service that stitches it back together |
| T12 | Ships the KPI modal **without** its sparkline, and says so in the code | nothing; the chart appears when the endpoint does |
| T13 | Renders the comparison screen, dark: it carries the field end to end and waits for it | nothing; the screen lights up on its own |

Two of these are worth more than the others because they **remove** code rather
than add a screen: **T1** deletes a table that only knows about reviews posted
through the panel, and **T9** deletes a table that has already frozen days at
zero and had to be taught to heal itself.

### TO DO — on the backend side

We cannot see your deployment from here. Run the line, read the answer, tick the
box. `$API` and `$TOKEN` as usual; the full acceptance test for each ticket is
in its own section below.

| # | Shipped? Ask your API | Expected when done |
|---|---|---|
| T1 | `curl -s "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| jq '[.tasks[] \| select(.review_id != null) \| has("review_by")] \| all'` | `true` |
| T2 | — (a document, not an endpoint) | the contract, written down |
| T3 | `curl -s "$API/consultant/shops/4/tasks?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| jq '[.data.tasks[] \| has("completion_id")] \| all'` | `true` |
| T4 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/material-complaints?shop_ids=2,4" -H "Authorization: Bearer $TOKEN"` | `200` |
| T5a | `curl -s "$API/consultant/shops/4/pnl/monthly?year=2026" -H "Authorization: Bearer $TOKEN" \| jq '[.data.months[] \| has("material")] \| all'` | `true` |
| T5b | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/pnl/monthly?shop_ids=2,4&year=2026" -H "Authorization: Bearer $TOKEN"` | `200` |
| T6 | `curl -s -o /dev/null -w '%{http_code}' -X POST "$API/consultant/task-reviews/1/validate" -H "Authorization: Bearer $TOKEN"` | not `404` |
| T7 | `curl -sI "$API/consultant/network/tasks/ranking?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| grep -i cache-control` | a `Cache-Control` line |
| T8 | see the three curls in the T8 section — they must agree | one revenue, three times |
| T9 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/4/checklists/progress?from=2026-07-01&to=2026-07-31" -H "Authorization: Bearer $TOKEN"` | `200` |
| T10 | `curl -s "$API/shops/4/statistics/sales/product-category-groups?grouping=category&from=2026-07-01&to=2026-07-31" -H "Authorization: Bearer $TOKEN" \| jq '[.. \| objects \| select(has("product_id")) \| has("is_pdm")] \| all'` | `true` |
| T11 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/network/tasks?date=2026-07-30" -H "Authorization: Bearer $TOKEN"` | `200` |
| T12 | `curl -s "$API/consultant/shops/sales-kpis/quarterly?quarters=6" -H "Authorization: Bearer $TOKEN" \| jq '.quarters \| length'` | `6` |
| T13 | `curl -s "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| jq '[.tasks[] \| has("product_id")] \| all'` | `true` |

**Nothing on this list has been confirmed shipped at the time of writing.** If
one of them has landed since, the line above will say so faster than a meeting.

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
GET /consultant/shops/material-complaints
GET /consultant/shops/material-complaints?status=OPEN
GET /consultant/shops/material-complaints?from=2026-07-01&to=2026-07-31
```

All three query parameters are **optional**. Without them, return everything the
single-shop endpoint would return, for every shop the consultant may see.

| Parameter | Type | Meaning |
|---|---|---|
| `status` | string | filter, same values as today (`OPEN`, `CLOSED`, …) |
| `from` | date `Y-m-d` | `reported_at` ≥ this date |
| `to` | date `Y-m-d` | `reported_at` ≤ this date |

### Response

Same envelope as your other multi-shop endpoints (`/consultant/shops/monthly-sales`):

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

Two rules that matter to us:

1. **Every authorised shop appears**, even with an empty `complaints` array. A
   missing shop and a shop with zero complaints are different facts, and the
   panel has to tell them apart — one is "no problem", the other is "no data".
2. Each complaint object is **exactly** what the single-shop endpoint returns
   today. Don't reshape it; we already parse that form.

### Why

Two screens need every shop's complaints at once: the network report and the
Food-Cost lever.

Today the panel calls `GET /shops/{id}/material-complaints` once per shop. They
go out in parallel (`curl_multi`, in chunks of 32), so it is one wait for us —
but it is **25 concurrent requests hitting your side** for a single screen, and
25 authorisation checks, and 25 query round trips to your database.

One endpoint means one query, and it lets you filter server-side instead of
sending us everything to filter in PHP.

### Acceptance

```bash
# 1. Every authorised shop is present, including those with no complaint.
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/material-complaints" | jq '.shops | length'
```

Must equal the number of shops the consultant may see — same
`consultant_shop_assignment` rule as your other endpoints.

```bash
# 2. The per-shop payload matches the single-shop endpoint, shop by shop.
diff \
  <(curl -s -H "Authorization: Bearer $TOKEN" "$API/shops/4/material-complaints" | jq -S .) \
  <(curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/material-complaints" \
    | jq -S '.shops[] | select(.shop_id == 4) | .complaints')
```

Must print nothing.

```bash
# 3. The filter narrows, and never widens.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/material-complaints?status=OPEN" \
  | jq '[.shops[].complaints[] | select(.status != "OPEN")] | length'
```

Must be `0`.

---

## T5 — Monthly P&L for all shops

**Existing endpoint:** `GET /consultant/shops/{shopId}/pnl/monthly?from=&to=` (P2) — one shop.

This ticket has **two parts**. The second one is the important one: the endpoint
that exists today does not always return the cost line we need, and without it
the valuation is wrong — not slow, *wrong*.

### Part A — the multi-shop endpoint

```
GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07
```

`from` and `to` are months (`YYYY-MM`), inclusive. Same envelope as
`/consultant/shops/monthly-sales`:

```json
{
  "shops": [
    {
      "shop_id": 1,
      "months": [
        { "month": "2025-08", "turnover": 52000, "material": 18000,
          "labour": 15000, "overhead": 9000, "result": 10000 }
      ]
    },
    { "shop_id": 2, "months": [] }
  ]
}
```

Same month object as the existing single-shop endpoint. As in T4: every
authorised shop appears, even with an empty `months` array.

### Part B — `material` must always be there

**This is the blocking part.**

The panel computes the net margin of a shop as:

```
net margin = (turnover − material − labour − overhead) ÷ turnover
```

It does **not** use your `result` field, because `result` does not always deduct
the material cost. When it doesn't, margins come out around 40 % — no bakery
earns that, and the whole valuation follows the error.

So every month object must carry the four lines, or say plainly that it can't:

| Field | Type | Required |
|---|---|---|
| `turnover` | number | **yes** |
| `material` | number | **yes** — the food cost of the month |
| `labour` | number | **yes** |
| `overhead` | number | **yes** — rent, royalties, energy, depreciation… |
| `result` | number | optional; we no longer rely on it |

`null` is acceptable and honest when a line genuinely doesn't exist for that
month — the panel shows it as missing and says so on screen. What we cannot work
with is a **silently absent** `material` next to a `result` that looks complete.

**What happens today without it.** `/pnl/daily` *does* return `material` — the
profitability heat map lives on it and its figures are right. So the panel falls
back to the daily endpoint and re-aggregates a whole month day by day, just to
recover one number the monthly endpoint should already have. That is an extra
round trip per affected shop, on every valuation.

### Two questions we need answered

1. **What exactly does `overhead` cover?** We treat it as rent + royalties +
   energy + depreciation. If some of those sit elsewhere, the margin is
   overstated and we need to know where they are.
2. **Is `result` meant to equal `turnover − material − labour − overhead`?** If
   yes, it's currently not, and that's a bug on your side worth fixing anyway.
   If no, tell us what it means and we'll stop mentioning it.

### Why (Part A)

The valuation screen needs 12 closed months of P&L for **every** shop. Today
that's one request per shop, fired in parallel — same cost profile as T4: one
wait for us, N concurrent requests for you.

Measured on the current panel, the valuation endpoint costs **11 network waits**
and the trends endpoint **12**. T5 removes the largest chunk of that.

This is the exact multi-shop pattern you already built for P1
(`/consultant/shops/monthly-sales`) — same shape, different payload.

### Acceptance

```bash
# 1. Every authorised shop is present.
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2025-08&to=2026-07" | jq '.shops | length'
```

```bash
# 2. THE ONE THAT MATTERS — no month may omit a cost line.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2025-08&to=2026-07" \
  | jq '[.shops[].months[]
         | select(has("material") and has("labour") and has("overhead") | not)]
        | length'
```

Must be `0`. Present-and-`null` passes; absent does not.

```bash
# 3. The monthly endpoint agrees with the daily one, which we know is right.
#    Pick one shop and one closed month.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/pnl/daily?from=2026-06-01&to=2026-06-30" \
  | jq '[.days[].material] | add'

curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2026-06&to=2026-06" \
  | jq '.shops[] | select(.shop_id == 4) | .months[0].material'
```

The two numbers must match. If they don't, the monthly aggregation is the one to
fix — the daily figures are what the heat map uses, and those have been checked
against your back-office.

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

## T8 — One shop, one month, three different revenues

**Endpoints concerned:**
`GET /consultant/shops/monthly-sales` (P1) · `GET /consultant/shops/sales-kpis` (P3) · `GET /consultant/shops/{id}/pnl/monthly` (P2)

Nothing to build here. This ticket asks you to **make three existing numbers
agree — or to tell us why they shouldn't.**

### The problem

Three endpoints report the revenue of a shop, and for the *same shop* over the
*same closed month* they do not return the same figure:

| Source | Field | What the panel uses it for |
|---|---|---|
| P1 `monthly-sales` | `ca` | the 12-month trend series |
| P3 `sales-kpis` | `ca` / `turnover` | reports, average basket, N vs N-1 |
| P2 `pnl/monthly` | `turnover` | valuation, net margin |

The panel cannot tell which one is right. It has no rule to pick a winner, so
today it **hard-codes a preference per screen** — which is a workaround, not an
answer, and it means two screens can legitimately show two different revenues
for the same month.

**One correction on our side, in fairness.** We reported a gap of roughly ×3
earlier. Part of that was our bug: the panel was summing per-shop KPIs over a
shop list that contained duplicates, while `monthly-sales` comes back keyed by
`shop_id` and so was immune. That duplication is fixed. Please don't chase the
×3 — run the comparison below and work from what it actually shows.

### What we're asking for

Either of these closes the ticket:

1. **They agree.** The three figures match for an identical window, to the cent.
2. **They legitimately differ**, and you document what each one counts. Then we
   pick the right source per screen instead of guessing.

If it's (2), these are the differences we'd expect to be told about:

| Question | Why it matters to us |
|---|---|
| Gross or net of VAT? | a 6 % / 21 % mix changes every margin we compute |
| Are discounts deducted? | changes the average basket, not just the total |
| Are cancelled or voided tickets excluded? | one endpoint may filter them, another not |
| Are B2B, delivery and wholesale channels included? | a shop with a B2B contract diverges from its neighbours for a real reason |
| Days with no register closing — skipped or counted as zero? | changes a monthly total, and the day count behind an average |

### What the panel does today

- **Revenue level** comes from `monthly-sales`, and from `pnl/monthly` on the
  valuation screen so that revenue and costs share one source. A margin built
  from two endpoints is arithmetic on two different definitions.
- **`sales-kpis` is no longer trusted for the level** — only for the **N / N-1
  ratio at equal days**, which is the one thing it can do that the monthly
  series cannot (truncating both years to the same day count). A ratio survives
  a wrong level because both sides come from the same endpoint.
- The gap between the two is **measured and shown on screen** above 2 %, rather
  than being quietly absorbed.

That's a lot of machinery to work around a question only you can answer.

### Acceptance

```bash
# Pick one shop and one CLOSED month — no partial month, no ambiguity.
SHOP=4; M=2026-06

# 1. The monthly series.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/monthly-sales?from=$M&to=$M" \
  | jq --argjson s $SHOP '.shops[] | select(.shop_id == $s) | .months[0].ca'
```

```bash
# 2. The sales KPIs, same window expressed as dates.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/sales-kpis?date_from=2026-06-01&date_to=2026-06-30" \
  | jq --argjson s $SHOP '.shops[] | select(.shop_id == $s) | (.ca // .turnover)'
```

```bash
# 3. The P&L.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/$SHOP/pnl/monthly?from=$M&to=$M" \
  | jq '.months[0].turnover'
```

The three numbers must be equal. If they aren't, the answer we need is **which
one is the revenue of that shop for that month**, and what the other two are
counting instead.

### Why

This is the second correctness ticket on this list, next to T5a. T5a makes the
margin right; T8 makes the number the margin is a percentage *of* right. A
correct margin on the wrong revenue is still a wrong valuation.

---

## T9 — Checklist progress over a date range

**Existing endpoints:**
`GET /consultant/shops/{shopId}/checklists?date=YYYY-MM-DD` — the checklists of **one day**
`GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=YYYY-MM-DD` — the tasks of **one day**

This is the only ticket on the list that **deletes code on our side** instead of
adding a screen.

### What to create

```
GET /consultant/shops/{shopId}/checklists/progress?from=2026-05-01&to=2026-05-31
```

`from` and `to` are dates (`YYYY-MM-DD`), inclusive. The payload is exactly what
the two existing endpoints return, grouped by day:

```json
{
  "days": [
    {
      "date": "2026-05-25",
      "checklists": [
        {
          "id": 10,
          "name": "Ouverture boutique",
          "tasks": [
            { "task_id": 1, "task_name": "Ouverture caisse", "status": "DONE",
              "is_mandatory": true, "attachment_id": 77,
              "review_is_accepted": 1, "review_rating": 5, "review_comment": "RAS" }
          ]
        }
      ]
    },
    { "date": "2026-05-26", "checklists": [] }
  ]
}
```

Three rules that matter to us:

1. **Every requested day appears**, even with an empty `checklists` array. A day
   with no checklist and a day missing from the response are different facts:
   one is "nothing was planned", the other is "we don't know".
2. Each task object is **exactly** what `/checklists/{id}/progress` returns
   today. Don't reshape it; we already parse that form, and the day view will
   keep using the single-day endpoint.
3. A sensible cap is fine — say 62 days. Tell us the limit and the error you
   return above it, and we'll split the range client-side. Silent truncation is
   the one thing we cannot work with: a month that comes back short reads as a
   month where nothing happened.

### Why

The panel now has a **Checklist Tasks report**, weekly and monthly. A weekly
report covers 6 days; a monthly one covers about 26. With only a per-day
endpoint, and one extra call per checklist per day, a monthly report is roughly
**50 sequential round trips** — at a 10 s client timeout, the gateway cuts it off
long before the page renders.

We shipped two workarounds rather than wait:

- calls are grouped with `curl_multi`, so a month costs 2 waits instead of 50;
- **every closed day is frozen into a local table** (`mac_checklist_day_snapshot`)
  and never requested again.

That second one is the expensive part. It brought a table, a JSON payload per
day, a "is this day closed yet?" rule, and a cache that can go stale if an old
review is corrected. **This endpoint deletes all of it** — the table, the
freezing logic, and the staleness question. We would read the range and render.

It is also the same pattern you already built for P1
(`/consultant/shops/monthly-sales`) and that T4 and T5b ask for: one call, one
window, many rows.

### Acceptance

```bash
# 1. Every requested day is present, including days with no checklist.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/2/checklists/progress?from=2026-05-01&to=2026-05-31" \
  | jq '.days | length'
```

Must be `31` — not "the number of days that had activity".

```bash
# 2. The per-day payload matches the single-day endpoints, day by day.
diff \
  <(curl -s -H "Authorization: Bearer $TOKEN" \
      "$API/consultant/shops/2/checklists/10/progress?date=2026-05-25" | jq -S '.tasks') \
  <(curl -s -H "Authorization: Bearer $TOKEN" \
      "$API/consultant/shops/2/checklists/progress?from=2026-05-25&to=2026-05-25" \
    | jq -S '.days[0].checklists[] | select(.id == 10) | .tasks')
```

Must print nothing.

```bash
# 3. The range never returns a day outside it.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/2/checklists/progress?from=2026-05-01&to=2026-05-31" \
  | jq '[.days[].date | select(. < "2026-05-01" or . > "2026-05-31")] | length'
```

Must be `0`.

---

## T10 — Product reference data: `is_pdm`, and a mandatory sector

**Endpoints:** every payload that returns products or product categories —
today `GET /shops/{shopId}/statistics/sales/product-category-groups`
(`grouping=category|group|month`), plus any product list the back office exposes.

**Scope:** this ticket is about the product *reference data* and the form that
maintains it. There is no new endpoint here.

### Part A — `is_pdm` on the product

Add a boolean `is_pdm` to the product record, and expose it wherever products
are returned to clients.

| Field | Type | Default | Null when |
|---|---|---|---|
| `is_pdm` | bool (`0`/`1`) | `0` | never — a product is PDM or it is not |

Two rules, both of which matter more than the field itself:

1. **Never null.** A product whose flag was never set must return `false`, not
   `null` and not an absent key. A missing key forces every client to invent a
   default, and two clients will invent different ones.
2. **Present even on products that are not PDM.** Filtering the flag out of the
   payload to save bytes turns "not PDM" and "unknown" into the same thing.

### Part B — the sector, mandatory in the product form

The product form must **require** a sector, chosen from a closed list —
*boulangerie*, *traiteur*, … — with no free text and no empty value.

| Field | Type | Null when |
|---|---|---|
| `sector_id` | int | never once this ticket ships |
| `sector_name` | string | never once this ticket ships |

Three things we need beyond the form itself:

1. **The list, as data.** A `GET` that returns the sectors (`id`, `name`) so the
   panel labels them the same way the back office does. Sectors hard-coded on
   our side would drift the day one is renamed.
2. **A decision on the existing catalogue.** Making the field mandatory on new
   products leaves the old ones without a sector. Either backfill them, or tell
   us they can be null so we can display "sector not set" instead of silently
   dropping those products out of a breakdown — the one thing we must not do is
   count them as zero.
3. **The sector on the sales payloads.** A sector known only inside the product
   form is invisible to us. It has to travel with the product wherever the
   product travels, exactly like the category does today.

### Why

The panel breaks revenue down by category. Category is fine for a shelf, too fine
for a conversation with a franchisee: "your traiteur is down 8 %" is a sentence
they act on, "your category 47 is down 8 %" is not. The sector is the level the
franchise actually steers by, and it does not exist in any payload we receive.

`is_pdm` is the same problem one notch further: as long as PDM products cannot be
told apart from the rest, no screen and no report can separate them.

### Acceptance

```bash
# 1. Every product carries the flag, PDM or not — no absent key, no null.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/shops/4/statistics/sales/product-category-groups?grouping=category&from=2026-07-01&to=2026-07-31" \
  | jq '[.. | objects | select(has("product_id"))
         | select(has("is_pdm") | not or (.is_pdm == null))] | length'
```

Must be `0`.

```bash
# 2. The sector travels with the product.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/shops/4/statistics/sales/product-category-groups?grouping=category&from=2026-07-01&to=2026-07-31" \
  | jq '[.. | objects | select(has("product_id")) | {product_id, sector_id, sector_name, is_pdm}] | .[0:5]'
```

Every row must show a `sector_id` and a `sector_name`.

```bash
# 3. The sector list is readable as data, not only as a dropdown.
curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/product-sectors" | jq '.data'
```

Must return the same list the product form offers.

```bash
# 4. The form refuses a product with no sector.
#    Expect a 422 and a message naming the field.
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Test sans secteur","price":1.0}' "$API/products"
```

Must be `422`, not `200`.

---

## T11 — Every task in the network, for one day

**Existing endpoints, all three of which we call today:**
`GET /consultant/network/tasks/ranking?date=YYYY-MM-DD` — the shops and their completion rate
`GET /consultant/shops/{shopId}/tasks?date=YYYY-MM-DD` — the tasks of one shop
`GET /consultant/shops/{shopId}/checklists?date=YYYY-MM-DD` — the checklists of one shop
`GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=YYYY-MM-DD` — the reviews

### The problem

The panel has a screen that lists **every task of the network for a day**,
grouped shop › checklist › task, so a consultant can review them one after the
other instead of walking back and forth between shops. Building that screen
costs, today:

```
 1 call   the ranking, to know which shops exist
 N calls  /tasks          — one per shop
 N calls  /checklists     — one per shop
 M calls  /progress       — one per (shop, checklist) pair; M ≈ 4 × N
```

For 5 shops with 4 checklists each, that is **31 requests**. For 30 shops,
**181**. They go out in parallel, capped at 24 at a time, so it lands as three
or four waves — but it is still the single most expensive screen in the panel,
and it grows linearly with the network.

Only the third family carries the review — `review_rating`, `review_is_accepted`
— which is the whole point of the screen. `/tasks` does not have it.

### What to create

```
GET /consultant/network/tasks?date=2026-07-30
```

Optional `shop_ids=2,4,7` to narrow it. The payload is the three existing ones,
already joined:

```json
{
  "date": "2026-07-30",
  "shops": [
    {
      "shop_id": 4,
      "shop_name": "Atelier by – Halle",
      "shop_city": "Halle",
      "completion_rate": 62,
      "checklists": [
        {
          "id": 43,
          "name": "CO-01 — Ouverture",
          "tasks": [
            {
              "task_id": 1216,
              "task_name": "Vérifier si le magasin est propre.",
              "status": "DONE",
              "is_mandatory": 1,
              "requires_photo": 1,
              "completed_by": "Nathan C.",
              "completed_at": "2026-07-30 04:40:00",
              "note": null,
              "completion_id": 169,
              "attachment_id": 393,
              "review_id": 55,
              "review_rating": 5,
              "review_is_accepted": 1,
              "review_comment": null
            }
          ]
        }
      ]
    }
  ]
}
```

Every field above already exists in one of the three endpoints we call. Nothing
new has to be computed — this ticket is a **join**, not a feature.

### Three rules

1. **Every shop of the ranking is present**, even one with no task that day.
   A shop that disappears from a list reads as a shop that does not exist.
2. **Every task is present**, done or not. The panel decides what to show; a
   payload that pre-filters takes that decision away from it.
3. **`review_*` is present on every done task**, `null` when no review exists.
   An absent key and "not reviewed yet" must not be the same thing.

### If T3 ships first

T3 adds the completion and review fields to `GET /shops/{id}/tasks`. If it lands
before this one, T11 becomes almost free: the shop loop already returns
everything, and this endpoint is a `foreach` over the shops. Doing T3 first is
the cheaper order.

### Why

This is the screen a consultant opens every morning. At 30 shops it issues 181
requests to render one page, and our HTTP client gives up after 10 s per call —
the gateway cuts the page before the network does. One call would make it a
screen we can put on a phone in a bakery, on a mobile connection.

It is also the last place where the panel still assembles by hand what the API
could hand over assembled: three families of calls, a join on `task_id`, and a
service whose only job is to stitch them together.

### Acceptance

```bash
# 1. One call returns the whole network — shops, checklists, tasks.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/network/tasks?date=2026-07-30" \
  | jq '{shops: (.shops | length),
         checklists: [.shops[].checklists | length] | add,
         tasks: [.shops[].checklists[].tasks | length] | add}'
```

The three numbers must match what the per-shop endpoints return for the same
day.

```bash
# 2. No shop of the ranking is missing — including a shop with zero task.
diff <(curl -s -H "Authorization: Bearer $TOKEN" \
        "$API/consultant/network/tasks/ranking?date=2026-07-30" \
        | jq -S '[.data.shops[].shop_id] | sort') \
     <(curl -s -H "Authorization: Bearer $TOKEN" \
        "$API/consultant/network/tasks?date=2026-07-30" \
        | jq -S '[.shops[].shop_id] | sort')
```

Must print nothing.

```bash
# 3. THE ONE THAT MATTERS — the review travels with the task.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/network/tasks?date=2026-07-30" \
  | jq '[.shops[].checklists[].tasks[]
         | select(.status == "DONE")
         | select(has("review_rating") | not)] | length'
```

Must be `0` — the key is always there, `null` when there is no review.

```bash
# 4. Same figures as the endpoint we use today, for one shop.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/network/tasks?date=2026-07-30&shop_ids=4" \
  | jq '[.shops[0].checklists[].tasks[]] | length'
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/tasks?date=2026-07-30" | jq '.data.tasks | length'
```

The two numbers must be equal.

---

## What each ticket changes for the panel

| Ticket | What we can delete | What we gain |
|---|---|---|
| T1 | the local review journal | the real author, for every review |
| T2 | the duplicated field names | a payload we can trust |
| T3 | 2 round trips per page load | photo and review on the day view |
| T4 | 25 concurrent requests | 1 request |
| T5a | the fallback that re-reads a whole month day by day | a net margin we can trust |
| T5b | N parallel requests | 1 request |
| T6 | a local table | validation visible to all clients |
| T7 | our TTL guesses | fewer requests, fresher data |
| T8 | a hard-coded source preference per screen | one revenue figure, the same on every screen |
| T9 | a snapshot table and its freezing logic | a checklist report that just reads its window |
| T10 | nothing — we have no sector today | revenue read by sector, and PDM products we can isolate |
| T11 | 31 to 181 requests, and the service that joins them | the network review screen, in one call |

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

---

## T12 — Quarterly sales history, per shop

**Existing endpoint we would otherwise abuse:**
`GET /consultant/shops/sales-kpis?date_from=&date_to=` — one window, all shops

### The problem

Each shop card on the *Boutiques* screen carries four tiles: CA du mois,
tickets/jour, panier moyen, produits/client. Tapping a tile now opens a small
modal that places the shop **among the others** and against **the same window
last year**. Both of those cost exactly one extra call, because every shop
shares the same window.

The design also asks for a **six-quarter sparkline** in that modal — the shape
of the trend, not its numbers. That is the one piece we did not build, because
there is no endpoint for it and the workaround is bad:

- 6 quarters = 18 monthly windows **per shop**;
- at 5 shops that is 90 windows, at 30 shops 540;
- `sales-kpis` groups by *window*, not by *shop*, so the best case is 18 calls
  (one per month, all shops) — on a screen a consultant opens several times a day.

Eighteen calls to draw six points is the wrong trade. Hence this ticket.

### What we need

`GET /consultant/shops/sales-kpis/quarterly?quarters=6`
Optional `shop_ids=2,4,7`. Optional `end=YYYY-Qn` to end elsewhere than the
current quarter.

```json
{
  "quarters": ["2025-Q1","2025-Q2","2025-Q3","2025-Q4","2026-Q1","2026-Q2"],
  "shops": [
    { "shop_id": 4,
      "series": [
        { "quarter": "2025-Q1", "tickets": 17800, "ca": 336000.00,
          "products": 35600, "avg_basket": 18.88, "products_per_ticket": 2.0 },
        { "quarter": "2025-Q2", "tickets": null, "ca": null,
          "products": null, "avg_basket": null, "products_per_ticket": null }
      ] }
  ]
}
```

Same object shape as `sales-kpis` — a quarter must look like a window, or we
end up with two parsers and the second one rots.

### Two rules this payload must follow

1. **Every requested quarter is present for every requested shop**, even before
   the shop existed. A shop that opened in 2026 gets four quarters of `null` —
   `null`, not `0`. Zero means "sold nothing"; absent means "we don't know", and
   a sparkline that draws a zero for a shop that did not exist yet invents a
   collapse.
2. **The current quarter is partial, and says so** — either an
   `is_partial: true` flag on the last entry, or its window bounds. Drawing a
   third of a quarter next to five full ones shows a crash that isn't there.

### How to check it

```bash
# 1. Six quarters, in order, for the whole network.
curl -s "$API/consultant/shops/sales-kpis/quarterly?quarters=6" \
  -H "Authorization: Bearer $TOKEN" | jq '.quarters'

# 2. A shop that did not exist gets null, never zero.
curl -s "$API/consultant/shops/sales-kpis/quarterly?quarters=6&shop_ids=$NEW_SHOP" \
  -H "Authorization: Bearer $TOKEN" | jq '.shops[0].series[] | {quarter, ca}'

# 3. The quarterly total agrees with the three monthly windows it covers.
#    (Same shop, same period, via the endpoint we already use.)
curl -s "$API/consultant/shops/sales-kpis?date_from=2025-01-01&date_to=2025-03-31" \
  -H "Authorization: Bearer $TOKEN" | jq '.shops[] | select(.shop_id==4) | .ca'
```

### What it unlocks

The sparkline ships, and the *Boutiques* screen keeps its current cost: two
calls total, whatever the number of shops. Until then the modal shows the
network position and last year — and simply has no chart, which is stated in
the code, not silently missing.

---

## T13 — `product_id` on a task that controls a product

**Existing endpoints:**
`GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=…`
`GET /consultant/shops/{shopId}/tasks?date=…`

**One field.** The smallest ticket on this list, and it lights up a screen that
is already built, tested and deployed.

### The problem

A consultant opens *« Contrôle qualité – Salade Grecque »*. They see the photo
the shop took — and nothing to compare it against. The product is judged from
memory, on the screen where they decide whether it is acceptable.

The panel now renders the product's **technical-sheet photo side by side** with
the photo taken. That comparison is live. It stays dark because the task payload
never says *which product* is being controlled.

### Today

```json
{
  "task_id": 1216,
  "task_name": "Contrôle qualité – Salade Grecque",
  "status": "DONE",
  "is_mandatory": 1,
  "requires_photo": 1,
  "attachment_id": 393
}
```

The product exists only inside a free-text label.

### Wanted — add

| Field | Type | Null when |
|---|---|---|
| `product_id` | int | the task controls no product — send `null`, never `0`, never an absent key |

```json
{
  "task_id": 1216,
  "task_name": "Contrôle qualité – Salade Grecque",
  "attachment_id": 393,
  "product_id": 87
}
```

Four rules:

1. **It must be the catalogue's own identifier** — the one `GET /products`
   returns. A line id or a recipe id makes the lookup miss silently.
2. **`null` for tasks with no product.** *« Nettoyage du sol »* has none, and
   that is not an error: the panel shows a single column. Absent and `null` are
   different facts (rule 2 of the payload rules).
3. **On both endpoints**, so the shop screen and the network list behave the
   same.
4. Three spellings are accepted on our side — `product_id`, `id_product`,
   `productId` — so a naming mismatch costs no round trip. Pick one, keep it.

### Why an identifier and not the name

We built name matching first, and threw it away. With a catalogue holding
« Salade » and « Grecque », matching *« Contrôle qualité – Salade Grecque »*
returned **« Grecque »** — the wrong product, shown with the authority of a
reference photo, on a screen where someone decides whether food leaves the
counter. A wrong visual is worse than no visual. The id is the only key we will
use.

### Acceptance

```bash
# 1. Every task carries the key — present, even when null.
curl -s "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" \
  -H "Authorization: Bearer $TOKEN" | jq '[.tasks[] | has("product_id")] | all'
# expect: true

# 2. A product-control task carries a real id, and it resolves in the catalogue.
PID=$(curl -s "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" \
  -H "Authorization: Bearer $TOKEN" | jq '[.tasks[] | .product_id | select(. != null)][0]')
curl -s "$API/products" -H "Authorization: Bearer $TOKEN" | jq --argjson p "$PID" \
  '[.. | objects | select(.id == $p) | .name] | first'
# expect: the product name, not null

# 3. The same field on the day view.
curl -s "$API/consultant/shops/4/tasks?date=2026-07-30" \
  -H "Authorization: Bearer $TOKEN" | jq '[.data.tasks[] | has("product_id")] | all'
# expect: true
```

### What is already done on our side

The field is carried end to end — the three `data-done` producers feed the review
modal — `GET /products` is read tolerantly (list, `{data}`, `{products}`,
`{items}` envelopes; photo as a URL, an object, a list, or an `attachment_id`)
and cached 30 minutes. `GET /checklists/product-photo?id=…&debug=1` on the panel
shows exactly what was read. Nothing else waits on us.
