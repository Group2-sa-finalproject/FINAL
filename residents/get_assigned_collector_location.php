
<?php
session_start();
require_once __DIR__ . "/../config/database.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

function respond($success, $message = "", $data = [], $status = 200)
{
    http_response_code($status);

    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $data));

    exit;
}

// RESIDENT AUTHENTICATION
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "residents"
) {
    respond(false, "Unauthorized access.", [], 403);
}

$conn->set_charset("utf8mb4");

$resident_id = (int) $_SESSION["user_id"];

// FIND RESIDENT'S APPROVED SPECIAL PICKUP
$sql = "
    SELECT
        cs.id AS schedule_id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,

        h.id AS household_id,
        h.house_address,
        h.latitude AS house_latitude,
        h.longitude AS house_longitude,

        u.id AS collector_id,
        u.fullname AS collector_name,

        cl.latitude AS collector_latitude,
        cl.longitude AS collector_longitude,
        cl.updated_at,

        CASE
            WHEN cl.updated_at >= NOW() - INTERVAL 30 SECOND
            THEN 1
            ELSE 0
        END AS is_online

    FROM collection_schedules cs

    INNER JOIN households h
        ON h.user_id = cs.user_id

    INNER JOIN users u
        ON u.id = cs.collector_id
        AND u.role = 'collector'

    LEFT JOIN collector_locations cl
        ON cl.collector_id = u.id

    WHERE cs.user_id = ?
      AND cs.status = 'Approved'
      AND cs.collector_id IS NOT NULL

    ORDER BY
        cs.collection_date ASC,
        cs.requested_time ASC,
        cs.id DESC

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(false, "Database query failed.", [], 500);
}

$stmt->bind_param("i", $resident_id);

if (!$stmt->execute()) {
    $stmt->close();
    respond(false, "Unable to retrieve pickup information.", [], 500);
}

$result = $stmt->get_result();
$data = $result->fetch_assoc();

$stmt->close();

// NO APPROVED SPECIAL PICKUP
if (!$data) {
    respond(false,
        "No approved special pickup with an assigned collector was found."
    );
}

// VALIDATE HOUSE COORDINATES
$houseLat = $data["house_latitude"];
$houseLng = $data["house_longitude"];

if (
    !is_numeric($houseLat) ||
    !is_numeric($houseLng) ||
    abs((float)$houseLat) > 90 ||
    abs((float)$houseLng) > 180
) {
    respond(false, "Your registered house has invalid GPS coordinates.");
}

// VALIDATE COLLECTOR COORDINATES
$collectorLat = $data["collector_latitude"];
$collectorLng = $data["collector_longitude"];

$locationAvailable =
    is_numeric($collectorLat) &&
    is_numeric($collectorLng) &&
    abs((float)$collectorLat) <= 90 &&
    abs((float)$collectorLng) <= 180;

// RETURN TRACKING INFORMATION
respond(true, "Special pickup tracking information retrieved.", [

    "schedule_id" => (int)$data["schedule_id"],

    "collection_date" => $data["collection_date"],

    "requested_time" => $data["requested_time"],

    "collector_id" => (int)$data["collector_id"],

    "fullname" => $data["collector_name"],

    "latitude" => $locationAvailable
        ? (float)$collectorLat
        : null,

    "longitude" => $locationAvailable
        ? (float)$collectorLng
        : null,

    "updated_at" => $data["updated_at"],

    "is_online" => $locationAvailable
        ? (int)$data["is_online"]
        : 0,

    "location_available" => $locationAvailable,

    "household_id" => (int)$data["household_id"],

    "house_address" => $data["house_address"],

    "house_latitude" => (float)$houseLat,

    "house_longitude" => (float)$houseLng

]);

?>
