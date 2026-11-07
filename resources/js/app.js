import './bootstrap';

// Check if current DOM requires MapLibre spatial engine
const hasMapCanvas = () => {
    return !!(
        document.getElementById('global-map-canvas') ||
        document.getElementById('candidate-detail-map') ||
        document.querySelector('[data-map-canvas]')
    );
};

// Global async loader for MapLibre and GlobalMapController
window.loadMapLibreEngine = async () => {
    if (window.GlobalMapController && window.maplibregl) {
        return { maplibregl: window.maplibregl, GlobalMapController: window.GlobalMapController };
    }
    const mapModule = await import('./map/global-map');
    window.GlobalMapController = mapModule.GlobalMapController;
    window.maplibregl = mapModule.maplibregl || window.maplibregl;
    return mapModule;
};

// Automatically initiate lazy-load if a map canvas exists on the current page
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (hasMapCanvas()) {
            window.loadMapLibreEngine();
        }
    });
} else {
    if (hasMapCanvas()) {
        window.loadMapLibreEngine();
    }
}
