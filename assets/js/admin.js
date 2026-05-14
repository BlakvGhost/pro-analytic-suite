(function () {
    var period = document.querySelector('.analytic-suite-filters select[name="period"]');
    var dateFields = document.querySelectorAll('.analytic-suite-filters input[type="date"]');

    function syncDateFields() {
        if (!period) {
            return;
        }

        dateFields.forEach(function (field) {
            field.disabled = period.value !== 'custom';
        });
    }

    if (period) {
        period.addEventListener('change', syncDateFields);
        syncDateFields();
    }
})();
