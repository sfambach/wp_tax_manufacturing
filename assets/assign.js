/* His word 2026-11-16 «habe zugewiesen übernimmt er aber nicht»: choosing in the stock's material search assigns at once;
   «Zuweisen» without «Übernehmen» first takes what is marked (D-1387). */
(function () {
    function send(form) {
        if (!form.querySelector('input[name=do][type=hidden]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'do';
            input.value = 'assign';
            form.appendChild(input);
        }
        // D-1408: requestSubmit goes through the modeller's live forms; submit() would reload the whole page.
        if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    document.addEventListener('taxmod-stock-material-picked', function (event) {
        var form = event.target.closest && event.target.closest('form.taxmod-mf-assign');
        if (form) {
            send(form);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.classList || !form.classList.contains('taxmod-mf-assign')) {
            return;
        }
        var thing = form.querySelector('.taxmod-material-search-out[data-part=thing]');
        var take = form.querySelector('.taxmod-material-search-take');
        if (thing && thing.value === '' && take) {
            event.preventDefault();
            take.click();
        }
    }, true);
})();
