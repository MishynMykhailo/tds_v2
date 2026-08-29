<?php

namespace App\Exceptions;

use Exception;

/**
 * Port of legacy `Component\Landings\LocalFile\Validator\IncompatibleLocalFile`
 * (application/Component/Landings/LocalFile/Validator/IncompatibleLocalFile.php).
 * Thrown by LocalFileService::replaceFiles() when an uploaded ZIP fails
 * validation (forbidden file/extension, missing index, forbidden function
 * call, forbidden charset).
 */
class IncompatibleLocalFileException extends Exception
{
}
