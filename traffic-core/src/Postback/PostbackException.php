<?php

namespace TrafficCore\Postback;

/**
 * Port of legacy `Component\Postback\PostbackError`
 * (application/Component/Postback/PostbackError.php) — thrown for any
 * "expected" postback-processing failure (missing/unknown sub_id) whose
 * message is meant to be echoed back to the caller as the plain-text
 * response body, as opposed to an unexpected/internal error.
 */
class PostbackException extends \RuntimeException
{
}
