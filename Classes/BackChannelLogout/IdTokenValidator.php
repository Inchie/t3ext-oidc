<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Causal\Oidc\Exception\LoginDeniedException;
use Firebase\JWT\JWT;

/**
 * Validates the claims of the ID token a login received directly from the token endpoint.
 *
 * The signature is not verified: the token comes from the token endpoint over a trusted
 * transport (OpenID Connect Core 1.0, section 3.1.3.7, step 6).
 */
final readonly class IdTokenValidator
{
    /**
     * @param string|null $idToken The `id_token` value of the token response
     * @throws LoginDeniedException
     */
    public function validate(#[\SensitiveParameter] ?string $idToken, string $issuer, string $clientId): IdTokenClaims
    {
        $claims = $this->readClaims($idToken);

        if (($claims->iss ?? null) !== $issuer) {
            throw new LoginDeniedException('The ID token was not issued by the configured issuer. (6546fce4-d6ac-4b31-896b-7c4ce31a951b)');
        }
        $audience = $claims->aud ?? null;
        if ($audience !== $clientId && $audience !== [$clientId]) {
            throw new LoginDeniedException('The ID token audience does not name only the configured client. (8308dc66-9207-4b37-a4e2-4f8e11f25984)');
        }
        $expiration = $claims->exp ?? null;
        if ((!is_int($expiration) && !is_float($expiration)) || $expiration <= time()) {
            throw new LoginDeniedException('The ID token has expired or carries no expiration time. (4083d686-f1ad-4be9-aec5-638e73281a53)');
        }
        $sid = $claims->sid ?? null;
        if (!is_string($sid) || $sid === '') {
            throw new LoginDeniedException('The ID token carries no sid. (9a5e7593-435e-4e4c-a273-2c58abd27317)');
        }
        $sub = $claims->sub ?? null;

        return new IdTokenClaims(
            sid: $sid,
            sub: is_string($sub) ? $sub : null,
        );
    }

    /**
     * @throws LoginDeniedException
     */
    private function readClaims(#[\SensitiveParameter] ?string $idToken): \stdClass
    {
        if ($idToken === null || $idToken === '') {
            throw new LoginDeniedException('The token response carries no ID token. (e7d542bd-a5e2-45b1-9bf2-8b92b446792f)');
        }
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new LoginDeniedException('The ID token is not a compact JWT. (b9c44b9b-1d0e-448c-b8e8-ab133e9ed911)');
        }
        try {
            $claims = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[1]));
        } catch (\Throwable) {
            $claims = null;
        }
        if (!$claims instanceof \stdClass) {
            throw new LoginDeniedException('The ID token payload is not a JSON object. (e1f8a998-a434-4304-b374-a48c136a6804)');
        }
        return $claims;
    }
}
