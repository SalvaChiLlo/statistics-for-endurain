import {fetchJson} from "../../utils";
import L from 'leaflet';
import {createMapToolsControl} from "./leaflet-controls";
import {buildGradientSegments, DEFAULT_METRIC} from "./route-gradient";
import './ctrl-scroll-zoom';

/**
 * Maps the combined profile chart's series `id` (set from `CombinedStreamType::value` in
 * CombinedStreamProfileCharts.php) to the metric key used by `route-metrics.json` / `route-gradient.js`.
 * Series without an entry here (e.g. watts) simply have no gradient data, so `buildGradientSegments`
 * naturally returns null for them and the caller falls back to the default metric.
 */
const CHART_SERIES_TO_ROUTE_METRIC = {
    velocity: 'speed',
    pace: 'speed',
    heartrate: 'heartrate',
    cadence: 'cadence',
    spm: 'cadence',
    altitude: 'elevation',
    temp: 'temperature',
};

export default class LeafletMap {
    constructor(mapNode, data, config) {
        this.mapNode = mapNode;
        this.data = data;
        this.config = config;

        this.map = L.map(mapNode, {
            ctrlScrollZoom: true,
            minZoom: data.minZoom,
            maxZoom: data.maxZoom,
            zoomSnap: .5,
            zoomDelta: .5,
            preferCanvas: true,
        });

        if (data.tileLayer) {
            this.config.tileLayerUrls.forEach((tileLayerUrl) => {
                L.tileLayer(tileLayerUrl).addTo(this.map);
            });
        }

        this.featureGroup = null;
        this.routeMetrics = null;
        this.gradientRoutes = [];
        this.currentMetric = DEFAULT_METRIC;
    }

    async addRoutes() {
        const featureGroup = L.featureGroup();
        const polylines = await fetchJson(this.data.polylineUrl);
        const routeMetrics = await this.fetchRouteMetrics();

        for (const coordinates of polylines) {
            const gradientSegments = routeMetrics && routeMetrics.length === coordinates.length
                ? buildGradientSegments(routeMetrics, DEFAULT_METRIC)
                : null;

            if (gradientSegments) {
                const layers = gradientSegments.map(segment => L.polyline(segment.latlngs, {
                    color: segment.color,
                    weight: 3,
                    opacity: 0.9,
                    lineJoin: 'round',
                }).addTo(featureGroup));
                this.gradientRoutes.push({coordinates, layers});
            } else {
                L.polyline(coordinates, {
                    color: this.config.polylineColor,
                    weight: 2,
                    opacity: 0.9,
                    lineJoin: 'round',
                    smoothFactor: 2.0
                }).addTo(featureGroup);
            }

            if (this.data.showStartMarker) {
                this.addCircleMarker(coordinates[0], '#3ba272').addTo(featureGroup);
            }
            if (this.data.showEndMarker) {
                this.addCircleMarker(coordinates.at(-1), '#BD2D22').addTo(featureGroup);
            }
        }

        if (this.data.imageOverlay) {
            L.imageOverlay(this.data.imageOverlay, this.data.bounds, {
                attribution: '© <a href="https://zwift.com" rel="noreferrer noopener">Zwift</a>',
            }).addTo(this.map);
            this.map.setMaxBounds(this.data.bounds);
        }

        this.featureGroup = featureGroup;
        this.routeMetrics = routeMetrics;

        featureGroup.addTo(this.map);
        this.map.fitBounds(featureGroup.getBounds(), {maxZoom: this.data.maxZoom});
        this.map.addControl(createMapToolsControl({bounds: featureGroup.getBounds()}));
    }

    /**
     * Recolors the already-rendered gradient polyline segments to reflect `metric`, falling back to
     * `DEFAULT_METRIC` (and leaving the route untouched if even that has no data, matching `addRoutes()`'s
     * flat-color fallback). Updates existing Leaflet layers' style in place (`setStyle`) rather than
     * destroying/recreating them, since this runs on every chart-hover row change and needs to stay smooth
     * even for long activities. Layers are only rebuilt in the rare case where a metric's data coverage
     * produces a different number of segments than what is currently rendered.
     */
    setRouteMetric(metric) {
        if (!this.routeMetrics || this.gradientRoutes.length === 0 || this.currentMetric === metric) {
            return;
        }

        let appliedMetric = metric;
        let didApply = false;

        for (const route of this.gradientRoutes) {
            let segments = buildGradientSegments(this.routeMetrics, metric);
            if (!segments && metric !== DEFAULT_METRIC) {
                segments = buildGradientSegments(this.routeMetrics, DEFAULT_METRIC);
                appliedMetric = DEFAULT_METRIC;
            }
            if (!segments) {
                continue;
            }

            if (segments.length === route.layers.length) {
                segments.forEach((segment, index) => route.layers[index].setStyle({color: segment.color}));
            } else {
                route.layers.forEach(layer => this.featureGroup.removeLayer(layer));
                route.layers = segments.map(segment => L.polyline(segment.latlngs, {
                    color: segment.color,
                    weight: 3,
                    opacity: 0.9,
                    lineJoin: 'round',
                }).addTo(this.featureGroup));
            }
            didApply = true;
        }

        if (didApply) {
            this.currentMetric = appliedMetric;
        }
    }

    async fetchRouteMetrics() {
        if (!this.data.routeMetricsUrl) {
            return null;
        }

        try {
            return await fetchJson(this.data.routeMetricsUrl);
        } catch (error) {
            console.error('Failed to load route metrics:', error);
            return null;
        }
    }

    async connectToEChart() {
        if (!this.mapNode.hasAttribute('data-leaflet-echart-connect')) {
            return;
        }

        const eChartNode = document.querySelector('div[data-echarts-options][data-leaflet-echart-connect]');
        if (!eChartNode) {
            return;
        }

        const coordinatesUrl = eChartNode.getAttribute('data-leaflet-echart-connect');
        if (!coordinatesUrl) {
            return;
        }

        try {
            const coordinateMap = await fetchJson(coordinatesUrl);
            const marker = this.addCircleMarker([0, 0], '#F26722', {radius: 6, opacity: 0}).addTo(this.map);
            const chart = echarts.getInstanceByDom(eChartNode);
            const initialZoom = this.map.getZoom();

            chart.on('updateAxisPointer', (event) => {
                if (!event.dataIndex || !event.dataIndex in coordinateMap) {
                    marker.setStyle({opacity: 0, fillOpacity: 0});
                    return;
                }

                const coordinate = coordinateMap[event.dataIndex];
                marker.setLatLng(coordinate);
                marker.setStyle({opacity: 1, fillOpacity: 1});

                const shouldPan = this.map.getZoom() > initialZoom || !this.map.getBounds().contains(coordinate);
                if (shouldPan) {
                    this.map.panTo(coordinate);
                }
            });

            // Each series in the combined profile chart is one metric's row (HR, speed, elevation, ...)
            // rendered as a filled area with no visible line stroke or symbols (see
            // CombinedStreamProfileCharts.php: showSymbol=false, lineStyle.width=0). ECharts does not treat
            // that filled area as an interactive graphic, so `chart.on('mouseover'/'mouseout', ...)` never
            // fires for it (verified directly against a real chart instance — confirmed with both a minimal
            // reproduction and the actual combined-profile options, zero events on hovering the area either
            // way). The reliable signal is the mouse's raw Y position within the chart versus each grid's
            // pixel rect, which `updateAxisPointer` already proves is available on every real mouse move
            // over the chart (it's what drives the existing marker).
            eChartNode.addEventListener('mousemove', (domEvent) => {
                const y = domEvent.clientY - eChartNode.getBoundingClientRect().top;
                const seriesId = this.findSeriesIdAtY(chart, y);
                const routeMetric = seriesId ? CHART_SERIES_TO_ROUTE_METRIC[seriesId] : null;
                this.setRouteMetric(routeMetric || DEFAULT_METRIC);
            });
            eChartNode.addEventListener('mouseleave', () => {
                this.setRouteMetric(DEFAULT_METRIC);
            });
        } catch (error) {
            console.error('Failed to load coordinate map:', error);
        }
    }

    /**
     * Finds the `id` of the series whose grid row contains `y` (a pixel offset relative to the chart's
     * container, i.e. `event.clientY - chartNode.getBoundingClientRect().top`). Returns null when `y` falls
     * between rows (grid gaps, margins) rather than guessing.
     *
     * Relies on `CombinedStreamProfileCharts::build()` giving each series the same index as its grid
     * (`'xAxisIndex' => $index, 'yAxisIndex' => $index`), so grid index N's pixel rect corresponds to
     * `series[N]`.
     */
    findSeriesIdAtY(chart, y) {
        const series = chart.getOption().series || [];
        for (let index = 0; index < series.length; index++) {
            const grid = chart.getModel().getComponent('grid', index);
            if (!grid) {
                continue;
            }
            const rect = grid.coordinateSystem.getRect();
            if (y >= rect.y && y <= rect.y + rect.height) {
                return series[index].id;
            }
        }

        return null;
    }

    addCircleMarker(latLng, fillColor, {radius = 8, opacity = 1} = {}) {
        return L.circleMarker(latLng, {
            radius,
            color: '#303030',
            fillColor,
            fillOpacity: opacity,
            opacity,
        });
    }

}
