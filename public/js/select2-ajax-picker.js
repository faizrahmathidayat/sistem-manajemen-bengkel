function initAjaxSelect(el, options) {
    const opts = Object.assign({ endpoint: '', extraParams: function () { return {}; }, placeholder: '-- Cari --', onSelect: null }, options);
    const $el = $(el);
    $el.select2({
        placeholder: opts.placeholder,
        allowClear: true,
        minimumInputLength: 3,
        width: '100%',
        language: {
            inputTooShort: function () { return 'Ketik minimal 3 huruf...'; },
            searching: function () { return 'Mencari...'; },
            noResults: function () { return 'Tidak ditemukan.'; },
        },
        ajax: {
            url: opts.endpoint,
            delay: 300,
            data: function (params) {
                return Object.assign({ q: params.term }, opts.extraParams());
            },
            processResults: function (data) {
                return { results: data };
            },
        },
    });
    if (opts.onSelect) {
        $el.on('select2:select', function (e) {
            opts.onSelect(e.params.data);
        });
    }
    return $el;
}

async function preselectAjaxOption(el, options) {
    const opts = Object.assign({ endpoint: '', id: null, extraParams: function () { return {}; } }, options);
    if (!opts.id) return null;
    const params = new URLSearchParams(Object.assign({ 'ids[]': opts.id }, opts.extraParams()));
    const response = await fetch(`${opts.endpoint}?${params}`, { headers: { Accept: 'application/json' } });
    const items = await response.json();
    const item = items[0];
    if (!item) return null;
    const option = new Option(item.text, item.id, true, true);
    $(el).append(option);
    return item;
}
