<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * The local model could not be reached or errored mid-generation. Thrown rather than
 * swallowed so the assistant reports "not running / couldn't answer" instead of showing a
 * blank or invented reply (no silent failure).
 */
final class AssistantUnavailable extends RuntimeException {}
