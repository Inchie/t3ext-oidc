<?php

declare(strict_types=1);

namespace Causal\Oidc\Tests\Unit\BackChannelLogout;

use Causal\Oidc\BackChannelLogout\LogoutTokenValidator;
use Causal\Oidc\Exception\LogoutTokenRejectedException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogoutTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://idp.example/realms/test';
    private const CLIENT = 't3ext-oidc';
    private const PUBLISHED_KEY_ID = 'published-key';
    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /** RSA key material that exists only for this test run: the key the key set publishes, and one it does not. */
    private static \OpenSSLAsymmetricKey $publishedKey;
    private static \OpenSSLAsymmetricKey $unpublishedKey;

    public static function setUpBeforeClass(): void
    {
        self::$publishedKey = self::generateKey();
        self::$unpublishedKey = self::generateKey();
    }

    private static function generateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        return $key;
    }

    /**
     * @return \Generator<string, array{array<string, mixed>}>
     */
    public static function acceptedClaims(): \Generator
    {
        yield 'aud as string' => [[]];
        yield 'aud as array containing the client' => [['aud' => ['other-client', self::CLIENT]]];
        yield 'nbf in the past' => [['nbf' => time() - 10]];
        yield 'sub present' => [['sub' => 'subject-1']];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[Test]
    #[DataProvider('acceptedClaims')]
    public function validTokenIsAcceptedAndItsSidReturned(array $claims): void
    {
        $sid = (new LogoutTokenValidator())->validate(
            $this->signedToken($claims),
            $this->publishedKeySet(),
            self::ISSUER,
            self::CLIENT,
        );

        self::assertSame('idp-session-1', $sid);
    }

    /**
     * @return \Generator<string, array{claims?: array<string, mixed>, signedWithUnpublishedKey?: bool, keyId?: string|null, algorithm?: string}>
     */
    public static function rejectedSignedTokens(): \Generator
    {
        yield 'invalid signature' => ['signedWithUnpublishedKey' => true];
        yield 'kid not in the key set' => ['keyId' => 'unpublished-key'];
        yield 'kid missing' => ['keyId' => null];
        yield 'alg other than RS256' => ['algorithm' => 'RS384'];
        yield 'wrong iss' => ['claims' => ['iss' => 'https://other-idp.example/realms/test']];
        yield 'wrong aud' => ['claims' => ['aud' => 'other-client']];
        yield 'aud array without the client' => ['claims' => ['aud' => ['other-client']]];
        yield 'expired' => ['claims' => ['exp' => time() - 1]];
        yield 'iat in the future' => ['claims' => ['iat' => time() + 60]];
        yield 'iat in the future beside an nbf in the past' => ['claims' => ['iat' => time() + 60, 'nbf' => time() - 10]];
        yield 'nbf in the future' => ['claims' => ['nbf' => time() + 60]];
        yield 'exp not a number' => ['claims' => ['exp' => (string)(time() + 120)]];
        yield 'iat not a number' => ['claims' => ['iat' => (string)time()]];
        yield 'iss missing' => ['claims' => ['iss' => null]];
        yield 'aud missing' => ['claims' => ['aud' => null]];
        yield 'iat missing' => ['claims' => ['iat' => null]];
        yield 'exp missing' => ['claims' => ['exp' => null]];
        yield 'jti missing' => ['claims' => ['jti' => null]];
        yield 'jti empty' => ['claims' => ['jti' => '']];
        yield 'jti not a string' => ['claims' => ['jti' => 42]];
        yield 'events missing' => ['claims' => ['events' => null]];
        yield 'events not an object' => ['claims' => ['events' => self::LOGOUT_EVENT]];
        yield 'events without the logout event' => ['claims' => ['events' => ['http://schemas.openid.net/event/other' => new \stdClass()]]];
        yield 'logout event not an object' => ['claims' => ['events' => [self::LOGOUT_EVENT => 'logout']]];
        yield 'nonce present' => ['claims' => ['nonce' => 'nonce-1']];
        yield 'sid missing' => ['claims' => ['sid' => null]];
        yield 'sid empty' => ['claims' => ['sid' => '']];
        yield 'sid not a string' => ['claims' => ['sid' => 42]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[Test]
    #[DataProvider('rejectedSignedTokens')]
    public function tokenIsRejected(
        array $claims = [],
        bool $signedWithUnpublishedKey = false,
        ?string $keyId = self::PUBLISHED_KEY_ID,
        string $algorithm = 'RS256',
    ): void {
        $token = $this->signedToken($claims, $signedWithUnpublishedKey ? self::$unpublishedKey : self::$publishedKey, $keyId, $algorithm);

        $this->expectException(LogoutTokenRejectedException::class);

        (new LogoutTokenValidator())->validate($token, $this->publishedKeySet(), self::ISSUER, self::CLIENT);
    }

    /**
     * @return \Generator<string, array{array<string, mixed>}>
     */
    public static function claimsOutsideTheirTime(): \Generator
    {
        yield 'expired' => [['exp' => time() - 10]];
        yield 'iat in the future' => [['iat' => time() + 10]];
        yield 'nbf in the future' => [['nbf' => time() + 10]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[Test]
    #[DataProvider('claimsOutsideTheirTime')]
    public function noLeewayIsAppliedWhereAnotherPartOfTheProcessConfiguredOne(array $claims): void
    {
        JWT::$leeway = 600;
        try {
            $this->expectException(LogoutTokenRejectedException::class);

            (new LogoutTokenValidator())->validate($this->signedToken($claims), $this->publishedKeySet(), self::ISSUER, self::CLIENT);
        } finally {
            JWT::$leeway = 0;
        }
    }

    #[Test]
    public function tokenSignedSymmetricallyWithThePublishedPublicKeyIsRejected(): void
    {
        $keyDetails = openssl_pkey_get_details(self::$publishedKey);
        self::assertIsArray($keyDetails);
        $publicKey = $keyDetails['key'];
        $token = JWT::encode($this->claims(), $publicKey, 'HS256', self::PUBLISHED_KEY_ID);

        $this->expectException(LogoutTokenRejectedException::class);

        (new LogoutTokenValidator())->validate($token, $this->publishedKeySet(), self::ISSUER, self::CLIENT);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function malformedTokens(): \Generator
    {
        $segment = static fn(string $content): string => JWT::urlsafeB64Encode($content);
        $header = $segment('{"alg":"RS256","kid":"published-key"}');
        $payload = $segment('{"sid":"idp-session-1"}');

        yield 'empty' => [''];
        yield 'no segments' => ['not-a-token'];
        yield 'two segments' => [$header . '.' . $payload];
        yield 'four segments' => [$header . '.' . $payload . '.signature.more'];
        yield 'header not JSON' => [$segment('header') . '.' . $payload . '.signature'];
        yield 'header not an object' => [$segment('["RS256"]') . '.' . $payload . '.signature'];
        yield 'payload not JSON' => [$header . '.' . $segment('payload') . '.signature'];
        yield 'payload not an object' => [$header . '.' . $segment('"idp-session-1"') . '.signature'];
        yield 'alg none' => [$segment('{"alg":"none","kid":"published-key"}') . '.' . $payload . '.'];
        yield 'kid empty' => [$segment('{"alg":"RS256","kid":""}') . '.' . $payload . '.signature'];
        yield 'kid not a string' => [$segment('{"alg":"RS256","kid":0}') . '.' . $payload . '.signature'];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function malformedTokenIsRejectedWithoutAKeySet(string $token): void
    {
        $this->expectException(LogoutTokenRejectedException::class);

        (new LogoutTokenValidator())->assertWellFormed($token);
    }

    #[Test]
    public function signedTokenIsWellFormedWithoutAKeySet(): void
    {
        $this->expectNotToPerformAssertions();

        (new LogoutTokenValidator())->assertWellFormed($this->signedToken());
    }

    /**
     * @return \Generator<string, array{array<mixed>}>
     */
    public static function unusableKeySets(): \Generator
    {
        yield 'empty' => [[]];
        yield 'no keys' => [['keys' => []]];
        yield 'keys not a list of keys' => [['keys' => ['published-key']]];
        yield 'another document' => [['error' => 'not_found']];
    }

    /**
     * @param array<mixed> $keySet
     */
    #[Test]
    #[DataProvider('unusableKeySets')]
    public function tokenIsRejectedWhenTheKeySetIsNotAJwkSet(array $keySet): void
    {
        $this->expectException(LogoutTokenRejectedException::class);

        (new LogoutTokenValidator())->validate($this->signedToken(), $keySet, self::ISSUER, self::CLIENT);
    }

    /**
     * @param array<string, mixed> $claims Added to or replacing the claims of a valid logout token; a null value removes a claim
     */
    private function signedToken(
        array $claims = [],
        ?\OpenSSLAsymmetricKey $key = null,
        ?string $keyId = self::PUBLISHED_KEY_ID,
        string $algorithm = 'RS256',
    ): string {
        return JWT::encode($this->claims($claims), $key ?? self::$publishedKey, $algorithm, $keyId);
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function claims(array $claims = []): array
    {
        $claims = array_replace([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT,
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => 'logout-token-1',
            'events' => [self::LOGOUT_EVENT => new \stdClass()],
            'sid' => 'idp-session-1',
        ], $claims);

        return array_filter($claims, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * The JWK Set of the published key, without a declared algorithm.
     *
     * @return array{keys: list<array<string, string>>}
     */
    private function publishedKeySet(): array
    {
        $keyDetails = openssl_pkey_get_details(self::$publishedKey);
        self::assertIsArray($keyDetails);
        $rsa = $keyDetails['rsa'];

        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => self::PUBLISHED_KEY_ID,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]]];
    }
}
