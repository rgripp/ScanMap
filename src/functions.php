<?php
// src/functions.php

class Database {
    private static $instance = null;
    private $pdo;
    private $queryCache = [];
    private const CACHE_DURATION = 300; // 5 minutes
    private const CACHE_PREFIX = 'db_cache_';
    private $invalidationTags = [];

    private function __construct() {
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASSWORD');

        try {
            $this->pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::ATTR_PERSISTENT => true
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException("Error connecting to the database: " . $e->getMessage());
        }

        // Initialize invalidation tags
        $this->invalidationTags = [
            'scans' => time(),
            'scannedObjects' => time()
        ];
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function getCacheKey($query, $params = [], $tags = []) {
        $baseKey = md5($query . serialize($params));
        $tagVersions = '';
        foreach ($tags as $tag) {
            if (isset($this->invalidationTags[$tag])) {
                $tagVersions .= $this->invalidationTags[$tag];
            }
        }
        return self::CACHE_PREFIX . $baseKey . '_' . md5($tagVersions);
    }

    private function getCache($key) {
        if (isset($this->queryCache[$key])) {
            list($data, $time, $ttl) = $this->queryCache[$key];
            if (time() - $time < $ttl) {
                return $data;
            }
            unset($this->queryCache[$key]);
        }
        return null;
    }

    private function setCache($key, $data, $ttl = null) {
        $ttl = $ttl ?? self::CACHE_DURATION;
        $this->queryCache[$key] = [$data, time(), $ttl];
        
        // Basic cache size management
        if (count($this->queryCache) > 1000) {
            uasort($this->queryCache, function($a, $b) {
                return $a[1] <=> $b[1];
            });
            $this->queryCache = array_slice($this->queryCache, -500, null, true);
        }
    }

    private function invalidateCache($tags = []) {
        foreach ($tags as $tag) {
            if (isset($this->invalidationTags[$tag])) {
                $this->invalidationTags[$tag] = time();
            }
        }
    }

    public function query($query, $params = [], $options = []) {
        $useCache = $options['useCache'] ?? true;
        $cacheTTL = $options['cacheTTL'] ?? self::CACHE_DURATION;
        $cacheTags = $options['cacheTags'] ?? [];

        if ($useCache) {
            $cacheKey = $this->getCacheKey($query, $params, $cacheTags);
            $cachedResult = $this->getCache($cacheKey);
            if ($cachedResult !== null) {
                return $cachedResult;
            }
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll();

        if ($useCache) {
            $this->setCache($cacheKey, $result, $cacheTTL);
        }

        return $result;
    }

    public function execute($query, $params = [], $tags = []) {
        $stmt = $this->pdo->prepare($query);
        $result = $stmt->execute($params);
        
        if (!empty($tags)) {
            $this->invalidateCache($tags);
        }
        
        return $result;
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollBack() {
        return $this->pdo->rollBack();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}

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
    return Database::getInstance()->getConnection();
}

function resetDatabase($pdo) {
    $db = Database::getInstance();
    try {
        $db->execute("SET FOREIGN_KEY_CHECKS = 0");
        $db->execute("TRUNCATE TABLE scannedObjects", [], ['scannedObjects']);
        $db->execute("TRUNCATE TABLE scans", [], ['scans']);
        $db->execute("SET FOREIGN_KEY_CHECKS = 1");

        return ["message" => "Database reset successfully!", "type" => "success"];
    } catch (PDOException $e) {
        return ["message" => "Error resetting the database: " . $e->getMessage(), "type" => "error"];
    }
}

function deleteScan($pdo, $scanId) {
    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        
        $db->execute(
            "DELETE FROM scannedObjects WHERE scanID = :scanId",
            [':scanId' => $scanId],
            ['scannedObjects']
        );
        
        $db->execute(
            "DELETE FROM scans WHERE id = :scanId",
            [':scanId' => $scanId],
            ['scans']
        );
        
        $db->commit();
        return ["message" => "Scan deleted successfully!", "type" => "success"];
    } catch (PDOException $e) {
        $db->rollBack();
        return ["message" => "Error deleting scan: " . $e->getMessage(), "type" => "error"];
    }
}

function isScanDuplicate($pdo, $xml) {
    try {
        $db = Database::getInstance();
        $result = $db->query(
            "SELECT COUNT(*) as count FROM scans 
            WHERE characterName = :characterName 
            AND years = :years 
            AND days = :days 
            AND hours = :hours 
            AND minutes = :minutes 
            AND seconds = :seconds 
            AND galX = :galX 
            AND galY = :galY 
            AND layer = :layer",
            [
                ':characterName' => isset($xml->channel->characterName) ? (string)$xml->channel->characterName : '',
                ':years' => isset($xml->channel->cgt->years) ? (int)$xml->channel->cgt->years : 0,
                ':days' => isset($xml->channel->cgt->days) ? (int)$xml->channel->cgt->days : 0,
                ':hours' => isset($xml->channel->cgt->hours) ? (int)$xml->channel->cgt->hours : 0,
                ':minutes' => isset($xml->channel->cgt->minutes) ? (int)$xml->channel->cgt->minutes : 0,
                ':seconds' => isset($xml->channel->cgt->seconds) ? (int)$xml->channel->cgt->seconds : 0,
                ':galX' => isset($xml->channel->location->galX) ? (int)$xml->channel->location->galX : 0,
                ':galY' => isset($xml->channel->location->galY) ? (int)$xml->channel->location->galY : 0,
                ':layer' => isset($xml->channel->location->layer) ? (string)$xml->channel->location->layer : '',
            ],
            [
                'useCache' => true,
                'cacheTTL' => 60,
                'cacheTags' => ['scans']
            ]
        );
        return $result[0]['count'] > 0;
    } catch (PDOException $e) {
        throw new PDOException("Error checking for duplicate scan: " . $e->getMessage());
    }
}

function insertScanData($pdo, $xml) {
    $db = Database::getInstance();
    $db->execute(
        "INSERT INTO scans (
            characterName, years, days, hours, minutes, seconds, galX, galY, layer
        ) VALUES (
            :characterName, :years, :days, :hours, :minutes, :seconds, :galX, :galY, :layer
        )",
        [
            ':characterName' => isset($xml->channel->characterName) ? (string)$xml->channel->characterName : '',
            ':years' => isset($xml->channel->cgt->years) ? (int)$xml->channel->cgt->years : 0,
            ':days' => isset($xml->channel->cgt->days) ? (int)$xml->channel->cgt->days : 0,
            ':hours' => isset($xml->channel->cgt->hours) ? (int)$xml->channel->cgt->hours : 0,
            ':minutes' => isset($xml->channel->cgt->minutes) ? (int)$xml->channel->cgt->minutes : 0,
            ':seconds' => isset($xml->channel->cgt->seconds) ? (int)$xml->channel->cgt->seconds : 0,
            ':galX' => isset($xml->channel->location->galX) ? (int)$xml->channel->location->galX : 0,
            ':galY' => isset($xml->channel->location->galY) ? (int)$xml->channel->location->galY : 0,
            ':layer' => isset($xml->channel->location->layer) ? (string)$xml->channel->location->layer : '',
        ],
        ['scans']
    );

    return $db->lastInsertId();
}

function insertScannedObjects($pdo, $xml, $scanID) {
    if (!isset($xml->channel->item)) {
        return;
    }

    $db = Database::getInstance();
    $batchSize = 100;
    $values = [];
    $placeholders = [];
    $params = [];
    $counter = 0;

    foreach ($xml->channel->item as $index => $item) {
        $counter++;
        $prefix = 'item' . $counter . '_';
        
        $placeholders[] = "(
            :scanID_{$counter}, :{$prefix}inParty, :{$prefix}name, :{$prefix}typeName, 
            :{$prefix}typeUID, :{$prefix}entityID, :{$prefix}entityType, :{$prefix}entityTypeName, 
            :{$prefix}entityUID, :{$prefix}hull, :{$prefix}hullMax, :{$prefix}shield, 
            :{$prefix}shieldMax, :{$prefix}ionic, :{$prefix}ionicMax, :{$prefix}underConstruction, 
            :{$prefix}sharingSensors, :{$prefix}x, :{$prefix}y, :{$prefix}travelDirection, 
            :{$prefix}ownerName, :{$prefix}ownerUID, :{$prefix}iffStatus, :{$prefix}image, 
            :{$prefix}partyLeaderUID, :{$prefix}partyLeaderName
        )";

        $params["scanID_{$counter}"] = $scanID;
        $params["{$prefix}inParty"] = isset($item->inParty) ? (string)$item->inParty : null;
        $params["{$prefix}name"] = isset($item->name) ? (string)$item->name : null;
        $params["{$prefix}typeName"] = isset($item->typeName) ? (string)$item->typeName : null;
        $params["{$prefix}typeUID"] = isset($item->typeUID) ? (string)$item->typeUID : null;
        $params["{$prefix}entityID"] = isset($item->entityID) ? (int)$item->entityID : null;
        $params["{$prefix}entityType"] = isset($item->entityType) ? (int)$item->entityType : null;
        $params["{$prefix}entityTypeName"] = isset($item->entityTypeName) ? (string)$item->entityTypeName : null;
        $params["{$prefix}entityUID"] = isset($item->entityUID) ? (string)$item->entityUID : null;
        $params["{$prefix}hull"] = isset($item->hull) ? (int)$item->hull : null;
        $params["{$prefix}hullMax"] = isset($item->hullMax) ? (int)$item->hullMax : null;
        $params["{$prefix}shield"] = isset($item->shield) ? (int)$item->shield : null;
        $params["{$prefix}shieldMax"] = isset($item->shieldMax) ? (int)$item->shieldMax : null;
        $params["{$prefix}ionic"] = isset($item->ionic) ? (int)$item->ionic : null;
        $params["{$prefix}ionicMax"] = isset($item->ionicMax) ? (int)$item->ionicMax : null;
        $params["{$prefix}underConstruction"] = isset($item->underConstruction) ? (string)$item->underConstruction : null;
        $params["{$prefix}sharingSensors"] = isset($item->sharingSensors) ? (string)$item->sharingSensors : null;
        $params["{$prefix}x"] = isset($item->x) ? (int)$item->x : null;
        $params["{$prefix}y"] = isset($item->y) ? (int)$item->y : null;
        $params["{$prefix}travelDirection"] = isset($item->travelDirection) ? (string)$item->travelDirection : null;
        $params["{$prefix}ownerName"] = isset($item->ownerName) ? (string)$item->ownerName : null;
        $params["{$prefix}ownerUID"] = isset($item->ownerUID) ? (string)$item->ownerUID : null;
        $params["{$prefix}iffStatus"] = isset($item->iffStatus) ? (string)$item->iffStatus : null;
        $params["{$prefix}image"] = isset($item->image) ? (string)$item->image : null;
        $params["{$prefix}partyLeaderUID"] = isset($item->partyLeaderUID) ? (string)$item->partyLeaderUID : null;
        $params["{$prefix}partyLeaderName"] = isset($item->partyLeaderName) ? (string)$item->partyLeaderName : null;

        // Execute in batches
        if ($counter % $batchSize === 0) {
            executeBatchInsert($db, $placeholders, $params);
            $placeholders = [];
            $params = [];
            $counter = 0;
        }
    }

    // Insert remaining items
    if (!empty($placeholders)) {
        executeBatchInsert($db, $placeholders, $params);
    }
}

function executeBatchInsert($db, $placeholders, $params) {
    $sql = "INSERT INTO scannedObjects (
        scanID, inParty, name, typeName, typeUID, entityID, entityType, entityTypeName,
        entityUID, hull, hullMax, shield, shieldMax, ionic, ionicMax, underConstruction,
        sharingSensors, x, y, travelDirection, ownerName, ownerUID, iffStatus, image,
        partyLeaderUID, partyLeaderName
    ) VALUES " . implode(", ", $placeholders);

    $db->execute($sql, $params, ['scannedObjects']);
}

function fetchScans($pdo) {
    return Database::getInstance()->query(
        "SELECT id, characterName, galX, galY, years, days, hours, minutes, seconds 
         FROM scans 
         ORDER BY id DESC",
        [],
        [
            'useCache' => true,
            'cacheTTL' => 300,
            'cacheTags' => ['scans']
        ]
    );
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
        $db = Database::getInstance();
        $db->beginTransaction();

        // Check for duplicate scan
        if (isScanDuplicate($pdo, $xml)) {
            return ["message" => "Scan already added", "type" => "error"];
        }

        $scanID = insertScanData($pdo, $xml);
        insertScannedObjects($pdo, $xml, $scanID);
        
        $db->commit();
        return ["message" => "Database updated successfully!", "type" => "success"];
    } catch (PDOException $e) {
        $db->rollBack();
        return ["message" => "Error processing XML: " . $e->getMessage(), "type" => "error"];
    }
}