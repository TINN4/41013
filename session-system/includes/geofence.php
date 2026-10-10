<?php
/**
 * Geofence configuration for QR login (values come from config/.env).
 * LIMITATION: the coordinates are reported by the user's browser. A technically capable user can
 * spoof them, and indoor GPS can be inaccurate. The geofence is a deterrent / policy control,
 * NOT proof of physical presence. It is never the only factor (badge + device secret [+ MFA]).
 */
function ssms_geofence_enabled(): bool { return strtolower((string)ssms_cfg('SSMS_GEOFENCE_ENABLED', 'true')) !== 'false'; }
function ssms_office_lat(): float { return (float)ssms_cfg('SSMS_OFFICE_LAT', '14.81053705'); }
function ssms_office_lng(): float { return (float)ssms_cfg('SSMS_OFFICE_LNG', '121.046147894731'); }
function ssms_allowed_radius(): int { return (int)ssms_cfg('SSMS_ALLOWED_RADIUS_METERS', '300'); }

function ssms_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
function ssms_within_geofence($lat, $lng): bool {
    if (!ssms_geofence_enabled()) return true;
    if (!is_numeric($lat) || !is_numeric($lng)) return false;
    $lat = (float)$lat; $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
    return ssms_distance_meters($lat, $lng, ssms_office_lat(), ssms_office_lng()) <= ssms_allowed_radius();
}
