<?php
// config/map_assets.php
// The tags that load Leaflet, the open-source map library every map in this app is drawn with
// (map tiles and data: OpenStreetMap). Kept in one place so the version, or a copy hosted on
// this server, only has to be changed here.

/** <link> and <script> for Leaflet. Print it inside <head>. */
function leaflet_tags(): string
{
    return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"'
        . ' integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">' . "\n"
        . '    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"'
        . ' integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>' . "\n";
}
