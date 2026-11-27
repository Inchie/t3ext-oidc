<?php

declare(strict_types=1);

namespace Causal\Oidc\BackChannelLogout;

use Causal\Oidc\Exception\LogoutTokenRejectedException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Validates a logout token as OpenID Connect Back-Channel Logout 1.0, section 2.6, requires
 * and yields the identity-provider session it ends.
 */
final readonly class LogoutTokenValidator
{
    private const ALGORITHM = 'RS256';
    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /**
     * @param array<mixed> $jwkSet The decoded JWK Set document of the identity provider
     * @return string The `sid` of the logout token
     * @throws LogoutTokenRejectedException
     */
    public function validate(
        #[\SensitiveParameter]
        string $logoutToken,
        array $jwkSet,
        string $issuer,
        string $clientId,
    ): string {
        $this->assertWellFormed($logoutToken);
        $claims = $this->verifySignatureAndTimeClaims($logoutToken, $jwkSet);
        $this->assertIssuedForClientAt($claims, $issuer, $clientId);
        $this->assertIsLogoutNotification($claims);

        $sid = $claims->sid ?? null;
        if (!is_string($sid) || $sid === '') {
            throw new LogoutTokenRejectedException('The logout token carries no sid. (b33a8949-4321-4de3-a86a-a6e9ca81e711)');
        }
        return $sid;
    }

    /**
     * The checks that need no key material: a compact JWS whose header and payload are JSON
     * objects, signed with RS256 by a named key.
     *
     * @throws LogoutTokenRejectedException
     */
    public function assertWellFormed(#[\SensitiveParameter] string $logoutToken): void
    {
        $segments = explode('.', $logoutToken);
        if (count($segments) !== 3) {
            throw new LogoutTokenRejectedException('The logout token is not a compact JWS. (ce38a413-d5ea-4a55-aefd-f0e7e3900530)');
        }
        $header = $this->decodeSegment($segments[0]);
        if ($header === null || $this->decodeSegment($segments[1]) === null) {
            throw new LogoutTokenRejectedException('The logout token header or payload is not a JSON object. (029ed137-af11-4d02-96a3-aa5767823210)');
        }
        if (($header->alg ?? null) !== self::ALGORITHM) {
            throw new LogoutTokenRejectedException('The logout token is not signed with RS256. (65373fb9-25a2-4bb6-8eb6-e97ecfb619fa)');
        }
        $keyId = $header->kid ?? null;
        if (!is_string($keyId) || $keyId === '') {
            throw new LogoutTokenRejectedException('The logout token names no signing key. (6344b735-25f3-4e66-9432-f50a0e5da889)');
        }
    }

    private function decodeSegment(#[\SensitiveParameter] string $segment): ?\stdClass
    {
        try {
            $decoded = JWT::jsonDecode(JWT::urlsafeB64Decode($segment));
        } catch (\Throwable) {
            return null;
        }
        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * @param array<mixed> $jwkSet
     * @throws LogoutTokenRejectedException
     */
    private function verifySignatureAndTimeClaims(#[\SensitiveParameter] string $logoutToken, array $jwkSet): \stdClass
    {
        try {
            $keys = JWK::parseKeySet($jwkSet, self::ALGORITHM);
        } catch (\Throwable $e) {
            throw new LogoutTokenRejectedException('The key set is not a usable JWK Set. (7b0b9f94-eb51-42bd-9946-26c329506d77)', 0, $e);
        }
        try {
            // The library's exceptions carry the token payload and are therefore not chained.
            $claims = JWT::decode($logoutToken, $keys);
        } catch (\Throwable) {
            throw new LogoutTokenRejectedException(
                'The logout token signature does not verify against the key set, or the token is expired or not yet valid. (4a514882-ba23-4e37-b210-ce48868ff5c8)'
            );
        }

        // The library skips a time claim the token does not carry, checks iat only where the token carries
        // no nbf, and applies a leeway any code of the process may set. Here none is applied.
        $now = time();
        $expiration = $claims->exp ?? null;
        if ((!is_int($expiration) && !is_float($expiration)) || $expiration <= $now) {
            throw new LogoutTokenRejectedException('The logout token carries no exp, or has expired. (1ce5a0f6-204c-4242-8735-23ab2ef4e3c7)');
        }
        $issuedAt = $claims->iat ?? null;
        if ((!is_int($issuedAt) && !is_float($issuedAt)) || $issuedAt > $now) {
            throw new LogoutTokenRejectedException('The logout token carries no iat, or one in the future. (d5548e01-a819-46d7-aa59-c78969315646)');
        }
        $notBefore = $claims->nbf ?? null;
        if ($notBefore !== null && ((!is_int($notBefore) && !is_float($notBefore)) || $notBefore > $now)) {
            throw new LogoutTokenRejectedException('The logout token is not valid yet. (93d5d39c-11a1-4fef-9c7e-86f398a1adea)');
        }
        return $claims;
    }

    /**
     * @throws LogoutTokenRejectedException
     */
    private function assertIssuedForClientAt(\stdClass $claims, string $issuer, string $clientId): void
    {
        if (($claims->iss ?? null) !== $issuer) {
            throw new LogoutTokenRejectedException('The logout token was not issued by the configured issuer. (cfe7b705-4ab6-4731-945d-a2d2a5abe43a)');
        }
        $audience = $claims->aud ?? null;
        if ($audience !== $clientId && !(is_array($audience) && in_array($clientId, $audience, true))) {
            throw new LogoutTokenRejectedException('The logout token audience does not name the configured client. (77288257-bed7-4495-b44f-51a314d93297)');
        }
    }

    /**
     * @throws LogoutTokenRejectedException
     */
    private function assertIsLogoutNotification(\stdClass $claims): void
    {
        $tokenId = $claims->jti ?? null;
        if (!is_string($tokenId) || $tokenId === '') {
            throw new LogoutTokenRejectedException('The logout token carries no jti. (90323a8e-d735-4852-8e1d-f96e889df388)');
        }
        $events = $claims->events ?? null;
        if (!$events instanceof \stdClass || !($events->{self::LOGOUT_EVENT} ?? null) instanceof \stdClass) {
            throw new LogoutTokenRejectedException('The logout token carries no back-channel logout event. (8715b1df-e89c-4d80-8f6c-b520f0861525)');
        }
        if (property_exists($claims, 'nonce')) {
            throw new LogoutTokenRejectedException('The logout token carries a nonce. (c5f69eed-8610-441a-bbcc-4a9cc87fa133)');
        }
    }
}
