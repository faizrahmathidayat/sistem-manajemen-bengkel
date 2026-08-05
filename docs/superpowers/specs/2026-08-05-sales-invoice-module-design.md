# Sales Invoice Module — Design

## Context

Migration 009 in `Rencana_Migrasi_Database_Sistem_Bengkel.md` calls for an Invoice module: one invoice per completed PKB (work order), with a snapshot of PKB lines, header-level discount/PPN, and a draft → posted lifecycle where posting deducts physical stock, releases the PKB's reservations, and writes a kartu stok (inventory movement) entry.

The permission catalog for this module (`invoice.view`, `invoice.create`, `invoice.edit`, `invoice.post`, `invoice.void`, `invoice.print`, `invoice.email`) and a disabled "Invoice — Segera Hadir" sidebar placeholder were already seeded during the Foundation: Identity & Access phase (`database/seeders/MenuPermissionSeeder.php`, `resources/views/partials/sidebar.blade.php:13-19`), in anticipation of this module. This plan wires that placeholder up to real functionality.

**Already built (prior session, not redone by this plan):**
- `database/migrations/2026_08_05_000003_create_invoices_table.php`, `..._000004_create_invoice_details_table.php`
- `app/Models/Invoice.php`, `app/Models/InvoiceDetail.php`, `app/Support/InvoiceStatus.php`, `app/Support/InvoiceDetailItemType.php`
- `App\Support\WorkOrderStatus::COMPLETED` and `App\Support\InventoryMovementType::USAGE_OUT` constants
- `App\Services\InvoiceService` with `createFromWorkOrder(WorkOrder $wo): Invoice` and `postInvoice(Invoice $invoice): Invoice`, both DB-transaction-safe, lock-ordered consistently with the rest of the inventory subsystem, and unit-verified via a rollback-safe tinker smoke test (not an automated test file yet — Task 3/5 below add real feature test coverage on top of these).

This plan adds the missing layer on top: the PKB "mark as done" transition that unlocks invoicing, and the Invoice controller/routes/views/authorization that let a user actually drive `InvoiceService` from the browser.

## Decisions

**1. PKB completion is a new explicit transition, not implicit.**
`WorkOrder.status` gains `COMPLETED` (already added). A PKB becomes eligible for `COMPLETED` from `OPEN` directly, or from `SHORTAGE` only if `shortage_overridden_at` is already set (i.e. the shortage was explicitly accepted first via the existing `overrideShortage` flow). This mirrors how `WorkOrderPolicy` already gates other transitions on current status, and re-uses the existing shortage-override signal instead of inventing a second "are we really okay with this" flag. New permission: `pkb.complete`.

**2. Invoice creation is one click from the PKB, not a manual form.**
Unlike Goods Receipt / Stock Adjustment (which have a `create` GET form because a human types in header + lines), an Invoice's header and lines are entirely derived from its PKB by `InvoiceService::createFromWorkOrder()`. So there is no `invoices/create` view — `InvoiceController::store()` takes a `work_order_id`, is triggered by a "Buat Invoice" button on `work-orders/show.blade.php` (visible when the PKB is `COMPLETED` and has no invoice yet), and redirects straight to `invoices.show`.

**3. Invoice lifecycle in this plan: `draft` → `posted` only. No void/cancel yet.**
The permission catalog already reserves `invoice.void` for a future cancellation flow, and `invoice.print`/`invoice.email` for output features — none of those are built here. The user's request for this phase was specifically "buat Invoice draft" + "posting Invoice"; a void/cancel flow has real implications for reversing a posted stock deduction that deserve their own design pass, so it's left as explicitly out of scope rather than guessed at.

**4. Discount/PPN are edited on the draft before posting, as simple header percentages.**
`InvoiceService::createFromWorkOrder()` already creates the draft with `discount_percent`/`tax_percent` at 0 (subtotal-only grand total). `InvoiceController::update()` (Task 4) recalculates on save:

```
discount_amount = round(subtotal * discount_percent / 100, 2)
taxable_base     = subtotal - discount_amount
tax_amount       = round(taxable_base * tax_percent / 100, 2)
grand_total      = round(taxable_base + tax_amount, 2)
```

i.e. PPN is applied after discount (standard Indonesian invoicing convention), matching `ck_invoices_discount_percent_range` (0–100) already enforced at the DB layer.

**5. Authorization mirrors `GoodsReceiptPolicy` exactly (draft-gated `update`/`post`), plus one `create` ability that takes the source `WorkOrder` for branch-scoping.**
`InvoicePolicy::create(User $user, WorkOrder $workOrder)` is invoked via `$this->authorize('create', [Invoice::class, $workOrder])` — the standard Laravel pattern for a "create" ability that needs context beyond the (not-yet-existing) model instance.

## Explicitly out of scope (future work)

- Void/cancel a posted or draft invoice (`invoice.void` permission exists but unused)
- Print/PDF and email delivery (`invoice.print`, `invoice.email` permissions exist but unused)
- Payment/receivables (Migration 010 — `payment.*` permissions already seeded, separate plan)
- Any UI for the two `audit_logs`/reporting placeholders already visible (disabled) in the sidebar
