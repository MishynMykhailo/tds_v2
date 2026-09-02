<?php

/**
 * Port of legacy `Traffic\Context\UpdateTokensContext` + `Traffic\
 * Dispatcher\UpdateTokensDispatcher` (application/Traffic/Context/
 * UpdateTokensContext.php, application/Traffic/Dispatcher/
 * UpdateTokensDispatcher.php) — a landing page's own JS calls this after
 * the initial click to report additional `sub_id_N`/`extra_param_N`
 * values it only learned client-side (e.g. a form field filled in on the
 * page). Requires `sub_id` (the click to update); queues the update via
 * `TrafficCore\Queue\ClickUpdateQueue`, applied by the SAME worker that
 * drains the click-insert queue (`bin/process_click_queue.php`).
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Queue\ClickUpdateQueue;

const SUB_ID_COUNT = 15;
const EXTRA_PARAM_COUNT = 10;

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

$body = $request->getParsedBody();
$params = array_merge(is_array($body) ? $body : [], $request->getQueryParams());

if (empty($params['sub_id'])) {
    http_response_code(400);
    echo '[UpdateTokens] SubId is empty in : ' . json_encode($params);
    exit;
}

$fields = ['sub_id' => (string) $params['sub_id']];

for ($i = 1; $i <= SUB_ID_COUNT; $i++) {
    $key = "sub_id_{$i}";
    if (!empty($params[$key])) {
        $fields[$key] = urldecode((string) $params[$key]);
    }
}

for ($i = 1; $i <= EXTRA_PARAM_COUNT; $i++) {
    $key = "extra_param_{$i}";
    if (!empty($params[$key])) {
        $fields[$key] = urldecode((string) $params[$key]);
    }
}

if (!empty($params['offer_id'])) {
    $fields['offer_id'] = (int) $params['offer_id'];
}

if (!empty($params['is_bot'])) {
    $fields['is_bot'] = (int) $params['is_bot'];
}

(new ClickUpdateQueue())->push($fields);

http_response_code(200);
