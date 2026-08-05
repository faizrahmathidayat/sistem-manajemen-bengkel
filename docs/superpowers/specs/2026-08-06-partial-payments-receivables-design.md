# Partial Payments & Receivables (Migration 010) — Design

## Context

Migration 010 in `Rencana_Migrasi_Database_Sistem_Bengkel.md` §13 calls for a payment/receivables module: recording customer payments against posted invoices, with support for partial payment (a payment can be less than an invoice's grand total, and an invoice can receive several payments over time) and for one payment covering several invoices at once.

The permission catalog for this module (`payment.view`, `payment.create`, `payment.void`, `payment.print`) and a disabled "Penerimaan Pembayaran" sidebar placeholder were already seeded during the Foundation phase (`database/seeders/MenuPermissionSeeder.php:71-81`, `resources/views/partials/sidebar.blade.php:20-25`), in anticipation of this module. Notably there is no `payment.post` permission — this plan confirms that omission was intentional (see Decision 1).

This is the first module to write to the `invoices` table's financial state after posting — every prior invoice mutation (`InvoiceService::updateInvoice`/`cancelInvoice`) is `DRAFT`-only and untouched by this plan.

## Decisions

**1. A payment receipt is posted the instant it's created — there is no draft stage.**
Confirmed by the missing `payment.post` permission code: this module has exactly one mutating create action (`payment.create`) and one reversal action (`payment.void`), mirroring a real-world receipt book more than Invoice's draft/post lifecycle. `PaymentReceipt.status` still exists as a column (`posted` / `void`) so the void/audit trail has somewhere to live, but every row is created directly as `posted`.

**2. One payment can allocate across several invoices (full many-to-many), matching the source doc's `payment_allocations` design.**
The create flow is customer-first: pick Cabang → Customer, the system lists every invoice for that customer/branch with an outstanding balance (`status` in `posted`/`partially_paid`), and the user checks any subset and enters an allocation amount per invoice. This is a real UI/validation cost over a single-invoice-per-payment shortcut, but it's what the source schema already commits to (`unique(payment_receipt_id, invoice_id)`, no cardinality limit) and was chosen explicitly over the simpler alternative.

**3. `invoices.paid_amount` is a cached, atomically-maintained column — not computed live from `payment_allocations` on every read.**
This follows the same convention already used for `sparepart_branch_stocks.on_hand_qty`/`reserved_qty`: a running total updated inside the same transaction that changes it (payment create/void), rather than a `SUM()` join on every invoice list/show render. `outstanding_amount` is *not* stored — it's trivially `grand_total - paid_amount`, cheap enough to compute in an accessor with no query cost. `InvoiceStatus` gains two constants (`PARTIALLY_PAID`, `PAID`) and is recomputed from `paid_amount` every time it changes:

```
paid_amount <= 0                        -> posted
0 < paid_amount < grand_total            -> partially_paid
paid_amount >= grand_total (epsilon)     -> paid
```

**4. Void is whole-document, not per-allocation-line.**
Voiding a `PaymentReceipt` reverses every one of its allocations in the same transaction (decrementing each affected invoice's `paid_amount` and recomputing its status). There is no concept of voiding a single allocation line while leaving the rest of the receipt intact — this matches every other void/cancel flow in the project (PKB, Invoice), all of which are whole-document operations.

**5. Payment method is a closed set via a constants class, not free text.**
`App\Support\PaymentMethod`: `CASH`, `TRANSFER`, `QRIS`, `DEBIT_CARD`, `CREDIT_CARD`, `OTHER` — same shape as `InvoiceStatus`/`WorkOrderStatus`. Rendered as a `<select>` in the create form; stored as the matching string constant.

**6. Locking discipline: lock every referenced invoice, in ascending `id` order, before mutating any of them — both on create and on void.**
This is the same deadlock-avoidance convention used everywhere else in this project (stock rows locked ascending by `sparepart_branch_id`; here the shared resource is `invoices` rows instead). Two payment receipts that both touch overlapping invoices (in any submitted order) will always acquire locks in the same global order, so no AB-BA deadlock is constructible. Every check that depends on current state (branch/customer match, invoice status, remaining outstanding balance) is re-verified *after* acquiring the lock, never trusted from the value the form was built with — a second payment could have landed on the same invoice between page-load and submit.

## Data Model

### `payment_receipts`

| Column | Type / rule |
|---|---|
| id | bigint PK |
| number | varchar(50), unique — generated via `DocumentNumberGenerator`, prefix `PAY` |
| branch_id | FK `branches` |
| customer_id | FK `customers` |
| payment_date | date |
| payment_method | varchar(20) — `App\Support\PaymentMethod` constant |
| reference_number | varchar(100), nullable |
| amount | decimal(18,2), `CHECK (amount > 0)` — must equal `SUM(payment_allocations.allocated_amount)` for this receipt (enforced in the FormRequest, not the DB, since it's a cross-row invariant) |
| status | varchar(20) — `posted` / `void`, default `posted` |
| notes | text, nullable |
| voided_at, voided_by, void_reason | nullable (mirrors `invoices.cancelled_at/cancelled_by/cancel_reason` naming, just voided\_\* to match the `payment.void` permission wording) |
| created_by, updated_by | via `HasAudit` |
| timestamps | |

Index: `(branch_id, payment_date, status)`.

### `payment_allocations`

| Column | Type / rule |
|---|---|
| id | bigint PK |
| payment_receipt_id | FK `payment_receipts`, cascade on delete |
| invoice_id | FK `invoices` |
| allocated_amount | decimal(18,2), `CHECK (allocated_amount > 0)` |
| timestamps | |

`unique(payment_receipt_id, invoice_id)` — an invoice cannot appear twice in the same receipt.
Index: `(invoice_id)`.

### `invoices` changes

- New column `paid_amount` decimal(18,2) default 0, `CHECK (paid_amount >= 0)`.
- `App\Support\InvoiceStatus` gains `PARTIALLY_PAID = 'partially_paid'` and `PAID = 'paid'`.
- New accessor `getOutstandingAmountAttribute()` → `round($this->grand_total - $this->paid_amount, 2)`.
- No change to `cancelInvoice()`/`updateInvoice()` — both remain `DRAFT`-only and are structurally unreachable once an invoice has any payment (a payment can only be created against `posted`/`partially_paid`, both already past the `DRAFT` stage).

## Business Logic — `App\Services\PaymentService` (new)

### `createPaymentReceipt(array $data): PaymentReceipt`

`$data`: `branch_id`, `customer_id`, `payment_date`, `payment_method`, `reference_number`, `amount`, `notes`, `allocations: [{invoice_id, allocated_amount}, ...]`.

1. Sort `$data['allocations']` by `invoice_id` ascending.
2. For each, in that order: `Invoice::whereKey($invoiceId)->lockForUpdate()->first()`.
3. For each locked invoice, re-verify (throw `DomainException` naming the offending invoice on any failure, whole transaction rolls back):
   - `branch_id` and `customer_id` match the payment's `branch_id`/`customer_id`.
   - `status` is `posted` or `partially_paid`.
   - `allocated_amount <= outstanding_amount` (recomputed from the just-locked `paid_amount`, not a value carried from the request).
4. Create the `PaymentReceipt` row (`status = posted`, `number` from `DocumentNumberGenerator` with prefix `PAY`).
5. For each allocation: create the `PaymentAllocation` row, `$invoice->paid_amount += $allocated_amount`, recompute `$invoice->status` per the Decision 3 table, save.
6. Return `$paymentReceipt->fresh('allocations')`.

### `voidPaymentReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt`

1. `PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->first()`; throw if `status !== posted`.
2. Load its allocations; lock the referenced invoices in ascending `invoice_id` order.
3. For each: `$invoice->paid_amount -= $allocation->allocated_amount` (clamped to 0 with the same epsilon convention used elsewhere for float noise), recompute status, save.
4. Update the receipt: `status = void`, `void_reason = $reason`, `voided_by = auth()->id()`, `voided_at = now()`.
5. Return the receipt.

## Authorization

`PaymentReceiptPolicy` (new):
- `view(User $user, PaymentReceipt $receipt)` → `hasPermissionToInBranch('payment.view', $receipt->branch_id)`.
- `void(User $user, PaymentReceipt $receipt)` → `$receipt->status === 'posted' && hasPermissionToInBranch('payment.void', $receipt->branch_id)`.

`create` has no existing model to scope against, so it's checked directly in `StorePaymentReceiptRequest::authorize()` against the submitted `branch_id` — same pattern as `StoreGoodsReceiptRequest`/`StoreStockAdjustmentRequest`, not a Policy method.

## UI

1. **`payment-receipts` index** — list page (`list-filter-bar`/`empty-state` pattern), search by number, branch multiselect filter.
2. **`payment-receipts/create`** — Cabang `<select>` → Customer `<select>` (AJAX-scoped to that branch, mirrors the existing Vehicle/PKB cascading pattern), then an AJAX-loaded table of that customer's outstanding invoices (number, invoice date, grand total, already paid, outstanding) each with a checkbox + allocation-amount input; payment date/method/reference/notes fields; running total of checked allocations shown against the `amount` field.
3. **`payment-receipts/{id}` show** — header, allocation lines (invoice number/link, allocated amount), Void button (with reason) gated by `@can('void', ...)`, void info card once voided (same `@if ($voided_at) ... @elseif (posted) @can(...) @endif` pattern already used on Invoice/PKB).
4. **Invoice show page** — new "Riwayat Pembayaran" card: every `PaymentAllocation` referencing this invoice (via its `payment_receipt`), each linking to that receipt; outstanding balance shown alongside the existing grand total; status badge extended for `partially_paid` (warning/yellow) and `paid` (success/green).
5. Sidebar placeholder → real link, gated by `payment.view` (already the case for the placeholder).

## Explicitly out of scope

- Receivables aging report / `v_receivable_aging` (Migration 011 / Laporan track).
- Voiding a `posted` **invoice** to reverse its stock deduction — a different concept from this module's payment-void, not yet designed, `invoice.void` permission remains reserved for that future flow.
- Payment methods beyond the 6 listed constants.
- Print/receipt PDF output (`payment.print` permission exists but unused, same deferral pattern as `invoice.print`/`invoice.email` in the Sales Invoice module).
