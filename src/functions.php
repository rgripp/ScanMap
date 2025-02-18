<?php
// src/functions.php

function loadEnvironmentVariables($envFile) {
    if (file_exists($envFile)) {
        $envVariables = parse_ini_file($envFile);
        foreach ($envVariables as $key => $value) {
            putenv("$key=$value");
        }
        return true;
    }
    return false;
}

function connectToDatabase() {
    $dbHost = getenv('DB_HOST');
    $dbName = getenv('DB_NAME');
    $dbUser = getenv('DB_USER');
    $dbPass = getenv('DB_PASSWORD');

    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new PDOException("Error connecting to the database: " . $e->getMessage());
    }
}

function resetDatabase($pdo) {
    try {
        $pdo->exec("DELETE FROM scans");
        $pdo->exec("DELETE FROM scannedObjects");
        $pdo->exec("ALTER TABLE scans AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE scannedObjects AUTO_INCREMENT = 1");
        return ["message" => "Database reset successfully!", "type" => "success"];
    } catch (PDOException $e) {
        return ["message" => "Error resetting the database: " . $e->getMessage(), "type" => "error"];
    }
}

function isScanDuplicate($pdo, $xml) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM scans 
            WHERE characterName = :characterName 
            AND years = :years 
            AND days = :days 
            AND hours = :hours 
            AND minutes = :minutes 
            AND seconds = :seconds 
            AND galX = :galX 
            AND galY = :galY 
            AND layer = :layer
        ");

        $stmt->execute([
            ':characterName' => isset($xml->channel->characterName) ? (string)$xml->channel->characterName : '',
            ':years' => isset($xml->channel->cgt->years) ? (int)$xml->channel->cgt->years : 0,
            ':days' => isset($xml->channel->cgt->days) ? (int)$xml->channel->cgt->days : 0,
            ':hours' => isset($xml->channel->cgt->hours) ? (int)$xml->channel->cgt->hours : 0,
            ':minutes' => isset($xml->channel->cgt->minutes) ? (int)$xml->channel->cgt->minutes : 0,
            ':seconds' => isset($xml->channel->cgt->seconds) ? (int)$xml->channel->cgt->seconds : 0,
            ':galX' => isset($xml->channel->location->galX) ? (int)$xml->channel->location->galX : 0,
            ':galY' => isset($xml->channel->location->galY) ? (int)$xml->channel->location->galY : 0,
            ':layer' => isset($xml->channel->location->layer) ? (string)$xml->channel->location->layer : '',
        ]);

        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        throw new PDOException("Error checking for duplicate scan: " . $e->getMessage());
    }
}

function processXMLFile($pdo, $tmpFilePath) {
    $fileType = mime_content_type($tmpFilePath);
    if ($fileType !== 'text/xml' && $fileType !== 'application/xml') {
        return ["message" => "Error: The uploaded file is not a valid XML file.", "type" => "error"];
    }

    $xmlContent = file_get_contents($tmpFilePath);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        return ["message" => "Error: Failed to parse the XML file.", "type" => "error"];
    }

    try {
        // Check for duplicate scan
        if (isScanDuplicate($pdo, $xml)) {
            return ["message" => "Scan already added", "type" => "error"];
        }

        $scanID = insertScanData($pdo, $xml);
        insertScannedObjects($pdo, $xml, $scanID);
        return ["message" => "Database updated successfully!", "type" => "success"];
    } catch (PDOException $e) {
        return ["message" => "Error processing XML: " . $e->getMessage(), "type" => "error"];
    }
}

function insertScanData($pdo, $xml) {
    $stmt = $pdo->prepare("
        INSERT INTO scans (
            characterName, years, days, hours, minutes, seconds, galX, galY, layer
        ) VALUES (
            :characterName, :years, :days, :hours, :minutes, :seconds, :galX, :galY, :layer
        )
    ");

    $stmt->execute([
        ':characterName' => isset($xml->channel->characterName) ? (string)$xml->channel->characterName : '',
        ':years' => isset($xml->channel->cgt->years) ? (int)$xml->channel->cgt->years : 0,
        ':days' => isset($xml->channel->cgt->days) ? (int)$xml->channel->cgt->days : 0,
        ':hours' => isset($xml->channel->cgt->hours) ? (int)$xml->channel->cgt->hours : 0,
        ':minutes' => isset($xml->channel->cgt->minutes) ? (int)$xml->channel->cgt->minutes : 0,
        ':seconds' => isset($xml->channel->cgt->seconds) ? (int)$xml->channel->cgt->seconds : 0,
        ':galX' => isset($xml->channel->location->galX) ? (int)$xml->channel->location->galX : 0,
        ':galY' => isset($xml->channel->location->galY) ? (int)$xml->channel->location->galY : 0,
        ':layer' => isset($xml->channel->location->layer) ? (string)$xml->channel->location->layer : '',
    ]);

    return $pdo->lastInsertId();
}

function insertScannedObjects($pdo, $xml, $scanID) {
    if (!isset($xml->channel->item)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO scannedObjects (
            scanID, inParty, name, typeName, typeUID, entityID, entityType, entityTypeName, entityUID,
            hull, hullMax, shield, shieldMax, ionic, ionicMax, underConstruction, sharingSensors,
            x, y, travelDirection, ownerName, ownerUID, iffStatus, image, partyLeaderUID, partyLeaderName
        ) VALUES (
            :scanID, :inParty, :name, :typeName, :typeUID, :entityID, :entityType, :entityTypeName, :entityUID,
            :hull, :hullMax, :shield, :shieldMax, :ionic, :ionicMax, :underConstruction, :sharingSensors,
            :x, :y, :travelDirection, :ownerName, :ownerUID, :iffStatus, :image, :partyLeaderUID, :partyLeaderName
        )
    ");

    foreach ($xml->channel->item as $item) {
        $stmt->execute([
            ':scanID' => $scanID,
            ':inParty' => isset($item->inParty) ? (string)$item->inParty : null,
            ':name' => isset($item->name) ? (string)$item->name : null,
            ':typeName' => isset($item->typeName) ? (string)$item->typeName : null,
            ':typeUID' => isset($item->typeUID) ? (string)$item->typeUID : null,
            ':entityID' => isset($item->entityID) ? (int)$item->entityID : null,
            ':entityType' => isset($item->entityType) ? (int)$item->entityType : null,
            ':entityTypeName' => isset($item->entityTypeName) ? (string)$item->entityTypeName : null,
            ':entityUID' => isset($item->entityUID) ? (string)$item->entityUID : null,
            ':hull' => isset($item->hull) ? (int)$item->hull : null,
            ':hullMax' => isset($item->hullMax) ? (int)$item->hullMax : null,
            ':shield' => isset($item->shield) ? (int)$item->shield : null,
            ':shieldMax' => isset($item->shieldMax) ? (int)$item->shieldMax : null,
            ':ionic' => isset($item->ionic) ? (int)$item->ionic : null,
            ':ionicMax' => isset($item->ionicMax) ? (int)$item->ionicMax : null,
            ':underConstruction' => isset($item->underConstruction) ? (string)$item->underConstruction : null,
            ':sharingSensors' => isset($item->sharingSensors) ? (string)$item->sharingSensors : null,
            ':x' => isset($item->x) ? (int)$item->x : null,
            ':y' => isset($item->y) ? (int)$item->y : null,
            ':travelDirection' => isset($item->travelDirection) ? (string)$item->travelDirection : null,
            ':ownerName' => isset($item->ownerName) ? (string)$item->ownerName : null,
            ':ownerUID' => isset($item->ownerUID) ? (string)$item->ownerUID : null,
            ':iffStatus' => isset($item->iffStatus) ? (string)$item->iffStatus : null,
            ':image' => isset($item->image) ? (string)$item->image : null,
            ':partyLeaderUID' => isset($item->partyLeaderUID) ? (string)$item->partyLeaderUID : null,
            ':partyLeaderName' => isset($item->partyLeaderName) ? (string)$item->partyLeaderName : null,
        ]);
    }
}

function getScans($pdo) {
    $stmt = $pdo->query("SELECT id, characterName, years, days, hours, minutes, seconds FROM scans ORDER BY years DESC, days DESC, hours DESC, minutes DESC, seconds DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchScans($pdo) {
    $stmt = $pdo->query("SELECT id, characterName, galX, galY, years, days, hours, minutes, seconds FROM scans ORDER BY id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
