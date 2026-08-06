@extends('layouts.app')
@section('title', 'Catat Pembayaran')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>Catat Pembayaran</h1>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('payment-receipts.store') }}" id="paymentReceiptForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Cabang</label>
                        <select name="branch_id" id="branchSelect" class="form-select" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="payment_date" value="{{ now()->format('Y-m-d') }}" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Metode</label>
                        <select name="payment_method" class="form-select" required>
                            @foreach ($paymentMethods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">No. Referensi</label>
                        <input type="text" name="reference_number" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Total Nominal Dibayar</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" class="form-control" readonly required>
                        <div class="form-text">Terisi otomatis dari total alokasi di bawah.</div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6">Alokasi ke Invoice</h2>
                <p class="text-muted small" id="invoicesHint">Pilih customer terlebih dahulu untuk melihat invoice yang punya sisa piutang.</p>
                <div class="text-muted small d-none" id="invoicesLoading">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Memuat invoice...
                </div>
                <div class="table-responsive">
                    <table class="table table-sm" id="outstandingInvoicesTable" style="display:none">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Nomor</th>
                                <th>Tanggal</th>
                                <th>Grand Total</th>
                                <th>Sudah Dibayar</th>
                                <th>Sisa Piutang</th>
                                <th style="width: 160px">Nominal Dibayar</th>
                            </tr>
                        </thead>
                        <tbody id="outstandingInvoicesBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('payment-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const invoicesHint = document.getElementById('invoicesHint');
        const invoicesLoading = document.getElementById('invoicesLoading');
        const invoicesTable = document.getElementById('outstandingInvoicesTable');
        const invoicesBody = document.getElementById('outstandingInvoicesBody');
        const amountInput = document.getElementById('amountInput');

        $(customerSelect).select2({ placeholder: '-- Pilih Customer --', width: '100%' });

        function resetCustomer() {
            customerSelect.innerHTML = '<option value="">-- Pilih Cabang Dulu --</option>';
            $(customerSelect).prop('disabled', true).trigger('change.select2');
            resetInvoices();
        }

        function resetInvoices() {
            invoicesLoading.classList.add('d-none');
            invoicesTable.style.display = 'none';
            invoicesBody.innerHTML = '';
            invoicesHint.style.display = 'block';
            recomputeAmount();
        }

        function recomputeAmount() {
            let total = 0;
            invoicesBody.querySelectorAll('.invoice-allocation-input').forEach(function (input) {
                if (input.closest('tr').querySelector('.invoice-check').checked) {
                    total += parseFloat(input.value || '0');
                }
            });
            amountInput.value = total.toFixed(2);
        }

        branchSelect.addEventListener('change', function () {
            resetCustomer();
            if (!branchSelect.value) return;

            $(customerSelect).prop('disabled', false);
            customerSelect.innerHTML = '<option value="">-- Pilih Customer --</option>';

            // The shared `/lookup/customers` endpoint requires a 3-character search term
            // (Select2 AJAX-as-you-type convention), which doesn't fit this page's "just list
            // every customer of this branch" need — use the dedicated endpoint from Task 4 instead.
            fetch(`/payment-receipts/lookup/customers-by-branch/${branchSelect.value}`)
                .then(r => r.json())
                .then(function (customers) {
                    customers.forEach(function (customer) {
                        const opt = document.createElement('option');
                        opt.value = customer.id;
                        opt.textContent = customer.text;
                        customerSelect.appendChild(opt);
                    });
                    $(customerSelect).trigger('change.select2');
                });
        });

        customerSelect.addEventListener('change', function () {
            resetInvoices();
            if (!customerSelect.value || !branchSelect.value) return;

            invoicesHint.style.display = 'none';
            invoicesLoading.classList.remove('d-none');

            fetch(`/payment-receipts/lookup/outstanding-invoices/${customerSelect.value}?branch_id=${branchSelect.value}`)
                .then(r => r.json())
                .then(function (invoices) {
                    invoicesLoading.classList.add('d-none');
                    invoicesHint.style.display = invoices.length ? 'none' : 'block';
                    invoicesTable.style.display = invoices.length ? '' : 'none';
                    invoices.forEach(function (invoice, index) {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><input type="checkbox" class="invoice-check"></td>
                            <td><input type="hidden" class="invoice-id" name="allocations[${index}][invoice_id]" value="${invoice.id}">${invoice.number}</td>
                            <td>${invoice.invoice_date}</td>
                            <td>${invoice.grand_total.toLocaleString('id-ID')}</td>
                            <td>${invoice.paid_amount.toLocaleString('id-ID')}</td>
                            <td>${invoice.outstanding_amount.toLocaleString('id-ID')}</td>
                            <td><input type="number" step="0.01" min="0" max="${invoice.outstanding_amount}" class="form-control form-control-sm invoice-allocation-input" name="allocations[${index}][allocated_amount]" value="0" disabled></td>
                        `;
                        invoicesBody.appendChild(tr);
                    });

                    invoicesBody.querySelectorAll('.invoice-check').forEach(function (checkbox) {
                        checkbox.addEventListener('change', function () {
                            const input = checkbox.closest('tr').querySelector('.invoice-allocation-input');
                            input.disabled = !checkbox.checked;
                            if (checkbox.checked && parseFloat(input.value) === 0) {
                                input.value = input.max;
                            }
                            if (!checkbox.checked) {
                                input.value = 0;
                            }
                            recomputeAmount();
                        });
                    });
                    invoicesBody.querySelectorAll('.invoice-allocation-input').forEach(function (input) {
                        input.addEventListener('input', recomputeAmount);
                    });
                });
        });
    })();
    </script>
    @endpush
@endsection
