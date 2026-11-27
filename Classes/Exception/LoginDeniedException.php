<?php

declare(strict_types=1);

namespace Causal\Oidc\Exception;

/**
 * Its message is the reason of the denial and never carries token content.
 */
class LoginDeniedException extends \RuntimeException {}
