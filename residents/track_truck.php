
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

// RESIDENT LOGIN CHECK
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "residents"
) {
    header("Location: ../index.php");
    exit;
}

$conn->set_charset("utf8mb4");

$resident_id = (int) $_SESSION["user_id"];

// GET RESIDENT INFORMATION
$stmt = $conn->prepare("
    SELECT id, fullname
    FROM users
    WHERE id = ?
    AND role = 'residents'
    LIMIT 1
");

if (!$stmt) {
    die("Resident query failed: " . $conn->error);
}

$stmt->bind_param("i", $resident_id);
$stmt->execute();

$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resident) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// ESCAPE OUTPUT
function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Track Garbage Truck | EcoTrack</title>

<link rel="stylesheet"
href="../assets/vendor/bootstrap/css/bootstrap.min.css">

<link rel="stylesheet"
href="../assets/css/style.css">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- LEAFLET MAP CSS -->
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
/>

<style>

/* GENERAL */

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f7f5;
    font-family: Arial, Helvetica, sans-serif;
    color: #263b30;
}

a {
    text-decoration: none;
}

/* SIDEBAR */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: #123d2b;
    color: #fff;
    padding: 25px 15px;
    overflow-y: auto;
    z-index: 1000;
}

.sidebar-logo {
    text-align: center;
    margin-bottom: 35px;
}

.sidebar-logo h3 {
    font-size: 23px;
    font-weight: 800;
    color: #fff;
    margin: 0;
}

.sidebar-logo i {
    color: #9be7ad;
    margin-right: 7px;
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin-bottom: 8px;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 13px 15px;
    color: #d8e8df;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: .2s ease;
}

.sidebar-menu a i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,.12);
    color: #fff;
}

.sidebar-menu a.active {
    background: #fff;
    color: #123d2b;
}

.sidebar-menu .logout-link {
    margin-top: 25px;
    color: #ffcccc;
}

.sidebar-menu .logout-link:hover {
    background: rgba(255,255,255,.12);
    color: #fff;
}

/* MAIN CONTENT */

.main-content {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    background: #fff;
    padding: 18px 30px;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    border-bottom: 1px solid #e5eae6;
    min-height: 75px;
}

.resident-profile {
    display: flex;
    align-items: center;
    gap: 12px;
}

.resident-profile .profile-icon {
    width: 40px;
    height: 40px;
    background: #e3f3e8;
    color: #17643b;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 17px;
}

.resident-profile .profile-name {
    font-size: 14px;
    font-weight: 700;
    color: #183d2b;
}

.resident-profile .profile-role {
    display: block;
    font-size: 11px;
    color: #819087;
    font-weight: 500;
}

.content {
    padding: 30px;
    min-width: 0;
}

/* PAGE HEADER */

.page-heading {
    margin-bottom: 25px;
}

.page-heading h2 {
    font-size: 26px;
    color: #183d2b;
    font-weight: 800;
    margin-bottom: 7px;
}

.page-heading p {
    color: #78877e;
    font-size: 14px;
    margin: 0;
}

/* SECTION TITLE */

.section-title {
    color: #45564d;
    font-size: 17px;
    font-weight: 700;
    margin: 30px 0 18px;
}

/* TRACKING CARDS */

.tracking-card {
    background: white;
    border-radius: 12px;
    padding: 23px;
    border: 1px solid #e8eeea;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    margin-bottom: 23px;
}

.tracking-card h5 {
    color: #2d4036;
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 15px;
}

.dashboard-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e5f5e9;
    color: #23834b;
    margin-bottom: 17px;
    font-size: 21px;
}

/* COLLECTOR INFORMATION */

.collector-name {
    font-size: 16px;
    font-weight: 700;
    color: #263b32;
    margin-bottom: 8px;
}

.collector-status {
    font-size: 13px;
    margin-bottom: 0;
}

.status-online {
    color: #198754;
}

.status-offline {
    color: #dc3545;
}

.status-waiting {
    color: #77847c;
}

/* PICKUP INFORMATION */

.pickup-info {
    font-size: 13px;
    color: #77847c;
    margin-top: 7px;
}

.pickup-info i {
    color: #198754;
    width: 18px;
}

/* DISTANCE CARDS */

.metric-card {
    background: #fff;
    border: 1px solid #e8eeea;
    border-radius: 12px;
    padding: 18px;
    height: 100%;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.metric-icon {
    width: 38px;
    height: 38px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e5f5e9;
    color: #23834b;
    margin-bottom: 12px;
}

.metric-label {
    font-size: 12px;
    color: #849087;
    margin-bottom: 5px;
}

.metric-value {
    font-size: 20px;
    font-weight: 800;
    color: #183d2b;
    overflow-wrap: anywhere;
}

.metric-description {
    font-size: 11px;
    color: #929b96;
    margin-top: 5px;
}

/* MAP */

#map {
    width: 100%;
    height: 500px;
    border-radius: 10px;
    overflow: hidden;
    background: #e9efeb;
    z-index: 1;
}

.map-container {
    width: 100%;
    overflow: hidden;
    border-radius: 10px;
}

.tracking-description {
    color: #77847c;
    font-size: 13px;
    line-height: 1.6;
}

/* MAP LEGEND */

.map-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 17px;
    font-size: 12px;
    color: #65746b;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 7px;
}

.legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.house-dot {
    background: #198754;
}

.collector-dot {
    background: #0d6efd;
}

.route-line {
    width: 19px;
    height: 4px;
    background: #198754;
    border-radius: 5px;
}

/* MAP MARKERS */

.custom-map-icon {
    background: transparent;
    border: none;
}

.custom-map-icon div {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
    font-size: 16px;
}

.house-marker div {
    background: #198754;
}

.collector-marker div {
    background: #0d6efd;
}

/* TRACKING MESSAGE */

.tracking-message {
    padding: 12px 15px;
    border-radius: 8px;
    background: #f1f6f2;
    color: #607168;
    font-size: 13px;
    margin-top: 15px;
    line-height: 1.5;
}

.tracking-message i {
    margin-right: 5px;
}

/* FOOTER */

.dashboard-footer {
    margin-top: 35px;
    padding: 20px 0;
    border-top: 1px solid #e2e9e5;
    color: #929b96;
    font-size: 12px;
    text-align: center;
}

/* RESPONSIVE */

@media (max-width: 1000px) {
    .content {
        padding: 20px;
    }
}

@media (max-width: 700px) {

    .sidebar {
        width: 70px;
        padding: 20px 8px;
    }

    .sidebar-logo h3 {
        font-size: 0;
    }

    .sidebar-logo i {
        font-size: 25px;
        margin: 0;
    }

    .sidebar-menu a {
        justify-content: center;
        padding: 13px 8px;
    }

    .sidebar-menu a span {
        display: none;
    }

    .sidebar-menu a i {
        font-size: 18px;
        width: auto;
    }

    .main-content {
        margin-left: 70px;
    }

    .topbar {
        padding: 15px;
    }

    .content {
        padding: 18px 13px;
    }

    .page-heading h2 {
        font-size: 21px;
    }

    .section-title {
        font-size: 16px;
    }

    .tracking-card {
        padding: 18px;
    }

    #map {
        height: 400px;
    }

    .metric-value {
        font-size: 18px;
    }
}

</style>

</head>

<body>

<!-- RESIDENT SIDEBAR -->
<aside class="sidebar">

    <div class="sidebar-logo">
        <h3>
            <i class="fas fa-recycle"></i>
            EcoTrack
        </h3>
    </div>

    <ul class="sidebar-menu">

        <li>
            <a href="dashboard.php">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="eco_points.php">
                <i class="fas fa-coins"></i>
                <span>Eco Points</span>
            </a>
        </li>

        <li>
            <a href="collection_schedule.php">
                <i class="fas fa-calendar-alt"></i>
                <span>Collection Schedule</span>
            </a>
        </li>

        <li>
            <a href="track_truck.php" class="active">
                <i class="fas fa-truck"></i>
                <span>Track Garbage Truck</span>
            </a>
        </li>

        <li>
            <a href="subscription.php">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Subscription</span>
            </a>
        </li>

        <li>
            <a href="register_household.php">
                <i class="fas fa-house-user"></i>
                <span>Register Household</span>
            </a>
        </li>

        <li>
            <a href="feedback.php">
                <i class="fas fa-comment-dots"></i>
                <span>Feedback</span>
            </a>
        </li>

        <li>
            <a href="qrcode.php">
                <i class="fas fa-qrcode"></i>
                <span>Resident QR Code</span>
            </a>
        </li>

        <li>
            <a href="../logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </li>

    </ul>

</aside>

<!-- MAIN CONTENT -->
<div class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">

        <div class="resident-profile">

            <div class="profile-icon">
                <i class="fas fa-user"></i>
            </div>

            <div>
                <div class="profile-name">
                    <?= e($resident["fullname"]); ?>
                </div>

                <span class="profile-role">
                    Resident
                </span>
            </div>

        </div>

    </div>

    <!-- PAGE CONTENT -->
    <main class="content">

        <div class="page-heading">

            <h2>
                <i class="fas fa-truck me-2"></i>
                Track Garbage Truck
            </h2>

            <p>
                Monitor the collector assigned to your special pickup request.
            </p>

        </div>

        <!-- COLLECTOR INFORMATION -->
        <h6 class="section-title">
            <i class="fas fa-user me-2"></i>
            Assigned Collector Information
        </h6>

        <div class="tracking-card">

            <div class="row align-items-center">

                <div class="col-md-8">

                    <div class="d-flex align-items-start gap-3">

                        <div class="dashboard-icon">
                            <i class="fas fa-truck"></i>
                        </div>

                        <div>

                            <h5
                                class="collector-name"
                                id="collectorName">

                                Searching for assigned collector...

                            </h5>

                            <p
                                id="collectorStatus"
                                class="collector-status status-waiting">

                                <i class="fas fa-clock me-1"></i>
                                Waiting for location...

                            </p>

                            <p class="pickup-info" id="pickupDate">
                                <i class="fas fa-calendar"></i>
                                Pickup date: Waiting...
                            </p>

                            <p class="pickup-info" id="pickupAddress">
                                <i class="fas fa-house"></i>
                                Pickup address: Waiting...
                            </p>

                            <p class="pickup-info" id="lastUpdated">
                                <i class="fas fa-clock"></i>
                                Last GPS update: Waiting...
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- DISTANCE AND ETA -->
        <h6 class="section-title">
            <i class="fas fa-route me-2"></i>
            Distance and Estimated Travel Time
        </h6>

        <div class="row g-3 mb-4">

            <div class="col-md-4">

                <div class="metric-card">

                    <div class="metric-icon">
                        <i class="fas fa-road"></i>
                    </div>

                    <div class="metric-label">
                        Driving Distance
                    </div>

                    <div class="metric-value" id="drivingDistance">
                        --
                    </div>

                    <div class="metric-description">
                        Road distance to your house
                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="metric-card">

                    <div class="metric-icon">
                        <i class="fas fa-clock"></i>
                    </div>

                    <div class="metric-label">
                        Estimated Travel Time
                    </div>

                    <div class="metric-value" id="estimatedTime">
                        --
                    </div>

                    <div class="metric-description">
                        Estimated route duration
                    </div>

                </div>

            </div>

            <div class="col-md-4">

                <div class="metric-card">

                    <div class="metric-icon">
                        <i class="fas fa-location-arrow"></i>
                    </div>

                    <div class="metric-label">
                        GPS Distance
                    </div>

                    <div class="metric-value" id="gpsDistance">
                        --
                    </div>

                    <div class="metric-description">
                        Straight-line distance
                    </div>

                </div>

            </div>

        </div>

        <!-- LIVE MAP -->
        <h6 class="section-title">

            <i class="fas fa-map-marker-alt me-2"></i>
            Live Collector and House Location

        </h6>

        <div class="tracking-card">

            <h5>
                <i class="fas fa-map me-2 text-success"></i>
                Special Pickup Tracking Map
            </h5>

            <p class="tracking-description">
                The map shows your registered household, your assigned
                collector, and the estimated driving route between them.
                Collector location automatically refreshes every 5 seconds.
            </p>

            <div class="map-container">
                <div id="map"></div>
            </div>

            <div class="map-legend">

                <div class="legend-item">
                    <span class="legend-dot house-dot"></span>
                    Your House
                </div>

                <div class="legend-item">
                    <span class="legend-dot collector-dot"></span>
                    Garbage Collector
                </div>

                <div class="legend-item">
                    <span class="route-line"></span>
                    Driving Route
                </div>

            </div>

            <div class="tracking-message" id="trackingMessage">
                <i class="fas fa-info-circle"></i>
                Waiting for tracking information...
            </div>

        </div>

        <!-- FOOTER -->
        <div class="dashboard-footer">

            EcoTrack Waste Collection Management System

            <br>

            &copy; <?= date("Y"); ?>

            <br>

            Resident Tracking Page

        </div>

    </main>

</div>

<!-- BOOTSTRAP -->
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- LEAFLET -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

// ==========================================
// MAP VARIABLES
// ==========================================

let map;

let houseMarker = null;
let collectorMarker = null;
let routeLine = null;

let housePosition = null;
let collectorPosition = null;

let firstMapView = false;
let isLoading = false;

let previousCollectorPosition = null;
let lastRouteRequest = 0;

let trackingInterval = null;

const defaultLocation = [15.4802, 120.5979];


// ==========================================
// HTML ELEMENTS
// ==========================================

const collectorName =
    document.getElementById("collectorName");

const collectorStatus =
    document.getElementById("collectorStatus");

const pickupDate =
    document.getElementById("pickupDate");

const pickupAddress =
    document.getElementById("pickupAddress");

const lastUpdated =
    document.getElementById("lastUpdated");

const drivingDistance =
    document.getElementById("drivingDistance");

const estimatedTime =
    document.getElementById("estimatedTime");

const gpsDistance =
    document.getElementById("gpsDistance");

const trackingMessage =
    document.getElementById("trackingMessage");


// ==========================================
// CUSTOM MAP ICONS
// ==========================================

const houseIcon = L.divIcon({
    className: "custom-map-icon",
    html: '<div><i class="fas fa-house"></i></div>',
    iconSize: [38, 38],
    iconAnchor: [19, 19],
    popupAnchor: [0, -20]
});

const collectorIcon = L.divIcon({
    className: "custom-map-icon",
    html: '<div><i class="fas fa-truck"></i></div>',
    iconSize: [38, 38],
    iconAnchor: [19, 19],
    popupAnchor: [0, -20]
});


// ==========================================
// VALIDATE COORDINATES
// ==========================================

function validCoordinates(lat, lng) {

    return (
        Number.isFinite(lat) &&
        Number.isFinite(lng) &&
        Math.abs(lat) <= 90 &&
        Math.abs(lng) <= 180
    );

}


// ==========================================
// HAVERSINE DISTANCE
// STRAIGHT-LINE DISTANCE IN KM
// ==========================================

function calculateGPSDistance(pointA, pointB) {

    const earthRadius = 6371;

    const lat1 = pointA[0] * Math.PI / 180;
    const lat2 = pointB[0] * Math.PI / 180;

    const differenceLat =
        (pointB[0] - pointA[0]) * Math.PI / 180;

    const differenceLng =
        (pointB[1] - pointA[1]) * Math.PI / 180;

    const a =
        Math.sin(differenceLat / 2) *
        Math.sin(differenceLat / 2) +
        Math.cos(lat1) *
        Math.cos(lat2) *
        Math.sin(differenceLng / 2) *
        Math.sin(differenceLng / 2);

    const c =
        2 * Math.atan2(
            Math.sqrt(a),
            Math.sqrt(1 - a)
        );

    return earthRadius * c;

}


// ==========================================
// FORMAT DISTANCE
// ==========================================

function formatDistance(km) {

    if (!Number.isFinite(km)) {
        return "--";
    }

    if (km < 1) {
        return Math.round(km * 1000) + " m";
    }

    return km.toFixed(2) + " km";

}


// ==========================================
// FORMAT TRAVEL TIME
// ==========================================

function formatDuration(seconds) {

    if (!Number.isFinite(seconds)) {
        return "--";
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 1) {
        return "Less than 1 min";
    }

    if (minutes < 60) {
        return minutes + " min";
    }

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (remainingMinutes === 0) {
        return hours + " hr";
    }

    return hours + " hr " + remainingMinutes + " min";

}


// ==========================================
// UPDATE HOUSE MARKER
// ==========================================

function updateHouseMarker(position, address) {

    housePosition = position;

    if (!houseMarker) {

        houseMarker = L.marker(position, {
            icon: houseIcon,
            title: "Your Registered House"
        }).addTo(map);

    } else {

        houseMarker.setLatLng(position);

    }

    houseMarker.bindPopup(
        "<strong>Your Registered House</strong><br>" +
        (address || "Registered household location")
    );

}


// ==========================================
// UPDATE COLLECTOR MARKER
// ==========================================

function updateCollectorMarker(position, fullname) {

    collectorPosition = position;

    if (!collectorMarker) {

        collectorMarker = L.marker(position, {
            icon: collectorIcon,
            title: "Garbage Collector"
        }).addTo(map);

    } else {

        collectorMarker.setLatLng(position);

    }

    collectorMarker.bindPopup(
        "<strong>Garbage Collector</strong><br>" +
        fullname
    );

}


// ==========================================
// UPDATE GPS DISTANCE
// ==========================================

function updateGPSDistance() {

    if (!housePosition || !collectorPosition) {
        gpsDistance.textContent = "--";
        return;
    }

    const distance = calculateGPSDistance(
        collectorPosition,
        housePosition
    );

    gpsDistance.textContent =
        formatDistance(distance);

}


// ==========================================
// FETCH OSRM DRIVING ROUTE
// ==========================================

async function updateDrivingRoute(force = false) {

    if (!housePosition || !collectorPosition) {
        return;
    }

    const now = Date.now();

    // Do not request a route too frequently
    if (!force && now - lastRouteRequest < 20000) {
        return;
    }

    lastRouteRequest = now;

    const start = collectorPosition;
    const destination = housePosition;

    const url =
        "https://router.project-osrm.org/route/v1/driving/" +
        start[1] + "," + start[0] + ";" +
        destination[1] + "," + destination[0] +
        "?overview=full&geometries=geojson&steps=false";

    try {

        const response = await fetch(url);

        if (!response.ok) {
            throw new Error("Route service unavailable.");
        }

        const data = await response.json();

        if (
            data.code !== "Ok" ||
            !data.routes ||
            !data.routes.length
        ) {
            throw new Error("No driving route found.");
        }

        const route = data.routes[0];

        // ROAD DISTANCE
        drivingDistance.textContent =
            formatDistance(route.distance / 1000);

        // ESTIMATED TRAVEL TIME
        estimatedTime.textContent =
            formatDuration(route.duration);

        // DRAW ROAD ROUTE
        const coordinates =
            route.geometry.coordinates.map(function(point) {

                return [
                    point[1],
                    point[0]
                ];

            });

        if (routeLine) {

            routeLine.setLatLngs(coordinates);

        } else {

            routeLine = L.polyline(coordinates, {
                color: "#198754",
                weight: 5,
                opacity: 0.85,
                lineJoin: "round"
            }).addTo(map);

        }

        trackingMessage.innerHTML =
            '<i class="fas fa-check-circle text-success"></i>' +
            ' Driving route successfully updated.';

    } catch (error) {

        console.error("OSRM route error:", error);

        drivingDistance.textContent = "Unavailable";
        estimatedTime.textContent = "Unavailable";

        trackingMessage.innerHTML =
            '<i class="fas fa-exclamation-circle"></i>' +
            ' Driving route is temporarily unavailable. ' +
            'GPS distance and map markers are still available.';

    }

}


// ==========================================
// CHECK IF COLLECTOR HAS MOVED
// ==========================================

function collectorMovedEnough(newPosition) {

    if (!previousCollectorPosition) {
        return true;
    }

    const movement = calculateGPSDistance(
        previousCollectorPosition,
        newPosition
    );

    // Recalculate route after at least 30 meters of movement
    return movement >= 0.03;

}


// ==========================================
// FORMAT GPS UPDATE TIME
// ==========================================

function formatUpdatedTime(value) {

    if (!value) {
        return "Unavailable";
    }

    // Convert MySQL datetime into a browser-readable local date
    const date = new Date(
        String(value).replace(" ", "T")
    );

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();

}


// ==========================================
// LOAD COLLECTOR AND HOUSE INFORMATION
// ==========================================

async function loadCollectorLocation() {

    if (isLoading || !map) {
        return;
    }

    isLoading = true;

    try {

        const response = await fetch(
            "get_assigned_collector_location.php",
            {
                method: "GET",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                }
            }
        );

        const responseText = await response.text();

        let collector;

        try {

            collector = JSON.parse(responseText);

        } catch (error) {

            console.error(
                "Invalid API response:",
                responseText
            );

            throw new Error(
                "Invalid tracking API response. Check the PHP API for errors."
            );

        }

        if (!response.ok) {

            throw new Error(
                collector.message || "Unable to retrieve tracking information."
            );

        }

        // NO ASSIGNED SPECIAL PICKUP
        if (!collector.success) {

            collectorName.textContent =
                "No assigned collector found";

            collectorStatus.textContent =
                collector.message ||
                "No approved special pickup was found.";

            collectorStatus.className =
                "collector-status status-waiting";

            trackingMessage.textContent =
                collector.message ||
                "Waiting for an approved special pickup request.";

            pickupDate.textContent =
                "Pickup date: Not available";

            pickupAddress.textContent =
                "Pickup address: Not available";

            lastUpdated.textContent =
                "Last GPS update: Not available";

            drivingDistance.textContent = "--";
            estimatedTime.textContent = "--";
            gpsDistance.textContent = "--";

            return;

        }

        // ==================================
        // HOUSE INFORMATION
        // ==================================

        const houseLat = Number(collector.house_latitude);
        const houseLng = Number(collector.house_longitude);

        if (!validCoordinates(houseLat, houseLng)) {
            throw new Error(
                "Your household GPS coordinates are invalid."
            );
        }

        const newHousePosition = [
            houseLat,
            houseLng
        ];

        updateHouseMarker(
            newHousePosition,
            collector.house_address
        );

        pickupAddress.textContent =
            "Pickup address: " +
            (collector.collection_address ||
             collector.house_address ||
             "Registered household");

        // ==================================
        // PICKUP DATE AND TIME
        // ==================================

        let dateText = "Not available";

        if (collector.collection_date) {
            dateText = collector.collection_date;

            if (collector.requested_time) {
                dateText += " at " + collector.requested_time;
            }
        }

        pickupDate.textContent =
            "Pickup date: " + dateText;

        // ==================================
        // COLLECTOR INFORMATION
        // ==================================

        collectorName.textContent =
            "Collector: " + collector.fullname;

        const locationAvailable =
            collector.location_available === true ||
            Number(collector.location_available) === 1;

        const isOnline =
            Number(collector.is_online) === 1;

        lastUpdated.textContent =
            "Last GPS update: " +
            formatUpdatedTime(collector.updated_at);

        // ==================================
        // NO COLLECTOR GPS LOCATION YET
        // ==================================

        if (!locationAvailable) {

            collectorStatus.textContent =
                "Waiting for collector GPS location...";

            collectorStatus.className =
                "collector-status status-waiting";

            drivingDistance.textContent = "--";
            estimatedTime.textContent = "--";
            gpsDistance.textContent = "--";

            trackingMessage.innerHTML =
                '<i class="fas fa-info-circle"></i>' +
                ' Your house is displayed on the map. ' +
                'The collector marker and route will appear ' +
                'when the collector shares their GPS location.';

            if (!firstMapView) {

                map.setView(housePosition, 16);
                firstMapView = true;

            }

            return;

        }

        // ==================================
        // VALIDATE COLLECTOR GPS
        // ==================================

        const latitude = Number(collector.latitude);
        const longitude = Number(collector.longitude);

        if (!validCoordinates(latitude, longitude)) {
            throw new Error("Invalid collector GPS coordinates.");
        }

        const newCollectorPosition = [
            latitude,
            longitude
        ];

        // ==================================
        // ONLINE / OFFLINE STATUS
        // ==================================

        if (isOnline) {

            collectorStatus.innerHTML =
                '<i class="fas fa-circle me-1"></i>' +
                ' Online - GPS location updating';

            collectorStatus.className =
                "collector-status status-online";

        } else {

            collectorStatus.innerHTML =
                '<i class="fas fa-circle me-1"></i>' +
                ' Offline - Showing last known location';

            collectorStatus.className =
                "collector-status status-offline";

        }

        // ==================================
        // UPDATE COLLECTOR MARKER
        // ==================================

        const moved = collectorMovedEnough(
            newCollectorPosition
        );

        updateCollectorMarker(
            newCollectorPosition,
            collector.fullname
        );

        // ==================================
        // UPDATE GPS DISTANCE
        // ==================================

        updateGPSDistance();

        // ==================================
        // INITIAL MAP VIEW
        // ==================================

        if (!firstMapView) {

            const bounds = L.latLngBounds([
                housePosition,
                collectorPosition
            ]);

            map.fitBounds(bounds, {
                padding: [60, 60],
                maxZoom: 16
            });

            firstMapView = true;

        }

        // ==================================
        // UPDATE ROAD ROUTE
        // ==================================

        const shouldUpdateRoute =
            !routeLine ||
            moved ||
            Date.now() - lastRouteRequest >= 30000;

        if (shouldUpdateRoute) {

            await updateDrivingRoute(
                !routeLine || moved
            );

            previousCollectorPosition = [
                newCollectorPosition[0],
                newCollectorPosition[1]
            ];

        }

        // ==================================
        // TRACKING STATUS MESSAGE
        // ==================================

        if (isOnline) {

            trackingMessage.innerHTML =
                '<i class="fas fa-satellite-dish text-success"></i>' +
                ' Collector location is being monitored. ' +
                'The map checks for updates every 5 seconds.';

        } else {

            trackingMessage.innerHTML =
                '<i class="fas fa-exclamation-circle text-warning"></i>' +
                ' Collector is currently offline. ' +
                'The map is displaying the last recorded GPS location.';

        }

    } catch (error) {

        console.error(
            "Tracking error:",
            error
        );

        collectorStatus.textContent =
            error.message ||
            "Unable to retrieve tracking information. Retrying...";

        collectorStatus.className =
            "collector-status status-offline";

        trackingMessage.textContent =
            "Tracking update failed. The system will try again automatically.";

    } finally {

        isLoading = false;

    }

}


// ==========================================
// INITIALIZE MAP
// ==========================================

function initMap() {

    map = L.map("map").setView(
        defaultLocation,
        13
    );

    // OPENSTREETMAP
    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 19,

            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }
    ).addTo(map);

    // LOAD INITIAL DATA
    loadCollectorLocation();

    // REFRESH EVERY 5 SECONDS
    if (trackingInterval) {
        clearInterval(trackingInterval);
    }

    trackingInterval = setInterval(
        loadCollectorLocation,
        5000
    );

    // FIX MAP SIZE
    setTimeout(function() {
        map.invalidateSize();
    }, 300);

}


// ==========================================
// START MAP
// ==========================================

document.addEventListener("DOMContentLoaded", function() {

    if (typeof L === "undefined") {

        collectorStatus.textContent =
            "Map library failed to load. Check your internet connection.";

        return;

    }

    initMap();

});

</script>

</body>
</html>
