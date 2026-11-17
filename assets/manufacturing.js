/* Fertigung: printing the pick list without an inline handler (architecture 16.11., G3). Bound on the document, so what the
   live update puts in place (D-1408) works without setting up again. */
(function () {
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-taxmod-mf-print]')) {
            window.print();
        }
    });
})();
