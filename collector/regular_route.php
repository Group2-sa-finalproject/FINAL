
<?php
session_start();
require_once "../config/database.php";

// Allow logged-in collectors only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["collector", "collectors"], true)
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Get regular routes assigned to this collector
$stmt = $conn->prepare("
    SELECT
        cr.id AS route_id,
        cr.route_name,
        cr.status AS route_status,
        rs.id AS stop_id,
        rs.stop_order,
        rs.status AS stop_status,
        h.id AS household_id,
        h.house_address,
        h.latitude,
        h.longitude,
        u.fullname
    FROM collection_routes cr
    INNER JOIN route_stops rs
        ON rs.route_id = cr.id
    INNER JOIN households h
        ON h.id = rs.household_id
    LEFT JOIN users u
        ON u.id = h.user_id
    WHERE cr.collector_id = ?
      AND cr.route_type = 'regular'
      AND cr.status IN ('assigned', 'in_progress')
    ORDER BY cr.id DESC, rs.stop_order ASC
");

if (!$stmt) {
    die("Database query failed: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("i", $collector_id);
$stmt->execute();

$result = $stmt->get_result();

$routes = [];

while ($row = $result->fetch_assoc()) {
    $routes[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Regular Collection Route</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Leaflet CSS -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>
        body {
            background: #f4f7f6;
            font-family: Arial, sans-serif;
            padding-top: 85px;
        }

        .navbar {
            background: #198754;
            min-height: 65px;
        }

        .navbar-brand {
            color: white !important;
            font-weight: bold;
        }

        .page-container {
            max-width: 1200px;
            margin: 25px auto;
        }

        .route-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.07);
            margin-bottom: 20px;
        }

        .routes-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .routes-grid .route-card {
            margin-bottom: 0;
            width: 100%;
        }

        .route-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 18px;
        }

        .route-title {
            font-weight: 700;
            color: #183d2b;
            margin-bottom: 5px;
        }

        .route-subtitle {
            color: #6c757d;
            font-size: 13px;
            margin-bottom: 0;
        }

        .route-status {
            display: inline-block;
            background: #e8f5ed;
            color: #198754;
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .stops-heading {
            color: #183d2b;
            font-weight: 700;
        }

        .stops-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .stop-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #f8faf9;
            border: 1px solid #e5eae7;
            border-radius: 10px;
            padding: 16px;
            min-width: 0;
        }

        .stop-number {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #198754;
            color: white;
            border-radius: 50%;
            font-weight: bold;
        }

        .stop-details {
            flex: 1;
            min-width: 0;
        }

        .stop-name {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .stop-address {
            font-size: 13px;
            color: #6c757d;
            overflow-wrap: anywhere;
        }

        .stop-status {
            display: inline-block;
            background: #e8f5ed;
            color: #198754;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .stop-meta {
            font-size: 12px;
            color: #6c757d;
        }

        .btn-green {
            background: #198754;
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: #146c43;
            color: white;
        }

        #map {
            width: 100%;
            height: 500px;
            border-radius: 10px;
            z-index: 1;
        }

        #routeStatus {
            font-size: 14px;
        }

        /* Numbered Leaflet route markers */
        .numbered-route-marker {
            background: #1976d2;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0,0,0,.3);
        }

        /* Collector GPS marker */
        .collector-location-marker {
            background: #0d6efd;
            border: 3px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.4);
        }

        @media (max-width: 768px) {
            .stops-grid {
                grid-template-columns: 1fr;
            }

            .route-header {
                flex-direction: column;
            }

            #map {
                height: 400px;
            }
        }
    </style>
</head>

<body>

<main class="container page-container">

    <h2 class="fw-bold">Regular Collection Route</h2>

    <p class="text-secondary">
        View the regular collection routes assigned to you by the admin.
    </p>

    <?php if (count($routes) > 0): ?>

        <?php
        $groupedRoutes = [];

        foreach ($routes as $route) {
            $groupedRoutes[$route["route_id"]][] = $route;
        }
        ?>

        <div class="routes-grid">

            <?php foreach ($groupedRoutes as $routeId => $stops): ?>

                <div class="route-card">

                    <!-- Route Header -->
                    <div class="route-header">

                        <div>
                            <h5 class="route-title">
                                <?= htmlspecialchars($stops[0]["route_name"]); ?>
                            </h5>

                            <p class="route-subtitle">
                                <?= count($stops); ?> household stop(s) assigned
                            </p>
                        </div>

                        <span class="route-status">
                            <?= htmlspecialchars($stops[0]["route_status"]); ?>
                        </span>

                    </div>

                    <!-- Driving Route Button -->
                    <button
                        type="button"
                        class="btn btn-green mb-4"
                        onclick="showRegularRoute(<?= (int) $routeId ?>)">
                        View Driving Route
                    </button>

                    <!-- Household Stops -->
                    <h6 class="stops-heading mb-3">Household Stops</h6>

                    <div class="stops-grid">

                        <?php foreach ($stops as $stop): ?>

                            <div class="stop-item">

                                <div class="stop-number">
                                    <?= (int) $stop["stop_order"]; ?>
                                </div>

                                <div class="stop-details">

                                    <h6 class="stop-name">
                                        <?= htmlspecialchars($stop["fullname"] ?? "Resident"); ?>
                                    </h6>

                                    <p class="stop-address mb-2">
                                        <strong>Address:</strong>
                                        <?= htmlspecialchars($stop["house_address"] ?? "No address"); ?>
                                    </p>

                                    <span class="stop-status">
                                        <?= htmlspecialchars($stop["stop_status"]); ?>
                                    </span>

                                    <div class="stop-meta mt-2">
                                        Household ID:
                                        <?= (int) $stop["household_id"]; ?>
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="route-card">
            <p class="text-muted mb-0">
                No regular collection routes have been assigned to you yet.
            </p>
        </div>

    <?php endif; ?>


    <!-- ROUTE MAP -->
    <div class="route-card">

        <h5 class="fw-bold mb-3">Route Map</h5>

        <div class="d-flex flex-wrap gap-2 mb-3">

            <button
                type="button"
                class="btn btn-green"
                onclick="showAllStops()">
                Show All Stops
            </button>

            <button
                type="button"
                class="btn btn-primary"
                onclick="showSelectedRouteStops()">
                Remove Route
            </button>

        </div>

        <div id="map"></div>

        <p id="routeStatus" class="text-secondary mt-3 mb-0">
            Loading route map...
        </p>

    </div>

</main>

<!-- Leaflet JavaScript -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

// ==========================================
// ROUTE DATA FROM PHP
// ==========================================

const regularStops = <?= json_encode(array_map(function ($stop) {
    return [
        "route_id" => (int) $stop["route_id"],
        "household_id" => (int) $stop["household_id"],
        "stop_order" => (int) $stop["stop_order"],
        "name" => $stop["fullname"] ?? "Resident",
        "address" => $stop["house_address"] ?? "No address",
        "latitude" => is_numeric($stop["latitude"]) ? (float) $stop["latitude"] : null,
        "longitude" => is_numeric($stop["longitude"]) ? (float) $stop["longitude"] : null
    ];
}, $routes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;


// ==========================================
// MAP VARIABLES
// ==========================================

let map;

let currentPosition = null;
let currentLocationMarker = null;
let gpsWatchId = null;

let householdMarkers = [];
let routeMarkers = [];

let routePolyline = null;

let selectedRouteId = null;


// ==========================================
// INITIALIZE LEAFLET MAP
// ==========================================

function initMap() {

    map = L.map("map").setView([15.4802, 120.5979], 12);

    // OpenStreetMap tiles
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    createHouseholdMarkers();

    // Initially show all household stops
    showAllStops();

    // Start collector GPS tracking
    startGPS();

}


// ==========================================
// CHECK VALID GPS COORDINATES
// ==========================================

function hasValidCoordinates(stop) {

    return stop.latitude !== null &&
           stop.longitude !== null &&
           Number.isFinite(Number(stop.latitude)) &&
           Number.isFinite(Number(stop.longitude)) &&
           Math.abs(Number(stop.latitude)) <= 90 &&
           Math.abs(Number(stop.longitude)) <= 180;

}


// ==========================================
// ESCAPE HTML FOR POPUPS
// ==========================================

function escapeHTML(value) {

    return String(value ?? "").replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[char]);

}


// ==========================================
// HOUSEHOLD POPUP CONTENT
// ==========================================

function getStopInfo(stop, isRouteStop = false) {

    return `
        <div style="min-width:190px;max-width:260px;padding:5px;">

            <strong style="font-size:14px;">
                ${escapeHTML(stop.name)}
            </strong>

            <hr style="margin:7px 0;">

            <div style="font-size:12px;margin-bottom:5px;">
                <strong>Address:</strong>
                ${escapeHTML(stop.address)}
            </div>

            <div style="font-size:12px;margin-bottom:5px;">
                <strong>Household ID:</strong>
                #${escapeHTML(stop.household_id)}
            </div>

            <div style="font-size:12px;">
                <strong>Stop Order:</strong>
                ${escapeHTML(stop.stop_order)}
            </div>

            ${isRouteStop ? `
                <div style="color:#1976d2;font-size:11px;font-weight:bold;margin-top:6px;">
                    Selected Route Stop
                </div>
            ` : ""}

        </div>
    `;

}


// ==========================================
// CREATE GREEN HOUSEHOLD MARKERS
// ==========================================

function createHouseholdMarkers() {

    householdMarkers.forEach(marker => map.removeLayer(marker));

    householdMarkers = [];

    regularStops.forEach(stop => {

        if (!hasValidCoordinates(stop)) {
            return;
        }

        const marker = L.circleMarker(
            [Number(stop.latitude), Number(stop.longitude)],
            {
                radius: 8,
                fillColor: "#198754",
                color: "#ffffff",
                weight: 2,
                opacity: 1,
                fillOpacity: 1
            }
        );

        marker.bindPopup(getStopInfo(stop, false));

        householdMarkers.push(marker);

    });

}


// ==========================================
// CREATE BLUE NUMBERED ROUTE MARKERS
// ==========================================

function createRouteMarkers(stops) {

    routeMarkers.forEach(marker => map.removeLayer(marker));

    routeMarkers = [];

    stops.forEach(stop => {

        if (!hasValidCoordinates(stop)) {
            return;
        }

        const numberIcon = L.divIcon({
            className: "",
            html: `<div class="numbered-route-marker">${escapeHTML(stop.stop_order)}</div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });

        const marker = L.marker(
            [Number(stop.latitude), Number(stop.longitude)],
            {
                icon: numberIcon
            }
        );

        marker.bindPopup(getStopInfo(stop, true));

        routeMarkers.push(marker);

    });

}


// ==========================================
// COLLECTOR GPS TRACKING
// ==========================================

function startGPS() {

    const message = document.getElementById("routeStatus");

    if (!navigator.geolocation) {

        message.textContent =
            "Your browser does not support GPS location.";

        return;
    }

    message.textContent =
        "Waiting for your current GPS location...";

    gpsWatchId = navigator.geolocation.watchPosition(

        function(position) {

            currentPosition = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };

            const gpsIcon = L.divIcon({
                className: "",
                html: '<div class="collector-location-marker"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            });

            if (!currentLocationMarker) {

                currentLocationMarker = L.marker(
                    [currentPosition.lat, currentPosition.lng],
                    {
                        icon: gpsIcon,
                        zIndexOffset: 1000
                    }
                ).addTo(map);

                currentLocationMarker.bindPopup(
                    "<strong>Your Current Location</strong>"
                );

            } else {

                currentLocationMarker.setLatLng([
                    currentPosition.lat,
                    currentPosition.lng
                ]);

            }

            if (!selectedRouteId) {
                message.textContent =
                    "GPS ready. Select a route to view driving directions.";
            }

        },

        function(error) {

            let errorMessage = "Unable to get GPS location.";

            if (error.code === 1) {
                errorMessage = "Location permission was denied. Please allow location access in your browser.";
            } else if (error.code === 2) {
                errorMessage = "Your current location is unavailable.";
            } else if (error.code === 3) {
                errorMessage = "GPS request timed out. Please try again.";
            }

            if (!currentPosition) {
                message.textContent = errorMessage;
            }

        },

        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 5000
        }

    );

}


// ==========================================
// CLEAR PREVIOUS DRIVING ROUTE
// ==========================================

function clearRoutePolyline() {

    if (routePolyline) {

        map.removeLayer(routePolyline);

        routePolyline = null;

    }

}


// ==========================================
// FIT MAP TO MARKERS
// ==========================================

function fitToMarkers(markers) {

    const layers = [...markers];

    if (currentLocationMarker) {
        layers.push(currentLocationMarker);
    }

    if (layers.length === 0) {
        return;
    }

    const group = L.featureGroup(layers);

    if (layers.length === 1) {

        map.setView(group.getBounds().getCenter(), 16);

    } else {

        map.fitBounds(group.getBounds(), {
            padding: [40, 40],
            maxZoom: 16
        });

    }

}


// ==========================================
// SHOW ALL HOUSEHOLD STOPS
// ==========================================

function showAllStops() {

    selectedRouteId = null;

    clearRoutePolyline();

    routeMarkers.forEach(marker => map.removeLayer(marker));

    householdMarkers.forEach(marker => {

        if (!map.hasLayer(marker)) {
            marker.addTo(map);
        }

    });

    fitToMarkers(householdMarkers);

    const validCount = householdMarkers.length;

    document.getElementById("routeStatus").textContent =
        `${validCount} household stop(s) displayed using green markers.`;

}


// ==========================================
// SHOW SELECTED ROUTE STOPS
// ==========================================

function showSelectedRouteStops(routeId = selectedRouteId) {

    if (!routeId) {

        document.getElementById("routeStatus").textContent =
            "Please select a route first using its View Driving Route button.";

        return;
    }

    selectedRouteId = Number(routeId);

    const stops = regularStops
        .filter(stop => stop.route_id === selectedRouteId)
        .sort((a, b) => a.stop_order - b.stop_order)
        .filter(hasValidCoordinates);

    if (!stops.length) {

        document.getElementById("routeStatus").textContent =
            "No household stops with valid GPS coordinates were found for this route.";

        return;
    }

    clearRoutePolyline();

    householdMarkers.forEach(marker => map.removeLayer(marker));

    createRouteMarkers(stops);

    routeMarkers.forEach(marker => marker.addTo(map));

    fitToMarkers(routeMarkers);

    document.getElementById("routeStatus").textContent =
        `Route ${selectedRouteId}: ${routeMarkers.length} numbered household stop(s) displayed.`;

}


// ==========================================
// CALCULATE DRIVING ROUTE USING OSRM
// ==========================================

async function showRegularRoute(routeId) {

    const message = document.getElementById("routeStatus");

    const selectedId = Number(routeId);

    const stops = regularStops
        .filter(stop => stop.route_id === selectedId)
        .sort((a, b) => a.stop_order - b.stop_order)
        .filter(hasValidCoordinates);

    if (!stops.length) {

        message.textContent =
            "No household stops with valid GPS coordinates were found for this route.";

        return;
    }

    // Display selected route markers
    showSelectedRouteStops(selectedId);

    // Check collector GPS
    if (!currentPosition) {

        message.textContent =
            "Route stops are displayed, but your current GPS location is unavailable. Allow location access to calculate driving directions.";

        return;
    }

    if (stops.length > 90) {

        message.textContent =
            "This route has too many stops for this public routing service. Please divide the route into smaller groups.";

        return;
    }

    message.textContent =
        "Calculating driving route. Please wait...";

    clearRoutePolyline();

    try {

        // Start with collector's current location
        const coordinates = [
            `${currentPosition.lng},${currentPosition.lat}`
        ];

        // Add household stops in their assigned order
        stops.forEach(stop => {

            coordinates.push(
                `${Number(stop.longitude)},${Number(stop.latitude)}`
            );

        });

        // OSRM expects longitude first, then latitude
        const coordinateString = coordinates.join(";");

        const osrmURL =
            "https://router.project-osrm.org/route/v1/driving/" +
            coordinateString +
            "?overview=full&geometries=geojson&steps=false";

        const response = await fetch(osrmURL);

        if (!response.ok) {

            throw new Error(
                "The routing service could not be reached. Please try again."
            );

        }

        const data = await response.json();

        if (data.code !== "Ok" || !data.routes || !data.routes.length) {

            throw new Error(
                data.message || "No driving route was found."
            );

        }

        const route = data.routes[0];

        const routeCoordinates = route.geometry.coordinates.map(point => [
            point[1],
            point[0]
        ]);

        // Draw driving route on Leaflet map
        routePolyline = L.polyline(
            routeCoordinates,
            {
                color: "#1976d2",
                weight: 6,
                opacity: 0.85
            }
        ).addTo(map);

        // Keep numbered household markers visible
        routeMarkers.forEach(marker => {

            if (!map.hasLayer(marker)) {
                marker.addTo(map);
            }

        });

        // Keep collector GPS marker visible
        if (currentLocationMarker && !map.hasLayer(currentLocationMarker)) {
            currentLocationMarker.addTo(map);
        }

        // Fit map to driving route
        const bounds = routePolyline.getBounds();

        if (bounds.isValid()) {

            map.fitBounds(bounds, {
                padding: [40, 40],
                maxZoom: 16
            });

        }

        // Convert distance and duration
        const distanceKm = (route.distance / 1000).toFixed(2);

        const durationMinutes = Math.round(route.duration / 60);

        message.textContent =
            `Driving route displayed successfully. ` +
            `Distance: ${distanceKm} km | ` +
            `Estimated driving time: ${durationMinutes} minute(s). ` +
            `${stops.length} household stop(s) included.`;

    } catch (error) {

        console.error("OSRM routing error:", error);

        message.textContent =
            "Unable to calculate driving route: " + error.message;

    }

}


// ==========================================
// START MAP
// ==========================================

document.addEventListener("DOMContentLoaded", function() {

    initMap();

});

</script>

</body>
</html>
