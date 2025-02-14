<?php
// **
// USED TO DEFINE API ENDPOINTS
// **

// Get Meraki Plugin Settings
$app->get('/plugin/Patching/settings', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->api->setAPIResponseData($Patching->_pluginGetSettings());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Search for servers with their patching configuration
$app->get('/plugin/Patching/servers', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->api->setAPIResponseData($Patching->searchServers($request));
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Get servers that are due for patching based on current time
$app->get('/plugin/Patching/servers/due', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->api->setAPIResponseData($Patching->getServersDueForPatching());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Get servers that are due for patching based on current time, categorised by OS
$app->get('/plugin/Patching/servers/due/categorised', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->api->setAPIResponseData($Patching->categorizeServersDueForPatching());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Get patching history
$app->get('/plugin/Patching/history', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->api->setAPIResponseData($Patching->getPatchingHistory());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Check if server exists in AWX inventory
$app->get('/plugin/Patching/server/check/{serverName}', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $serverName = $args['serverName'];
        $result = $Patching->checkServerInAWX($serverName);
        $Patching->api->setAPIResponseData($result);
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Trigger cron job execution
$app->get('/plugin/Patching/cron/execute', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->logging->writeLog('Patching', 'Manually triggering cron job execution', 'debug');
        $Patching->api->setAPIResponseData($Patching->executeCronJob());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Verify database structure
$app->get('/plugin/Patching/database/verify', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->logging->writeLog('Patching', 'Verifying database structure', 'debug');
        $Patching->api->setAPIResponseData($Patching->verifyDatabaseStructure());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});

// Recreate database structure
$app->post('/plugin/Patching/database/recreate', function ($request, $response, $args) {
    $Patching = new Patching();
    if ($Patching->auth->checkAccess('ADMIN-CONFIG')) {
        $Patching->logging->writeLog('Patching', 'Recreating database structure', 'debug');
        $Patching->api->setAPIResponseData($Patching->recreateDatabaseStructure());
    }
    $response->getBody()->write(jsonE($GLOBALS['api']));
    return $response
        ->withHeader('Content-Type', 'application/json;charset=UTF-8')
        ->withStatus($GLOBALS['responseCode']);
});