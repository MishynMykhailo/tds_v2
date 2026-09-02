<?php

/**
 * Port of legacy `Traffic\Context\RobotsContext` + `Traffic\Dispatcher\
 * RobotsDispatcher` (application/Traffic/Context/RobotsContext.php,
 * application/Traffic/Dispatcher/RobotsDispatcher.php) — serves
 * `/robots.txt` based on the requested domain's `allow_indexing` flag.
 *
 * Ported literally: `_findDomainRobots()`'s default is `true` (allow)
 * when the Host header doesn't match any row in `domains` — confirmed by
 * reading the legacy method body, not assumed.
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Db;

const ROBOTS_ALLOW = "User-agent: *\nAllow: /";
const ROBOTS_DISALLOW = "User-agent: *\nDisallow: /";

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

$host = explode(':', $request->getHeaderLine('Host'))[0];

$allowIndexing = true;
if ($host !== '') {
    $stmt = Db::instance()->prepare('SELECT allow_indexing FROM domains WHERE name = ? LIMIT 1');
    $stmt->execute([$host]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row !== false) {
        $allowIndexing = (bool) $row['allow_indexing'];
    }
}

header('Content-Type: text/plain');
echo $allowIndexing ? ROBOTS_ALLOW : ROBOTS_DISALLOW;
