(function () {
    var period = document.querySelector('.analytic-suite-filters select[name="period"]');
    var dateFields = document.querySelectorAll('.analytic-suite-filters input[type="date"]');
    var palette = ['#0f766e', '#d69a3a', '#2563eb', '#b42318', '#7c3aed', '#15803d', '#c2410c', '#475569'];

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

    function numberFormat(value) {
        return new Intl.NumberFormat(document.documentElement.lang || 'fr-FR', {
            maximumFractionDigits: 2
        }).format(value);
    }

    function getTooltip() {
        var tooltip = document.querySelector('.analytic-suite-chart-tooltip');

        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'analytic-suite-chart-tooltip';
            document.body.appendChild(tooltip);
        }

        return tooltip;
    }

    function hideTooltip() {
        var tooltip = document.querySelector('.analytic-suite-chart-tooltip');

        if (tooltip) {
            tooltip.classList.remove('is-visible');
        }
    }

    function showTooltip(event, point) {
        var tooltip = getTooltip();
        var title = document.createElement('strong');
        var value = document.createElement('span');

        tooltip.textContent = '';
        title.textContent = point.label;
        value.textContent = numberFormat(point.value);
        tooltip.appendChild(title);
        tooltip.appendChild(value);

        if (point.meta) {
            var meta = document.createElement('small');
            meta.textContent = point.meta;
            tooltip.appendChild(meta);
        }

        tooltip.style.left = event.pageX + 14 + 'px';
        tooltip.style.top = event.pageY + 14 + 'px';
        tooltip.classList.add('is-visible');
    }

    function setupCanvas(canvas) {
        var parent = canvas.parentElement;
        var width = parent ? parent.clientWidth : canvas.clientWidth;
        var ratio = window.devicePixelRatio || 1;
        var chartWidth = Math.max(240, width);

        canvas.width = chartWidth * ratio;
        canvas.height = 260 * ratio;
        canvas.style.height = '260px';

        var ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        return {
            ctx: ctx,
            width: chartWidth,
            height: 260
        };
    }

    function parsePoints(canvas) {
        try {
            return JSON.parse(canvas.dataset.chartPoints || '[]').filter(function (point) {
                return point && point.label && Number(point.value) >= 0;
            });
        } catch (error) {
            return [];
        }
    }

    function drawLabel(ctx, text, x, y, maxWidth) {
        var label = String(text);

        if (ctx.measureText(label).width <= maxWidth) {
            ctx.fillText(label, x, y);
            return;
        }

        while (label.length > 1 && ctx.measureText(label + '...').width > maxWidth) {
            label = label.slice(0, -1);
        }

        ctx.fillText(label + '...', x, y);
    }

    function drawBar(canvas, points) {
        var chart = setupCanvas(canvas);
        var ctx = chart.ctx;
        var max = Math.max.apply(null, points.map(function (point) { return point.value; })) || 1;
        var left = 34;
        var bottom = 40;
        var top = 18;
        var gap = 10;
        var areaWidth = chart.width - left - 18;
        var areaHeight = chart.height - top - bottom;
        var barWidth = Math.max(18, (areaWidth - gap * (points.length - 1)) / points.length);
        var hitAreas = [];

        ctx.clearRect(0, 0, chart.width, chart.height);
        ctx.strokeStyle = '#dfe7e3';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(left, top);
        ctx.lineTo(left, top + areaHeight);
        ctx.lineTo(chart.width - 10, top + areaHeight);
        ctx.stroke();

        points.forEach(function (point, index) {
            var x = left + index * (barWidth + gap);
            var height = point.value > 0 ? Math.max(2, (point.value / max) * areaHeight) : 0;
            var y = top + areaHeight - height;

            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(x, y, barWidth, height);
            ctx.fillStyle = '#17211d';
            ctx.font = '700 11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(numberFormat(point.value), x + barWidth / 2, Math.max(12, y - 6));
            ctx.fillStyle = '#66746d';
            ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            drawLabel(ctx, point.label, x + barWidth / 2, chart.height - 16, Math.max(34, barWidth + 8));

            hitAreas.push({
                x: x,
                y: y,
                width: barWidth,
                height: height,
                point: point
            });
        });

        canvas._analyticSuiteHitAreas = hitAreas;
    }

    function drawDoughnut(canvas, points) {
        var chart = setupCanvas(canvas);
        var ctx = chart.ctx;
        var totalValue = points.reduce(function (sum, point) { return sum + point.value; }, 0);
        var total = totalValue || 1;
        var radius = Math.min(chart.width * 0.26, 88);
        var centerX = Math.max(112, chart.width * 0.32);
        var centerY = chart.height / 2;
        var start = -Math.PI / 2;
        var hitAreas = [];

        ctx.clearRect(0, 0, chart.width, chart.height);

        if (!totalValue) {
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
            ctx.strokeStyle = '#dfe7e3';
            ctx.lineWidth = radius * 0.44;
            ctx.stroke();
        }

        points.forEach(function (point, index) {
            var end = start + (point.value / total) * Math.PI * 2;

            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, start, end);
            ctx.closePath();
            ctx.fillStyle = palette[index % palette.length];
            ctx.fill();

            hitAreas.push({
                start: start,
                end: end,
                centerX: centerX,
                centerY: centerY,
                inner: radius * 0.56,
                outer: radius,
                point: point
            });

            start = end;
        });

        ctx.beginPath();
        ctx.arc(centerX, centerY, radius * 0.56, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.fillStyle = '#17211d';
        ctx.font = '800 22px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(numberFormat(totalValue), centerX, centerY + 7);

        points.slice(0, 7).forEach(function (point, index) {
            var legendX = chart.width * 0.62;
            var legendY = 44 + index * 24;

            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(legendX, legendY - 9, 10, 10);
            ctx.fillStyle = '#17211d';
            ctx.font = '700 12px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            drawLabel(ctx, point.label + ' (' + numberFormat(point.value) + ')', legendX + 16, legendY, chart.width - legendX - 22);
        });

        canvas._analyticSuiteHitAreas = hitAreas;
    }

    function drawLine(canvas, points) {
        var chart = setupCanvas(canvas);
        var ctx = chart.ctx;
        var max = Math.max.apply(null, points.map(function (point) { return point.value; })) || 1;
        var left = 38;
        var right = 18;
        var top = 18;
        var bottom = 42;
        var areaWidth = chart.width - left - right;
        var areaHeight = chart.height - top - bottom;
        var hitAreas = [];

        ctx.clearRect(0, 0, chart.width, chart.height);
        ctx.strokeStyle = '#dfe7e3';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(left, top);
        ctx.lineTo(left, top + areaHeight);
        ctx.lineTo(chart.width - right, top + areaHeight);
        ctx.stroke();

        ctx.strokeStyle = '#0f766e';
        ctx.lineWidth = 3;
        ctx.beginPath();
        points.forEach(function (point, index) {
            var x = left + (points.length === 1 ? areaWidth / 2 : (index / (points.length - 1)) * areaWidth);
            var y = top + areaHeight - (point.value / max) * areaHeight;

            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.stroke();

        points.forEach(function (point, index) {
            var x = left + (points.length === 1 ? areaWidth / 2 : (index / (points.length - 1)) * areaWidth);
            var y = top + areaHeight - (point.value / max) * areaHeight;

            ctx.beginPath();
            ctx.arc(x, y, 5, 0, Math.PI * 2);
            ctx.fillStyle = palette[index % palette.length];
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.fillStyle = '#66746d';
            ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            drawLabel(ctx, point.label, x, chart.height - 16, 62);

            hitAreas.push({
                x: x - 10,
                y: y - 10,
                width: 20,
                height: 20,
                point: point
            });
        });

        canvas._analyticSuiteHitAreas = hitAreas;
    }

    function findHit(canvas, event) {
        var rect = canvas.getBoundingClientRect();
        var x = event.clientX - rect.left;
        var y = event.clientY - rect.top;
        var areas = canvas._analyticSuiteHitAreas || [];

        return areas.find(function (area) {
            if (typeof area.start === 'number') {
                var dx = x - area.centerX;
                var dy = y - area.centerY;
                var distance = Math.sqrt(dx * dx + dy * dy);
                var angle = Math.atan2(dy, dx);

                if (angle < -Math.PI / 2) {
                    angle += Math.PI * 2;
                }

                return distance >= area.inner && distance <= area.outer && angle >= area.start && angle <= area.end;
            }

            return x >= area.x && x <= area.x + area.width && y >= area.y && y <= area.y + area.height;
        });
    }

    function renderChart(canvas) {
        var points = parsePoints(canvas);
        var type = canvas.dataset.chartType || 'bar';

        if (!points.length) {
            return;
        }

        if (type === 'doughnut') {
            drawDoughnut(canvas, points);
        } else if (type === 'line') {
            drawLine(canvas, points);
        } else {
            drawBar(canvas, points);
        }
    }

    function initCharts() {
        var charts = document.querySelectorAll('.analytic-suite-chart');

        charts.forEach(function (canvas) {
            var panel = canvas.closest('.analytic-suite-chart-panel');
            renderChart(canvas);

            if (panel) {
                panel.classList.add('is-enhanced');
            }

            canvas.addEventListener('mousemove', function (event) {
                var hit = findHit(canvas, event);

                if (hit) {
                    showTooltip(event, hit.point);
                } else {
                    hideTooltip();
                }
            });

            canvas.addEventListener('mouseleave', hideTooltip);
        });

        if (charts.length) {
            window.addEventListener('resize', function () {
                charts.forEach(renderChart);
            });
        }
    }

    initCharts();
})();
