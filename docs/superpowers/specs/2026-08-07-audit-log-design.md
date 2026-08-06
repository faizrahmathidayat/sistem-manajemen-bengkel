# Audit Log (Migration 011) — Design

## Context

Migration doc §14.1 specifies an `audit_logs` table and explicitly requires it for "post/void invoice, post/void payment, approval/dispatch/receive transfer, stock adjustment, perubahan harga, dan perubahan permission user." The sidebar placeholder ("Audit Log", gated by `audit_log.view`) has been disabled since the Foundation phase, and this permission code — unlike almost every other operational module in this app — is seeded **`is_branch_scoped => false`** (`database/seeders/MenuPermissionSeeder.php:214-221`): it's a **global** administrative permission, not per-branch. This is the opposite scoping from the mistake caught in the Receivables Report plan, and is called out explicitly here so it isn't second-guessed later.

Unlike every prior sub-project this session, this one is **not a new isolated module** — it's a cross-cutting retrofit that adds one small, additive logging call to several already-shipped, already-tested Service/Controller methods (Invoice, Payment Receipt, Stock Adjustment, Stock Transfer, branch permission assignment). Each integration point is a single line inserted into an existing `DB::transaction()` closure; none of them change existing business logic, return values, or error paths.

## Decisions

**1. Logging is explicit, not automatic (no model observers).**
The user's own critical-event list names specific *business* events (`invoice.posted`, `payment_receipt.voided`), not generic model lifecycle events (`created`/`updated`/`deleted`). A model observer watching `Invoice::updated` can't distinguish "posted" from "a line was edited" from "cancelled" without extra state-diffing — an explicit call at the exact point in each Service/Controller method where the business action actually happens is simpler, more precise, and matches how this codebase's Service layer already isolates each action into its own well-named method (`postInvoice()`, `voidPaymentReceipt()`, etc.).

**2. New `App\Services\AuditLogger` class, instantiated like every other service in this codebase** (`(new AuditLogger())->log(...)`, matching `(new DocumentNumberGenerator())->next(...)` / `(new InvoiceService())->postInvoice(...)` — not a static-method class, not a trait, for consistency).

```php
public function log(string $event, ?int $branchId, ?Model $auditable, array $oldValues = [], array $newValues = []): void
```

**3. Fail-safe by construction: `AuditLogger::log()` never throws.** Its entire body is wrapped in a `try { AuditLog::create([...]) } catch (\Throwable $e) { Log::error(...); }` — any failure (a JSON-encoding edge case, an unexpected DB error on the `audit_logs` table specifically) is swallowed and reported to Laravel's own log channel, never rethrown. This is what makes it safe to call **inside** the same `DB::transaction()` closure as the business action it's logging: if `AuditLogger::log()` can never throw, it can never trigger a rollback of the surrounding transaction. Calling it inside the transaction (rather than after commit) also means the audit row and the business change are atomic when both succeed — a call after commit would risk a crash-between-commit-and-log gap and lose atomicity for no benefit.

**4. `branch_id` is always passed explicitly by the caller, never inferred by reflection.** Most audited models have a plain `branch_id` column (`Invoice`, `PaymentReceipt`, `StockAdjustment`), but `StockTransfer` has `from_branch_id`/`to_branch_id` instead — there's no single uniform attribute name to introspect safely. Each call site passes the semantically correct one explicitly (see the event list below).

**5. `old_values`/`new_values` are small, event-specific payloads — not full model snapshots.** Every event in scope is a status transition (plus a few relevant fields, e.g. a void reason), not an arbitrary-field edit. Capturing e.g. every column of `Invoice` on every `invoice.posted` event would bloat the table and add noise to the diff viewer for no reader benefit. Each call site builds a minimal `['status' => 'draft']` → `['status' => 'posted']`-shaped array (plus 1-2 extra fields where relevant, e.g. `reason` on a void/cancel).

**6. `auditable_type`/`auditable_id` are real Eloquent polymorphic columns (`AuditLog::auditable(): MorphTo`)**, not this codebase's lighter `reference_type`/`reference_id` string-pair convention (used in `inventory_movements`/`inventory_reservations` specifically to avoid a real FK across differing tables at high write volume). Audit logs are read-mostly and benefit from a real `morphTo()` for the viewer UI to eager-load and link back to the source document without a manual `switch` per type (`StockCardController::resolveReference()`'s pattern is heavier than needed here).

**7. The 10-event curated list, each a constant in a new `App\Support\AuditEvent` class** (mirrors `PaymentMethod`'s `::LABELS` pattern for the UI's event filter dropdown):

| Constant | Event string | Hook point (file:method) | `branch_id` source | `auditable` |
|---|---|---|---|---|
| `INVOICE_POSTED` | `invoice.posted` | `InvoiceService::postInvoice()` | `$fresh->branch_id` | the `Invoice` |
| `INVOICE_CANCELLED` | `invoice.cancelled` | `InvoiceService::cancelInvoice()` | `$fresh->branch_id` | the `Invoice` |
| `PAYMENT_RECEIPT_CREATED` | `payment_receipt.created` | `PaymentService::createPaymentReceipt()` | `$data['branch_id']` | the `PaymentReceipt` |
| `PAYMENT_RECEIPT_VOIDED` | `payment_receipt.voided` | `PaymentService::voidPaymentReceipt()` | `$fresh->branch_id` | the `PaymentReceipt` |
| `STOCK_ADJUSTMENT_POSTED` | `stock_adjustment.posted` | `StockAdjustmentController::post()` | `$fresh->branch_id` | the `StockAdjustment` |
| `STOCK_TRANSFER_DISPATCHED` | `stock_transfer.dispatched` | `StockTransferController::dispatchTransfer()` (`app/Http/Controllers/StockTransferController.php:280`) | `$fresh->from_branch_id` | the `StockTransfer` |
| `STOCK_TRANSFER_RECEIVED` | `stock_transfer.received` | `StockTransferController::receive()` (`:381`) | `$fresh->to_branch_id` | the `StockTransfer` |
| `STOCK_TRANSFER_VOIDED` | `stock_transfer.voided` | `StockTransferController::cancel()` (`:415`) | `$fresh->from_branch_id` | the `StockTransfer` |
| `USER_BRANCH_PERMISSION_GRANTED` | `user_branch_permission.granted` | `UserBranchPermissionAssignmentController::store()` | the `$branch` route param | the `User` (the one granted the permission, not the actor) |
| `USER_BRANCH_PERMISSION_REVOKED` | `user_branch_permission.revoked` | `UserBranchPermissionAssignmentController::destroy()` | the `$branch` route param | the `User` |

("Payment Receipt: create/post" from the requirements collapses to one event — `PaymentReceiptStatus` has no separate draft/post stages, per Migration 010's own confirmed design; `createPaymentReceipt()` posts immediately.) **Global** `UserPermissionAssignmentController` (non-branch-scoped permission grants) is explicitly **out of scope** — the requirement says "perubahan permission user **di cabang**", matching only the branch-scoped controller.

**8. Authorization is a single global check, no Policy.** `AuditLogController::index()` calls `$this->authorize('audit_log.view')` bare — this resolves through `Gate::before`'s zero-argument fast path (a genuine global permission check, correct here specifically *because* the permission is seeded non-branch-scoped, unlike the Receivables Report's `report.receivable.view`). No new Policy class.

**9. The Cabang filter shows every branch in the system, not `branchesWithPermission(...)`.** Since `audit_log.view` is global, a user who can reach this page at all is trusted to see every branch's trail — `Branch::orderBy('name')->get()` populates the filter, mirroring how `UserController::index()`'s branch filter is a convenience narrower, not a security boundary.

## Data Model

### `audit_logs`

| Column | Type / rule |
|---|---|
| id | bigint PK |
| branch_id | nullable FK `branches`, `nullOnDelete()` |
| user_id | nullable FK `users`, `nullOnDelete()` (null = system/CLI-originated) |
| event | varchar(100) — one of the `AuditEvent` constants |
| auditable_type | varchar(100), nullable |
| auditable_id | bigint unsigned, nullable |
| old_values | json, nullable |
| new_values | json, nullable |
| ip_address | varchar(45), nullable (`request()->ip()` — 45 chars fits IPv6) |
| user_agent | text, nullable |
| timestamps | (`created_at`/`updated_at` — `updated_at` is always equal to `created_at` in practice since rows are never mutated after insert, but every other table in this project uses plain `$table->timestamps()` and there's no reason to special-case this one) |

Indexes: `(branch_id, created_at)`, `(auditable_type, auditable_id)`, `(event)`, `(user_id)` — covering the 4 UI filters (Cabang+date range, Modul/Event, User) plus reverse lookup from a specific document.

### `App\Models\AuditLog`

`belongsTo(Branch::class)`, `belongsTo(User::class)`, `morphTo('auditable')`, `$casts = ['old_values' => 'array', 'new_values' => 'array']`. No `HasAudit` trait — this table is itself the audit trail; it doesn't get its own `created_by`/`updated_by`, and rows are never updated.

## UI

- `resources/views/audit-logs/index.blade.php` — bespoke filter card (same shape as the Receivables Report's, not `list-filter-bar`): Cabang (multiselect, reusing `branch-multiselect-filter.blade.php` fed with *all* branches per Decision 9), User (text search on name), Modul/Event (`<select>` populated from `AuditEvent::LABELS`), Rentang Tanggal (`created_at` range). `simplePaginate(20)`.
- Each row: timestamp, actor (`optional($log->user)->name ?? 'Sistem'`), event label, a resolved link to the auditable document (via `$log->auditable`'s own `route()`-able show page — a small `resolveAuditableLink()` helper switching on `auditable_type`, same shape as `StockCardController::resolveReference()` but driven by the real `morphTo` relation instead of a lightweight string pair), and an expandable **simple diff view**: a small 3-column table (Field | Sebelum | Sesudah) iterating the union of `old_values`/`new_values` keys — no external diff library, just an `array_keys(array_merge($old, $new))` loop.
- Sidebar: `resources/views/partials/sidebar.blade.php`'s disabled "Audit Log" placeholder becomes a real link, same swap pattern used for every other module this session.

## Explicitly out of scope

- Automatic logging via model observers/events — see Decision 1.
- Global (non-branch) permission grant/revoke (`UserPermissionAssignmentController`) — see Decision 7's note.
- "Perubahan harga" (price changes) from the migration doc's own broader wording — no specific controller/action was named in this session's requirements, and this codebase has several distinct "price" fields across modules (`ServiceCatalog.default_price`, `SparepartBranch.selling_price`, invoice line `unit_price`, etc.) with no single obvious hook point; deferred until a follow-up explicitly scopes which one(s).
- CSV/export of the audit trail — not requested.
- Retention/archival policy for `audit_logs` (it will grow forever, unbounded) — not requested, flagged here only so it isn't forgotten indefinitely.
