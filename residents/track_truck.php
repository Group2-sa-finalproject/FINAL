
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

/* =========================================================
   GENERAL
========================================================= */

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

/* =========================================================
   SIDEBAR
========================================================= */

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

/* =========================================================
   MAIN CONTENT
========================================================= */

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

/* =========================================================
   PAGE HEADER
========================================================= */

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

/* =========================================================
   SECTION TITLE
========================================================= */

.section-title {
    color: #45564d;
    font-size: 17px;
    font-weight: 700;
    margin: 30px 0 18px;
}

/* =========================================================
   TRACKING CARDS
========================================================= */

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

/* =========================================================
   TRACKING ICON
========================================================= */

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

/* =========================================================
   COLLECTOR INFORMATION
========================================================= */

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

/* =========================================================
   MAP
========================================================= */

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

/* =========================================================
   FOOTER
========================================================= */

.dashboard-footer {
    margin-top: 35px;
    padding: 20px 0;
    border-top: 1px solid #e2e9e5;
    color: #929b96;
    font-size: 12px;
    text-align: center;
}

/* =========================================================
   RESPONSIVE
========================================================= */

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

        <!-- PAGE HEADER -->
        <div class="page-heading">

            <h2>
                <i class="fas fa-truck me-2"></i>
                Track Garbage Truck
            </h2>

            <p>
                Monitor the collector assigned to your household.
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

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- LIVE COLLECTOR LOCATION -->
        <h6 class="section-title">

            <i class="fas fa-map-marker-alt me-2"></i>
            Live Collector Location

        </h6>

        <div class="tracking-card">

            <h5>
                <i class="fas fa-location-dot me-2 text-success"></i>
                Collector Tracking Map
            </h5>

            <p class="tracking-description">
                View the real-time location of your assigned
                garbage collector. The map automatically updates
                every 5 seconds.
            </p>

            <div class="map-container">
                <div id="map"></div>
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

<!-- LEAFLET MAP JAVASCRIPT -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

// ==========================================
// LEAFLET RESIDENT TRUCK TRACKING
// ==========================================

let map;

let collectorMarker = null;

let firstLocationLoaded = false;

let trackingInterval = null;

let isLoading = false;


// DEFAULT MAP LOCATION
const defaultLocation = [15.4802, 120.5979];


// ==========================================
// LOAD COLLECTOR LOCATION
// ==========================================

async function loadCollectorLocation() {

    if (isLoading || !map) {
        return;
    }

    isLoading = true;

    const nameElement =
        document.getElementById("collectorName");

    const statusElement =
        document.getElementById("collectorStatus");

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

        } catch (jsonError) {

            console.error(
                "Invalid API response:",
                responseText
            );

            throw new Error(
                "The tracking API returned an invalid response. Check the PHP file for errors."
            );

        }

        if (!response.ok) {

            throw new Error(
                collector.message || "Unable to load collector."
            );

        }


        // NO ASSIGNED COLLECTOR
        if (!collector.success) {

            nameElement.textContent =
                collector.message || "No assigned collector found.";

            statusElement.textContent =
                "Waiting for an assigned collector.";

            statusElement.className =
                "collector-status status-waiting";

            if (collectorMarker) {

                map.removeLayer(collectorMarker);

                collectorMarker = null;

            }

            firstLocationLoaded = false;

            return;

        }


        // COLLECTOR NAME
        nameElement.textContent =
            "Collector: " + collector.fullname;


        // COLLECTOR COORDINATES
        const latitude = Number(collector.latitude);

        const longitude = Number(collector.longitude);

        if (
            !Number.isFinite(latitude) ||
            !Number.isFinite(longitude) ||
            Math.abs(latitude) > 90 ||
            Math.abs(longitude) > 180
        ) {

            throw new Error(
                "Invalid collector coordinates."
            );

        }

        const position = [
            latitude,
            longitude
        ];


        // ONLINE / OFFLINE STATUS
        const isOnline =
            Number(collector.is_online) === 1;


        if (isOnline) {

            statusElement.innerHTML =
                '<i class="fas fa-circle me-1"></i> Online • GPS location updating';

            statusElement.className =
                "collector-status status-online";

        } else {

            statusElement.innerHTML =
                '<i class="fas fa-circle me-1"></i> Offline • Showing last known location';

            statusElement.className =
                "collector-status status-offline";

        }


        // CREATE OR UPDATE LEAFLET MARKER
        if (!collectorMarker) {

            collectorMarker = L.marker(position, {
                title: collector.fullname,
                alt: "Garbage truck location"
            }).addTo(map);

            collectorMarker.bindPopup(
                "<strong>Garbage Collector</strong><br>" +
                collector.fullname
            );

        } else {

            collectorMarker.setLatLng(position);

            collectorMarker.setPopupContent(
                "<strong>Garbage Collector</strong><br>" +
                collector.fullname
            );

        }


        // CENTER MAP ON FIRST LOCATION
        if (!firstLocationLoaded) {

            map.setView(position, 16);

            collectorMarker.openPopup();

            firstLocationLoaded = true;

        }

    } catch (error) {

        console.error(
            "Tracking error:",
            error
        );

        nameElement.textContent =
            "Collector Information";

        statusElement.textContent =
            error.message ||
            "Unable to retrieve collector location. Retrying...";

        statusElement.className =
            "collector-status status-offline";

    } finally {

        isLoading = false;

    }

}


// ==========================================
// INITIALIZE LEAFLET MAP
// ==========================================

function initMap() {

    map = L.map("map").setView(
        defaultLocation,
        13
    );


    // OPENSTREETMAP TILE LAYER
    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 19,

            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }
    ).addTo(map);


    // LOAD INITIAL COLLECTOR LOCATION
    loadCollectorLocation();


    // REFRESH EVERY 5 SECONDS
    if (trackingInterval) {

        clearInterval(trackingInterval);

    }

    trackingInterval = setInterval(
        loadCollectorLocation,
        5000
    );


    // FIX MAP DISPLAY SIZE
    setTimeout(function () {

        map.invalidateSize();

    }, 300);

}


// ==========================================
// START MAP AFTER PAGE LOAD
// ==========================================

document.addEventListener("DOMContentLoaded", function () {

    if (typeof L === "undefined") {

        console.error("Leaflet failed to load.");

        document.getElementById("collectorStatus").textContent =
            "Map library failed to load. Please check your internet connection.";

        return;

    }

    initMap();

});

</script>

</body>
</html>
