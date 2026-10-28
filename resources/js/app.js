import './bootstrap';
import * as maplibregl from 'maplibre-gl';
import { GlobalMapController } from './map/global-map';

// Attach to window for Blade and external template initialization
window.maplibregl = maplibregl;
window.GlobalMapController = GlobalMapController;
