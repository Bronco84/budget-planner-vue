<?php

namespace App\Exceptions;

use Exception;

/**
 * An uploaded file was rejected; the message is safe to show the user.
 */
class FileValidationException extends Exception {}
