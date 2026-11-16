<?php ?>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<style>
.tracking-page-heading {
    margin-bottom: 25px;
}

.tracking-page-title {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 26px;
    color: #183d2b;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: 0;
    margin: 0 0 7px;
}

.tracking-page-subtitle {
    color: #78877e;
    font-size: 14px;
    margin: 0;
}

.tracking-card {
    background: #fff;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
    margin-bottom: 20px;
}

#collectorMap {
    width: 100%;
    height: 550px;
    border-radius: 10px;
    background: #e9efeb;
    overflow: hidden;
    z-index: 1;
}

#collectorMap.leaflet-container {
    background: #e9efeb;
}

#collectorMap .leaflet-tile {
    visibility: visible !important;
}

#collectorMap img {
    max-width: none !important;
    max-height: none !important;
}

.collector-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 5px;
    border-bottom: 1px solid #edf0ed;
    cursor: pointer;
    transition: background .2s;
}

.collector-row:hover {
    background: #f5faf6;
}

.collector-row:last-child {
    border-bottom: 0;
}

.collector-name {
    color: #183d2b;
    font-weight: 600;
}

.collector-coordinates {
    font-size: 12px;
    color: #77847c;
    margin-top: 4px;
}

.tracking-status {
    white-space: nowrap;
}

.collector-popup {
    min-width: 180px;
    font-family: Arial, sans-serif;
}

.collector-popup strong {
    color: #198754;
    font-size: 14px;
}

.collector-popup div {
    margin-top: 5px;
    font-size: 13px;
}

.collector-popup small {
    display: block;
    margin-top: 5px;
    color: #77847c;
}

@media (max-width: 700px) {
    .tracking-page-title {
        font-size: 21px;
    }
}

@media (max-width: 600px) {
    #collectorMap {
        height: 400px;
    }

    .tracking-card {
        padding: 14px;
    }
}
</style>

<div class="tracking-page-heading">
    <h2 class="tracking-page-title">Live Collector Tracking</h2>

    <p class="tracking-page-subtitle">
        Monitor the latest GPS locations reported by your waste collectors.
    </p>
</div>

<div class="tracking-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="fw-bold mb-0">
            <i class="fas fa-map-marked-alt"></i>
            Collector Locations
        </h5>

        <span id="trackingLastUpdated" class="text-secondary small">
            Waiting for location data...
        </span>
    </div>

    <div id="collectorMap"></div>

    <div id="trackingError" class="alert alert-warning mt-3 d-none"></div>
</div>

<div class="tracking-card">
    <h5 class="fw-bold mb-3">
        <i class="fas fa-users"></i>
        Collector Status
    </h5>

    <div id="collectorList" class="text-secondary">
        Loading collectors...
    </div>
</div>

<script>
(function () {
    "use strict";

    if (window.ecoTrackInitialized) {
        return;
    }

    window.ecoTrackInitialized = true;

    const mapElement = document.getElementById("collectorMap");
    const listElement = document.getElementById("collectorList");
    const updatedElement = document.getElementById("trackingLastUpdated");
    const errorElement = document.getElementById("trackingError");

    if (!mapElement || !listElement || !updatedElement || !errorElement) {
        window.ecoTrackInitialized = false;
        console.error("Tracking elements are missing.");
        return;
    }

    let map = null;
    let requestInProgress = false;
    let firstValidLocation = true;

    const markerByCollector = Object.create(null);

    function loadLeaflet() {

        return new Promise(function (resolve, reject) {

            if (window.L) {
                resolve();
                return;
            }

            if (window.ecoLeafletLoading) {
                window.ecoLeafletLoading.then(resolve).catch(reject);
                return;
            }

            window.ecoLeafletLoading = new Promise(function (resolveLoad, rejectLoad) {

                const script = document.createElement("script");

                script.src =
                    "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";

                script.onload = function () {

                    if (window.L) {
                        resolveLoad();
                    } else {
                        rejectLoad(
                            new Error("Leaflet loaded, but its library was not found.")
                        );
                    }
                };

                script.onerror = function () {
                    rejectLoad(
                        new Error("Leaflet failed to load. Check your internet connection.")
                    );
                };

                document.head.appendChild(script);
            });

            window.ecoLeafletLoading.then(resolve).catch(reject);
        });
    }

    function initializeMap() {

        map = L.map(mapElement).setView(
            [15.4802, 120.5979],
            12
        );

        L.tileLayer(
            "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
            {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }
        ).addTo(map);

        setTimeout(function () {
            map.invalidateSize(true);
        }, 300);

        setTimeout(function () {
            map.invalidateSize(true);
        }, 700);

        window.addEventListener("resize", function () {
            if (map) {
                map.invalidateSize(true);
            }
        });

        if (window.ResizeObserver) {

            const observer = new ResizeObserver(function () {
                if (map) {
                    map.invalidateSize(true);
                }
            });

            observer.observe(mapElement);
        }
    }

    function showError(message) {
        errorElement.textContent = message;
        errorElement.classList.remove("d-none");
    }

    function clearError() {
        errorElement.textContent = "";
        errorElement.classList.add("d-none");
    }

    function createCollectorPopup(collector) {

        const popup = document.createElement("div");
        popup.className = "collector-popup";

        const name = document.createElement("strong");

        name.textContent =
            collector.fullname || ("Collector #" + collector.id);

        const status = document.createElement("div");

        status.textContent =
            Number(collector.is_online) === 1
                ? "Status: Online"
                : "Status: Offline";

        const updated = document.createElement("small");

        updated.textContent =
            "Last GPS update: " +
            (collector.updated_at || "Unknown");

        popup.appendChild(name);
        popup.appendChild(status);
        popup.appendChild(updated);

        return popup;
    }

    function makeCollectorRow(collector) {

        const row = document.createElement("div");
        row.className = "collector-row";

        const left = document.createElement("div");

        const name = document.createElement("div");
        name.className = "collector-name";

        name.textContent =
            collector.fullname || ("Collector #" + collector.id);

        const coordinates = document.createElement("div");
        coordinates.className = "collector-coordinates";

        coordinates.textContent =
            Number(collector.latitude).toFixed(6) +
            ", " +
            Number(collector.longitude).toFixed(6);

        left.appendChild(name);
        left.appendChild(coordinates);

        const status = document.createElement("span");
        status.className = "badge tracking-status";

        if (Number(collector.is_online) === 1) {
            status.classList.add("bg-success");
            status.textContent = "Online";
        } else {
            status.classList.add("bg-secondary");
            status.textContent = "Offline";
        }

        row.appendChild(left);
        row.appendChild(status);

        row.addEventListener("click", function () {

            const id = String(collector.id);
            const marker = markerByCollector[id];

            if (!marker || !map) {
                return;
            }

            const point = marker.getLatLng();

            map.setView(point, 17);

            marker.openPopup();
        });

        return row;
    }

    async function loadCollectors() {

        if (requestInProgress) {
            return;
        }

        requestInProgress = true;

        try {

            const response = await fetch(
                new URL("get_collector_locations.php", window.location.href),
                {
                    method: "GET",
                    cache: "no-store",
                    headers: {
                        "Accept": "application/json"
                    }
                }
            );

            const responseText = await response.text();

            if (!response.ok) {
                throw new Error(
                    "API returned HTTP " +
                    response.status +
                    ": " +
                    responseText.substring(0, 250)
                );
            }

            let collectors;

            try {
                collectors = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(
                    "API did not return valid JSON. Response: " +
                    responseText.substring(0, 250)
                );
            }

            if (!Array.isArray(collectors)) {
                throw new Error(
                    collectors.error || "Unexpected API response."
                );
            }

            clearError();

            listElement.innerHTML = "";

            const receivedIds = new Set();

            let validCount = 0;

            collectors.forEach(function (collector) {

                const id = Number(collector.id);
                const lat = Number(collector.latitude);
                const lng = Number(collector.longitude);

                if (
                    !Number.isInteger(id) ||
                    !Number.isFinite(lat) ||
                    !Number.isFinite(lng) ||
                    lat < -90 ||
                    lat > 90 ||
                    lng < -180 ||
                    lng > 180
                ) {
                    return;
                }

                validCount++;

                receivedIds.add(String(id));

                const position = [lat, lng];

                const safeName = String(
                    collector.fullname || "Collector"
                );

                if (markerByCollector[id]) {

                    markerByCollector[id].setLatLng(position);

                } else {

                    markerByCollector[id] = L.marker(position).addTo(map);

                }

                markerByCollector[id].bindPopup(
                    createCollectorPopup(collector)
                );

                markerByCollector[id].bindTooltip(
                    safeName
                );

                listElement.appendChild(
                    makeCollectorRow(collector)
                );

                if (firstValidLocation) {

                    map.setView(position, 15);

                    firstValidLocation = false;

                }

            });

            Object.keys(markerByCollector).forEach(function (id) {

                if (!receivedIds.has(String(id))) {

                    map.removeLayer(markerByCollector[id]);

                    delete markerByCollector[id];

                }

            });

            if (validCount === 0) {

                listElement.textContent =
                    "No collector GPS records found. Check whether collectors have shared their location.";

            }

            updatedElement.textContent =
                "Last checked: " +
                new Date().toLocaleTimeString();

        } catch (error) {

            console.error("Collector tracking error:", error);

            showError(error.message);

            listElement.textContent =
                "Unable to load collector locations. See the error message above.";

        } finally {

            requestInProgress = false;

        }
    }

    async function startTracking() {

        try {

            await loadLeaflet();

            initializeMap();

            await loadCollectors();

            window.ecoTrackTrackingInterval = setInterval(
                loadCollectors,
                5000
            );

        } catch (error) {

            console.error("Leaflet tracking initialization error:", error);

            showError(error.message);

            listElement.textContent =
                "Unable to initialize the tracking map.";

            window.ecoTrackInitialized = false;

        }
    }

    startTracking();

})();
</script>