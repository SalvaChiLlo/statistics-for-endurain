/**
 * Builds per-segment colors for a route polyline so it can be rendered as a gradient reflecting a metric
 * (speed, heart rate, cadence, elevation) instead of a single flat color.
 *
 * The color scale re-uses the same yellow -> orange -> red gradient already used elsewhere in the app's
 * charts (see `gradientColor` in `../charts/echarts-themes.js`), so the map stays visually consistent with
 * the rest of the activity's statistics.
 */
const GRADIENT_STOPS = ['#f6efa6', '#d88273', '#bf444c'].map(hexToRgb);

export const DEFAULT_METRIC = 'speed';

function hexToRgb(hex) {
    const value = parseInt(hex.slice(1), 16);
    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

function lerp(a, b, t) {
    return a + (b - a) * t;
}

function colorForRatio(ratio) {
    const clamped = Math.min(1, Math.max(0, ratio));
    const scaled = clamped * (GRADIENT_STOPS.length - 1);
    const index = Math.min(GRADIENT_STOPS.length - 2, Math.floor(scaled));
    const t = scaled - index;
    const [r1, g1, b1] = GRADIENT_STOPS[index];
    const [r2, g2, b2] = GRADIENT_STOPS[index + 1];

    return `rgb(${Math.round(lerp(r1, r2, t))}, ${Math.round(lerp(g1, g2, t))}, ${Math.round(lerp(b1, b2, t))})`;
}

function isNumber(value) {
    return 'number' === typeof value && !Number.isNaN(value);
}

/**
 * @param {Array<{lat: number, lng: number, speed: ?number, heartrate: ?number, cadence: ?number, elevation: ?number, temperature: ?number}>} points
 * @param {string} metric
 * @returns {Array<{latlngs: Array<[number, number]>, color: string}>|null} null when there is not enough data
 *          for this metric, so the caller can fall back to the flat-color rendering.
 */
export function buildGradientSegments(points, metric = DEFAULT_METRIC) {
    if (!Array.isArray(points) || points.length < 2) {
        return null;
    }

    const values = points.map(point => point[metric]).filter(isNumber);
    if (values.length < 2) {
        return null;
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    if (min === max) {
        return null;
    }

    const segments = [];
    for (let i = 0; i < points.length - 1; i++) {
        const start = points[i];
        const end = points[i + 1];
        const startValue = isNumber(start[metric]) ? start[metric] : null;
        const endValue = isNumber(end[metric]) ? end[metric] : null;

        if (null === startValue && null === endValue) {
            continue;
        }

        const value = null !== startValue && null !== endValue
            ? (startValue + endValue) / 2
            : (startValue ?? endValue);

        segments.push({
            latlngs: [[start.lat, start.lng], [end.lat, end.lng]],
            color: colorForRatio((value - min) / (max - min)),
        });
    }

    return segments.length > 0 ? segments : null;
}
