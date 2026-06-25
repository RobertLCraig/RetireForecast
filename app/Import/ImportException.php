<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Thrown when an uploaded file does not match the chosen profile, or the profile is
 * not yet available. Always carries a human reason — imports never fail silently.
 */
final class ImportException extends RuntimeException {}
