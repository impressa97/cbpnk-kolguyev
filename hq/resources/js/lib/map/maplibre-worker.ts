import { setWorkerUrl } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

let installed = false;

/** MapLibre 6 worker is a real ESM file — Vite must emit a self-contained URL. */
export function installMapLibreWorker(): void {
    if (installed || typeof document === 'undefined') return;
    installed = true;
    setWorkerUrl(workerUrl);
}
