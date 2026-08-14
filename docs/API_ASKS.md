# API asks — the send-ready list

**What this file is.** One self-contained page listing everything the consultant
panel still needs from the API, written to be copied straight into an e-mail. It
adds one column the other documents do not have: **where each field or endpoint
lands in the panel** — the function that consumes it and the template that
displays it — so a backend change can be traced to the screen it lights up.

**The long form is elsewhere.** Payload examples, before/after JSON and
per-ticket reasoning live in `BACKEND_SPEC.md`; the ordered to-do list, plus the
infra/DBA part, lives in `DEV_HANDOFF.md`. This file is the *summary you send*,
not the specification you build from.

---

**Subject:** TFB consultant panel — API work still outstanding (14 items, 2 blocking)

Hi,

Here is the consolidated list of everything the consultant panel still needs from
the API. All URLs are relative to the `/api/v1` base. Nothing on this list has
been confirmed shipped on your side — if something already exists, tell us and
we'll drop it.

The long form (payload examples, field by field) is in the repo:
`docs/DEV_HANDOFF.md` and `docs/BACKEND_SPEC.md`.

---

## 1. Endpoints to create — 7

| # | Endpoint | What we need | Where it's needed in the panel |
|---|---|---|---|
| **T11** | `GET /consultant/network/tasks?date=YYYY-MM-DD` (+ `&shop_ids=1,2,3`) | For every shop, the day's tasks + their checklist + progress, in **one** call | Replaces the 3-per-shop fan-out in `NetworkTaskListService::forDate()` → `ChecklistController::networkTasks` → `checklist/all_tasks.twig`, and `::reviewStack` → `checklist/review_stack.twig` |
| **T9** | `GET /consultant/shops/{shopId}/checklists/progress?from=&to=` | Progress over a date range in one call | `ChecklistRepository::getProgressForPairs` → `ChecklistReportService` → `ChecklistReportController` → `report/checklist_week.twig`, `report/checklist_month.twig` (today: one call per checklist × per day) |
| **T4** | `GET /consultant/shops/material-complaints` (+ `?status=`, `?from=&to=`) | All shops' material complaints in one call | `ClaimService::getClaimsForAllShops` → `ClaimController::index` → `claim/index.twig` (today: one call per shop) |
| **T5b** | `GET /consultant/shops/pnl/monthly?from=&to=` | Monthly P&L, all shops, over a range | `ShopService::getMonthlyPnlMany` → `ValuationService` → `ValuationController` (JSON `/shops/valuation`) → `shop/list.twig` |
| **T6** | `POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate` | Mark an **existing** review as validated, idempotent — instead of posting a second review | `ChecklistService::submitTaskReview` → `ChecklistController::submitReview`; called by `window.tfbReview` in `checklist/_review_submit.twig` (included by `all_tasks.twig`, `shop_tasks.twig`, `review_stack.twig`) |
| **T12** | `GET /consultant/shops/sales-kpis/quarterly?quarters=6` (+ `&shop_ids=`, `&end=`) | Sales KPIs already aggregated by quarter | Quarterly comparison built by `TrendsService` → `TrendsController` → `trends/index.twig` (today: one ranged `sales-kpis` call per quarter) |
| **T10** | `GET /consultant/product-sectors` | The product → sector reference table | Sector grouping used by `ReportService` for the category-mix block of `report/view.twig` |

---

## 2. Existing endpoints to enrich — 7

| # | Endpoint | What we need | Where it's needed in the panel |
|---|---|---|---|
| **T5a** ⛔ *blocking* | `GET /consultant/shops/{id}/pnl/monthly` | `material` present on **every** month, including months with no purchase (then `0`, not absent). Today it is missing on some months, so the valuation silently under-counts | `ShopService::getMonthlyPnlMany` → `ValuationService` → `shop/list.twig` |
| **T8** ⛔ *blocking* | `monthly-sales` vs `sales-kpis` vs `pnl/monthly` | Three different revenue figures for the same shop and month. Which one is authoritative, and what do the other two count (VAT? discounts? delivery?) | The three are displayed side by side: `trends/index.twig` (`TrendsService`), `shop/list.twig` (`ShopKpiInsightService`), `report/view.twig` (`ReportService`) |
| **T14** 🆕 | `GET /products` | **Confirm the catalogue carries a usable product photo, and in which form.** We read it tolerantly today — a direct URL string, a nested `{ "url": … }` object, an `images[]` list, or an `attachment_id` — because we have never seen the real payload. Tell us which it is, or add one. If it is an attachment id, confirm it is signed by `GET /attachments/{id}/presigned-url`. Also confirm `id` and a name field are present | `ProductRepository::all()` → `ProductPhotoService::forProductId()` → `ChecklistController::productPhoto` → JS in `checklist/_review_modal_script.twig` → `#dnRefPhoto` (the "Fiche technique" column) in `checklist/_review_modal.twig`, visible on `all_tasks.twig` and `shop_tasks.twig` |
| **T13** | `GET /consultant/shops/{id}/tasks` (and T11) | `product_id` on every task, so a quality-control task says *which* product it is about | `ChecklistController::withCompletionDetails` (field whitelist) → `NetworkTaskListService::enrich()` → `data-done` in `checklist/_task_item.twig` and `checklist/all_tasks.twig` → `chargerReference()` in `_review_modal_script.twig`. **Depends on T14** — without a photo in the catalogue, `product_id` buys nothing |
| **T3** | `GET /consultant/shops/{id}/tasks` | Completion + review fields directly on the task: `completion_id`, `attachment_id`, `attachment_filename`, `review_id`, `review_rating`, `review_is_accepted`, `review_comment` | Today `ChecklistController::withCompletionDetails` re-calls `/checklists` and `/checklists/{cid}/progress` just to recover them → `checklist/shop_tasks.twig`, `all_tasks.twig`, `review_stack.twig` |
| **T1** | task reviews | `review_by`, `review_by_name`, `reviewed_at` — who validated, and when | Same whitelist → `data-done` in `checklist/_task_item.twig` → displayed in `checklist/_review_modal.twig` |
| **T7** | `sales-kpis`, `pnl-summary`, `monthly-sales`, `targets`, `network/tasks/ranking` | `Cache-Control: max-age=…` and `ETag` response headers | Our client-side TTLs are guesses today. Affects the post-login prewarm (`dashboard/loading.twig`), `shop/list.twig`, `trends/index.twig`, `report/view.twig` |

---

## 3. Already built on the panel side — for information, no work for you

| Route | Purpose | Chain |
|---|---|---|
| `GET /system/perf`, `GET /system/perf/data`, `POST /system/perf/beacon` | Performance heatmap (median / p95 per route, per hour) | `PerfController` → `system/perf.twig`, writes `mac_consultant_perf` |
| `GET /checklists/product-photo` | Serves the technical-sheet photo to the review modal | `ChecklistController::productPhoto` → `ProductPhotoService` → `checklist/_review_modal_script.twig` |
| `POST /notes/ai-correct` | The "Corriger" button on note taking | `NoteController::aiCorrect` → `TextCorrectionService` → `note/create.twig` |

**Infra, for the DBA.** The panel creates its own tables
(`CREATE TABLE IF NOT EXISTS`); the performance table `mac_consultant_perf` needs
`SELECT, INSERT, UPDATE, DELETE`. Retention is handled in-request — **no
scheduled job**. No personal data is stored: the user key is derived from the
bearer token, never a name or an e-mail. `database/*.sql` in the repo mirrors
every table, and `GET /system/db-setup` verifies them in one call.

---

## 4. Three conventions that apply to everything above

1. **Batch endpoints return a map keyed by `shop_id`**, not a flat list we have
   to re-index.
2. **Missing ≠ zero.** Send `null` when a figure does not exist; `0` is charted
   as a real zero and reads as a collapse.
3. **Stable types.** Numbers as numbers (not `"1 234,56 €"`), dates as
   `YYYY-MM-DD`, ids as integers.

---

## 5. Suggested order

| Order | Ticket | Why here |
|---|---|---|
| 1 | **T5a** | Blocking — the valuation is wrong until it is fixed |
| 2 | **T8** | Blocking — we cannot pick a revenue figure without your answer |
| 3 | **T14** | Answer-only; T13 is pointless without it |
| 4 | **T13** | Completes the photo comparison |
| 5 | **T11** | Biggest single latency win |
| 6 | **T3** | Removes the two follow-up calls per shop |
| 7–10 | **T4**, **T5b**, **T9**, **T6** | Same pattern, smaller screens |
| 11–13 | **T12**, **T10**, **T1** | Enrichment |
| 14 | **T7** | Once the payloads are stable |

---

## 6. Acceptance — one line each

```sh
export API=https://<api-host>/api/v1
export TOKEN=<bearer>

T11  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/network/tasks?date=2026-08-14" | head -c 400
T9   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/checklists/progress?from=2026-08-01&to=2026-08-31" | head -c 400
T4   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/material-complaints?status=open" | head -c 400
T5b  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/pnl/monthly?from=2026-01&to=2026-08" | head -c 400
T6   curl -s -X POST -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/task-reviews/42/validate" | head -c 400
T12  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/sales-kpis/quarterly?quarters=6" | head -c 400
T10  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/product-sectors" | head -c 400
T14  curl -s -H "Authorization: Bearer $TOKEN" "$API/products" | head -c 800     # id + a name + a photo?
T13  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/tasks?date=2026-08-14" | grep -o product_id
T5a  curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/pnl/monthly" | grep -c material
T8   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/monthly-sales?from=2026-06&to=2026-06"
T8   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/sales-kpis?date_from=2026-06-01&date_to=2026-06-30"
T8   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/pnl/monthly?year=2026"
     # ^ same shop, same closed month: the three revenues must agree, or you tell us what each counts
T3   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/tasks?date=2026-08-14" | grep -o completion_id
T1   curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/1/tasks?date=2026-08-14" | grep -o reviewed_at
T7   curl -sI -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/sales-kpis" | grep -iE 'cache-control|etag'
```

Thanks,

---

## Note for us, not for the e-mail

The "where it's needed" column is read from the code, with one exception worth
remembering before anyone quotes it as fact: **T10** and **T12** describe
endpoints that do not exist yet, so there is no call site to trace. Their
consumer (`ReportService` → `report/view.twig`, `TrendsService` →
`trends/index.twig`) is inferred from what the ticket is *for*, not observed.
Every other chain in this file is a call that exists today.
