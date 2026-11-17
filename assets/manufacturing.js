/* Fertigung: printing the pick list without an inline handler (architecture 16.11., G3). Bound on the document, so what the
   live update puts in place (D-1408) works without setting up again. */
(function () {
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-taxmod-mf-print]')) {
            window.print();
        }
    });
})();

/* D-1412: the tick in the head of a grid with ticks selects every row. ListPage::grid escapes its heads, so the box is put in
   here; bound on the document like the print, so a live update keeps it working. */
(function () {
    function heads() {
        document.querySelectorAll('table.taxmod-mf-ticks').forEach(function (table) {
            var head = table.querySelector('thead th');

            if (!head || head.querySelector('input')) {
                return;
            }

            var all = document.createElement('input');
            all.type = 'checkbox';
            all.className = 'taxmod-mf-all';
            all.setAttribute('aria-label', head.textContent || 'Alle');
            head.textContent = '';
            head.appendChild(all);
        });
    }

    document.addEventListener('change', function (event) {
        var all = event.target;

        if (!all.classList || !all.classList.contains('taxmod-mf-all')) {
            return;
        }

        all.closest('table').querySelectorAll('tbody input[type="checkbox"]').forEach(function (box) {
            box.checked = all.checked;
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', heads);
    } else {
        heads();
    }

    document.addEventListener('taxmod:replaced', heads);
})();
