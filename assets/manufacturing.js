/* Fertigung: the page's small acts without inline handlers (architecture 16.11., G3). Bound on the document, so rows the
   live update puts in place (D-1408, taxmod:replaced) work without setting up again. */
(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-taxmod-mf-print], [data-taxmod-mf-full], [data-taxmod-mf-fill]');
        if (!button) {
            return;
        }
        if (button.hasAttribute('data-taxmod-mf-print')) {
            window.print();
            return;
        }
        if (button.hasAttribute('data-taxmod-mf-full')) {
            var field = button.parentNode.querySelector('.taxmod-mf-fit');
            if (field) {
                field.value = field.dataset.full;
            }
            return;
        }
        var form = button.closest('form');
        var boards = parseFloat(button.previousElementSibling && button.previousElementSibling.value);
        if (!form || isNaN(boards)) {
            return;
        }
        form.querySelectorAll('.taxmod-mf-fit').forEach(function (input) {
            input.value = boards * parseFloat(input.step);
        });
    });
})();
