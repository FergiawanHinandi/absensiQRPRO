import * as L from 'leaflet';

declare module 'leaflet' {
  interface HeatLayerOptions {
    minOpacity?: number;
    maxZoom?: number;
    max?: number;
    radius?: number;
    blur?: number;
    gradient?: { [key: number]: string };
  }

  interface HeatLayer extends L.Layer {
    setLatLngs(latlngs: L.LatLngExpression[] | [number, number, number][]): this;
    addLatLng(latlng: L.LatLngExpression | [number, number, number]): this;
    setOptions(options: HeatLayerOptions): this;
    redraw(): this;
  }

  function heatLayer(
    latlngs: L.LatLngExpression[] | [number, number, number][],
    options?: HeatLayerOptions
  ): HeatLayer;
}
