<?php
/**
 * Configuration for QR + Geofence + Device-Bound Login.
 * Edit the values below to match your actual office location and policy.
 */

// City Hall — Sangguniang Panlungsod ng San Jose del Monte, Bulacan.
// (Source: public GPS coordinate listings for the City of San Jose del Monte
// municipal building. Adjust to your exact building's coordinates for accuracy.)
define('SSMS_OFFICE_LAT', 14.81053705);
define('SSMS_OFFICE_LNG', 121.046147894731);

// Allowed radius, in meters, from the office coordinates above.
// GPS accuracy indoors/urban areas is commonly ±20-50m, so keep this
// generous enough to avoid locking out legitimate users standing inside
// the building.
define('SSMS_ALLOWED_RADIUS_METERS', 300);

/**
 * Haversine distance between two lat/lng points, in meters.
 */
function ssms_distance_meters($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function ssms_within_geofence($lat, $lng) {
    if ($lat === null || $lng === null) return false;
    $d = ssms_distance_meters((float)$lat, (float)$lng, SSMS_OFFICE_LAT, SSMS_OFFICE_LNG);
    return $d <= SSMS_ALLOWED_RADIUS_METERS;
}
