<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use RuntimeException;

/**
 * Thrown by a host tool for a problem the model should read and explain:
 * "no item matches", "only 1 left in stock". The message reaches the model
 * as "Error: <message>"; anything else thrown is reported and hidden.
 */
final class ToolRefusal extends RuntimeException {}
