<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

/**
 * What a login keeps of a validated ID token.
 */
final readonly class IdTokenClaims
{
    public function __construct(
        public string $sid,
        public ?string $sub,
    ) {}
}
