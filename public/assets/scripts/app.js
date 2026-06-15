document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const message = form.dataset.confirmMessage;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

const formatPrice = (value, currency) => {
    if (!Number.isFinite(value)) {
        return '-';
    }

    return new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 2,
        minimumFractionDigits: 2,
        style: 'currency',
        currency,
    }).format(value);
};

const formatDate = (time) => {
    if (time && typeof time === 'object') {
        return new Intl.DateTimeFormat('fr-FR').format(new Date(time.year, time.month - 1, time.day));
    }

    if (typeof time !== 'string') {
        return '-';
    }

    return new Intl.DateTimeFormat('fr-FR').format(new Date(`${time}T00:00:00`));
};

const createLineSeries = (chart, options) => {
    if (window.LightweightCharts.LineSeries && typeof chart.addSeries === 'function') {
        return chart.addSeries(window.LightweightCharts.LineSeries, options);
    }

    if (typeof chart.addLineSeries === 'function') {
        return chart.addLineSeries(options);
    }

    throw new Error('Lightweight Charts line series API is unavailable.');
};

const initPriceChart = (element) => {
    if (!window.LightweightCharts) {
        return;
    }

    const canvas = element.querySelector('.price-chart-canvas');
    const tooltip = element.querySelector('.price-chart-tooltip');

    if (!(canvas instanceof HTMLElement) || !(tooltip instanceof HTMLElement)) {
        return;
    }

    let config = {};

    try {
        config = JSON.parse(element.dataset.priceChart || '{}');
    } catch {
        return;
    }
    const data = (config.points || [])
        .map((point) => ({
            time: point.date,
            value: Number(point.price),
        }))
        .filter((point) => point.time && Number.isFinite(point.value));

    if (data.length < 2) {
        return;
    }

    const styles = getComputedStyle(document.documentElement);
    const textColor = styles.getPropertyValue('--text').trim();
    const mutedColor = styles.getPropertyValue('--muted').trim();
    const lineColor = styles.getPropertyValue('--accent').trim();
    const dangerColor = styles.getPropertyValue('--danger').trim();
    const lineBorderColor = styles.getPropertyValue('--line').trim();
    const panelColor = styles.getPropertyValue('--panel').trim();
    const chartHeight = 320;
    const { ColorType, CrosshairMode, LineStyle, createChart } = window.LightweightCharts;
    const chart = createChart(canvas, {
        width: canvas.clientWidth,
        height: chartHeight,
        layout: {
            background: { type: ColorType.Solid, color: panelColor },
            textColor,
        },
        grid: {
            vertLines: { color: '#eef2f7' },
            horzLines: { color: '#eef2f7' },
        },
        crosshair: {
            mode: CrosshairMode.Normal,
            vertLine: {
                color: mutedColor,
                labelBackgroundColor: lineColor,
                style: LineStyle.Dashed,
            },
            horzLine: {
                color: mutedColor,
                labelBackgroundColor: lineColor,
                style: LineStyle.Dashed,
            },
        },
        rightPriceScale: {
            borderColor: lineBorderColor,
        },
        timeScale: {
            borderColor: lineBorderColor,
            timeVisible: true,
        },
    });

    const priceSeries = createLineSeries(chart, {
        color: lineColor,
        lineWidth: 3,
        priceFormat: {
            type: 'price',
            precision: 2,
            minMove: 0.01,
        },
        priceLineVisible: false,
    });
    priceSeries.setData(data);

    const recommendedStop = config.recommendedStop;
    const recommendedPrice = Number(recommendedStop?.stopPrice);

    (config.stopLines || []).forEach((stop) => {
        const stopPrice = Number(stop.stopPrice);

        if (!Number.isFinite(stopPrice)) {
            return;
        }

        const isRecommended = recommendedStop && stop.percentage === recommendedStop.percentage;
        priceSeries.createPriceLine({
            price: stopPrice,
            color: isRecommended ? dangerColor : mutedColor,
            lineWidth: isRecommended ? 2 : 1,
            lineStyle: LineStyle.Dashed,
            axisLabelVisible: true,
            title: isRecommended ? `Stop conseillé ${stop.percentage} %` : `Stop ${stop.percentage} %`,
        });
    });

    const updateTooltip = (param) => {
        const seriesData = param.seriesData.get(priceSeries);
        const price = Number(seriesData?.value ?? seriesData?.close);

        if (!param.point || !param.time || !Number.isFinite(price)) {
            tooltip.hidden = true;

            return;
        }

        const stopDistance = Number.isFinite(recommendedPrice)
            ? ((price / recommendedPrice) - 1) * 100
            : null;

        tooltip.replaceChildren();

        const title = document.createElement('strong');
        title.textContent = `${config.symbol} · ${formatDate(param.time)}`;

        const priceLine = document.createElement('span');
        priceLine.textContent = `Prix ${formatPrice(price, config.currency || 'EUR')}`;

        tooltip.append(title, priceLine);

        if (null !== stopDistance) {
            const stopLine = document.createElement('span');
            stopLine.textContent = `Écart stop conseillé ${stopDistance.toFixed(1).replace('.', ',')} %`;
            tooltip.append(stopLine);
        }

        tooltip.hidden = false;

        const tooltipWidth = tooltip.offsetWidth;
        const tooltipHeight = tooltip.offsetHeight;
        const left = Math.min(param.point.x + 16, element.clientWidth - tooltipWidth - 8);
        const top = Math.max(param.point.y - tooltipHeight - 12, 8);

        tooltip.style.transform = `translate(${Math.max(left, 8)}px, ${top}px)`;
    };

    chart.subscribeCrosshairMove(updateTooltip);
    chart.timeScale().fitContent();
    element.classList.add('enhanced');

    if (window.ResizeObserver) {
        const resizeObserver = new ResizeObserver(() => {
            chart.applyOptions({ width: canvas.clientWidth, height: chartHeight });
            chart.timeScale().fitContent();
        });
        resizeObserver.observe(canvas);
    }
};

document.querySelectorAll('.js-price-chart').forEach(initPriceChart);
