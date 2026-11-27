Reference: oidc-back-channel-logout
Repository: webroot/vendor/causal/oidc

# Issue: Externally initiated Single Sign-Out for TYPO3 via OpenID Connect Back-Channel Logout

## Problem

TYPO3 frontend users authenticate through OpenID Connect using the extension `causal/oidc`
(the request names the repository https://github.com/Inchie/t3ext-oidc as added to the IBBW
project). When a customer logs out of the external authentication system (external IDAM) and
Keycloak forwards that logout, TYPO3 is not informed and the customer's TYPO3 frontend sessions
stay alive. The request asks for externally initiated Single Sign-Out in the direction
**External IDAM → Keycloak → TYPO3**, so that a logout upstream ends the corresponding TYPO3
frontend sessions.

## Reported behaviour

- According to the request, `causal/oidc` provides OpenID Connect authentication but does not
  support externally initiated logout.
- A logout at the external IDAM / Keycloak therefore has no effect on existing TYPO3 frontend
  sessions.

## Desired outcome

- When Keycloak forwards a logout notification, TYPO3 invalidates the corresponding frontend
  sessions, using the standardized OpenID Connect Back-Channel Logout mechanism.
- Functional requirements stated by the requester:
  - A reliable association between Keycloak sessions and TYPO3 frontend sessions is established
    during authentication.
  - Back-Channel Logout requests from Keycloak are received.
  - Authenticity and required claims of incoming logout tokens are validated.
  - Only the TYPO3 frontend sessions covered by the validated logout token are invalidated.
  - Unauthorized session invalidation is prevented.
  - Unrelated frontend sessions and authentication mechanisms remain unaffected.
  - Repeated logout notifications are handled safely.
  - Obsolete session associations are cleaned up appropriately.
- The implementation is fully testable without Keycloak, an external IDAM or any external
  network connection: the external OIDC infrastructure is simulated with controlled test
  fixtures and mocked HTTP communication (test signing keys and JWKS fixtures, cryptographically
  signed ID tokens and logout tokens, simulated incoming Back-Channel Logout requests, mocked
  outgoing requests including JWKS retrieval), while the actual production token validation and
  the actual TYPO3 session creation, correlation and invalidation are exercised in functional
  tests.
- Test coverage requested as a minimum:
  - Successful logout: valid ID token during authentication; successful association of Keycloak
    and TYPO3 sessions; valid signed Back-Channel Logout token; successful invalidation of the
    corresponding TYPO3 sessions; multiple concurrent sessions; unrelated sessions remain
    active; repeated logout notifications; logout notification for an already expired or
    removed session.
  - Security and error scenarios: invalid token signature; unknown signing key; unexpected
    signing algorithm; incorrect issuer; incorrect audience; expired token; invalid issuance
    time; missing required claims; invalid logout event claim; prohibited nonce claim; malformed
    token; missing or invalid session identifier; cross-provider and cross-client session
    isolation; failed or unavailable JWKS retrieval; signing key rotation; concurrent login and
    logout operations.
  - The final test requirements are to be derived from the applicable OpenID Connect
    specifications and the approved architectural decisions.
- Final Verification distinguishes clearly between locally verified functionality using
  simulated OIDC infrastructure, functionality verified through real TYPO3 session handling, and
  outstanding integration verification against an actual Keycloak instance and the external
  IDAM.
- A concise integration test checklist is prepared for the later deployment to the Test
  environment.

## Known facts

These are stated explicitly by the requester as scope limits, constraints or intent.

- Direction in scope: External IDAM → Keycloak → TYPO3 only.
- The mechanism to use is OpenID Connect Back-Channel Logout.
- There is currently no connection to a real Keycloak instance in the local development
  environment.
- No automated test may depend on the availability of an external service.
- Security-critical behaviour that the tests are intended to verify must not be mocked.
- The absence of a local Keycloak instance must not result in weakened automated test coverage.
- Successful end-to-end integration with Keycloak or the external IDAM must not be claimed
  until it has actually been tested.
- All implementation, documentation and automated tests must reside in the `causal/oidc` fork
  (https://github.com/Inchie/t3ext-oidc, in the workspace at `webroot/vendor/causal/oidc`).
  Requester-supplied binding constraint, 2026-11-27.
- The IBBW project serves exclusively as the development and integration environment.
  Requester-supplied binding constraint, 2026-11-27.
- No separate BITBW extension is created. Requester-supplied binding constraint, 2026-11-27.
- The explicit decision to implement within the fork supersedes the original request's
  preference for a solution that does not require modifying or maintaining a fork of
  `causal/oidc`. The raw request below keeps the original wording for provenance.
- A separate integration extension is excluded; this is decided (see the constraints above).
- The database model, the session correlation mechanism and the middleware architecture are
  explicitly **not** decided and stay open for Grilling.
- The fork is public. The `Issues/` directory and the durable development records may reside in
  it; committed documentation must not contain confidential BITBW information, internal
  infrastructure details or credentials. Requester-supplied binding constraint, 2026-11-27.
- Extension points, TYPO3 Core APIs and existing project conventions are to be investigated
  before an implementation is proposed.
- Existing cryptographic libraries are to be reused; JWT cryptography is not to be implemented
  manually.
- Only the information required for session correlation and logout may be persisted;
  unnecessary persistence of complete tokens or personal data is to be avoided.
- Out of scope: TYPO3-initiated logout at Keycloak or the external IDAM; RP-Initiated Logout;
  Front-Channel Logout; token revocation unrelated to Back-Channel Logout; configuration of the
  actual Keycloak or external IDAM infrastructure; unrelated changes to existing authentication
  mechanisms; remediation of previously identified authentication security findings that are
  not prerequisites for this implementation.
- Security findings that are prerequisites are to be reported separately during Grilling.
- Approval gates must not be bypassed, and implementation must not begin before the required
  decisions have been approved. Grilling is to discuss exactly one open question at a time.
- The approved solution is to be implemented using test-driven development, followed by all
  applicable verification steps and a security-focused review.

## Assumptions

- The identity provider that sends the logout notification to TYPO3 is Keycloak; the external
  IDAM is not expected to call TYPO3 directly.
- Only TYPO3 **frontend** sessions are concerned; backend sessions are not mentioned in the
  request.

## Unverified claims

The request attributes the first five of the following to "previous source code
investigations" and itself asks that they be verified against the repository and version now
available in the workspace; the last two are further claims the request makes. None of the
seven has been checked during refinement.

- The extension creates independent TYPO3 frontend sessions.
- The ID token is currently not processed or validated.
- The Keycloak session identifier (`sid`) is not persisted.
- No Back-Channel Logout endpoint exists.
- TYPO3 Core provides APIs for invalidating existing frontend sessions.
- `causal/oidc` does not currently support externally initiated logout.
- "Previously identified authentication security findings" exist; the request does not list
  them or say where they are recorded.

## Premature solution assumptions

- Correlation by the Keycloak session identifier (`sid`): the request frames the association as
  session-to-session and lists "missing or invalid session identifier" as an error scenario.
  Whether logout tokens are matched by `sid`, by subject, or both is not decided.
- ID token validation during authentication is treated as part of the solution ("valid ID token
  during authentication"), although the request claims the ID token is currently not processed
  at all. Whether introducing it belongs to this Issue, or is a prerequisite security finding
  to be reported separately, is not decided.
- Remote JWKS retrieval over HTTP with key rotation is presupposed as the way signing keys are
  obtained.
- Persisting a session association is presupposed ("cleanup of obsolete session associations");
  where and how such an association is held is explicitly not approved.
- The enumerated test scenarios are given before the architectural decisions from which, by the
  request's own wording, the final test requirements are to be derived.

## Decisions

- D1: Decision-record discovery for `webroot/vendor/causal/oidc` (identity `causal/oidc`, not a
  member of the global decision scope) enumerated no decision record; none applies.
- D2: All JWT and JWK handling uses `firebase/php-jwt`, which the fork already requires
  (`^7.0.2`) and which ships JWK set parsing; no further cryptographic dependency is added.
  Counter-example: decoding a token payload by hand without signature verification, as
  `GenericOpenIdProvider::fetchResourceOwnerDetails` does today, is not token validation.

- D3: While Back-Channel Logout is enabled for the provider, a frontend login reads the
  `id_token` of the direct token-endpoint response without signature validation (OIDC Core
  §3.1.3.7 step 6: TLS server validation in place of the signature) and requires: `iss` equal to
  the configured issuer, `aud` naming only the configured client, `exp` in the future, a
  non-empty string `sid`, and — where the UserInfo endpoint is used — the UserInfo `sub` equal
  to the ID-token `sub` (OIDC Core §5.3.2). The login is denied when the ID token is missing,
  any of these checks fails, or the session correlation cannot be stored. No JWKS retrieval and
  no signature validation happen at login; no nonce is introduced; the authorization request,
  the user mapping and the login behaviour with Back-Channel Logout disabled are unchanged.
  Counter-example: a login that succeeds without a stored `sid` while Back-Channel Logout is
  enabled.
- D4: The TLS trust of D3 holds only for an HTTPS token endpoint with certificate validation.
  The existing HTTP client does not guarantee either — the scheme comes from `endpointToken`
  and validation from the instance's `HTTP.verify` setting (Core default `true`) — so a login
  with Back-Channel Logout enabled is denied when `endpointToken` is not `https` or
  certificate validation is disabled. No HTTP client infrastructure is added.
- D5: TYPO3 frontend sessions are correlated by `sid` only. Only the `sid` is persisted for
  correlation: no `sub`, no ID token, no further claim. There is no subject-based logout: a
  logout token without `sid` invalidates no session.

- D6: The association is held in a dedicated table owned by `causal/oidc` with two values per
  row: the `sid` (indexed, not unique, so several TYPO3 frontend sessions may share one `sid`)
  and the TYPO3 session identifier encrypted with PHP libsodium authenticated encryption under
  a dedicated key derived from the TYPO3 `encryptionKey` by the fork's existing key derivation
  approach. After successful logout-token validation the rows of that `sid` are decrypted and
  each session is removed immediately through the public TYPO3 session backend API
  (`SessionBackendInterface::remove()` with the session identifier). `ext-sodium` is a required
  dependency of the fork. One implementation serves TYPO3 v13 and v14 and every session
  backend. A decrypted session identifier never appears in a log entry or error message.
  Counter-example: a session identifier stored in plain text or as a backend-specific hash; a
  backend-specific implementation; a check on regular frontend requests; a deferred logout.

- D7: The Back-Channel Logout endpoint is a dedicated PSR-15 frontend middleware of the fork,
  registered following the existing `oidccallback` pattern, at a configurable path with a
  default. It is active only when Back-Channel Logout is configured and acts only on a `POST`
  to exactly that path; every other request passes through unaffected. The logout token is
  validated before any session association is read or any session removed. The signed logout
  token is the only credential: no further authentication mechanism, IP allowlist, rate
  limiter or routing abstraction is added. The restriction of D6 on request middleware
  concerns session checks on regular frontend requests only, not this endpoint.

- D8: Back-Channel Logout is configured when the provider carries an issuer and a JWKS URL; the
  JWKS URL is an explicit provider configuration value (no OIDC discovery) and falls under the
  HTTPS and certificate-validation rule of D4. A logout token is accepted only when its `iss`
  equals the configured issuer and its `aud` names the configured client (`clientKey`); the
  fork runs one provider, so no provider or client data is stored with an association.
- D9: The JWKS is retrieved from the configured URL for each logout-token validation, through
  TYPO3's existing HTTP client with bounded timeouts, and evaluated with `firebase/php-jwt`.
  `RS256` is the only accepted signature algorithm. A request that is recognisably malformed
  without key material is rejected before any outbound request. The logout request is rejected
  when key retrieval or token validation fails. There is no JWKS caching, no key-rotation
  handling, no refresh marker, no rate limiting and no additional persistence; key management
  and rotation are the identity provider's responsibility, and the additional HTTP request and
  the dependency on the JWKS endpoint's availability are accepted.

- D10: The endpoint answers as Back-Channel Logout 1.0 §2.8 states, with `Cache-Control:
  no-store` and no response body: HTTP 200 when the associated sessions were removed or no
  associated session remains (unknown `sid`, repeated notification, session already expired or
  logged out); HTTP 400 for an invalid request, an invalid token — a token without `sid`
  included — a failed key retrieval or a failed logout operation. A session association is
  deleted only after its session was removed; a session the backend no longer holds counts as
  removed. There is no `jti` persistence and no replay tracking, no further status code, no
  retry mechanism and no logging infrastructure; tokens and session identifiers are never
  logged.

- D11: Back-Channel Logout is event-driven, not continuous session synchronisation. Repeated
  and concurrent logout notifications are processed idempotently. The session association is
  persisted within the login request, and the login is denied when that fails. A logout
  notification arriving between token retrieval and the persisting of the association is a
  known, accepted limitation. No logout marker, additional persistence, locking,
  synchronisation mechanism, retry infrastructure, or periodic or per-request verification of
  the external session state is introduced; guaranteed synchronisation of external and local
  session state is out of scope.

- D12: Obsolete session associations are removed by one console command of `causal/oidc`: per
  row it decrypts the stored session identifier, asks the public TYPO3 session API whether the
  session still exists, and removes the association only when it does not; an association is
  preserved when accessing the session backend fails. The command is scheduled with TYPO3's
  existing mechanisms for console commands, and the command and its intended periodic
  execution are documented. No dedicated Scheduler task, automatic scheduling, configurable
  interval, additional lookup column or cleanup logic during login is introduced.

- D13: The fork gains a functional test suite on the existing TYPO3 Testing Framework with
  SQLite as database. External HTTP communication (token endpoint, UserInfo, JWKS) is mocked
  through TYPO3's existing `HTTP.handler` mechanism; test tokens are cryptographically signed
  with key material generated at test runtime, and no key material is committed. The actual
  token validation, encryption, session persistence and session invalidation are exercised,
  not mocked. The existing CI matrix is extended with the functional suite; the existing
  Playwright setup is unchanged. No separate mock server, generic OIDC testing framework or
  further test abstraction is introduced.
- D14: `ext-openssl` is a required dependency of the fork (RS256 verification in
  `firebase/php-jwt`). A session association that cannot be decrypted is never deleted
  automatically: a decryption failure does not establish that the session no longer exists.
  The failure is logged without sensitive data and the association is preserved — by the
  cleanup command of D12 and by logout-token processing alike, where a session that cannot be
  removed for that reason is a failed logout operation in the sense of D10.

- D15: The minimum test requirements are the following observable behaviours. Isolated
  token-validation cases are unit tests with data providers; functional tests are used where
  TYPO3 integration, database persistence or session handling must be verified; coverage is
  not duplicated across layers, no generic test framework is introduced, and production
  functionality is not expanded for tests. The accepted limitations of D9 and D11 get no test.
  - Login: valid ID token → login succeeds and an association with an encrypted session
    identifier exists; missing ID token, wrong `iss`, wrong `aud`, expired ID token, missing
    `sid`, UserInfo `sub` differing from ID-token `sub`, non-HTTPS token endpoint or JWKS URL,
    disabled certificate validation, or a failed association write → login denied, no session
    and no association; Back-Channel Logout not configured → login as before, no association.
  - Successful logout: valid signed logout token → session removed from the backend,
    association deleted, HTTP 200 with `Cache-Control: no-store` and empty body; several
    sessions on one `sid` all removed; sessions of other `sid`s and non-OIDC sessions stay
    active; repeated notification and notification for an expired or locally logged-out
    session → HTTP 200.
  - Rejected logout, HTTP 400 and no session touched: invalid signature; unknown `kid`;
    algorithm other than `RS256`; malformed token; wrong method or content type; wrong `iss`;
    wrong `aud` (the cross-provider and cross-client isolation case); expired; `iat` in the
    future; missing required claim; invalid `events`; `nonce` present; missing or non-string
    `sid`; failed or timed-out JWKS retrieval; an association that cannot be decrypted, which
    is preserved. A recognisably malformed request causes no outbound JWKS request.
  - Keys: a token signed with a key newly published in the JWKS is accepted on the next
    request; a token signed with a key no longer published is rejected.
  - Concurrency, as deterministic step-ordered tests: two notifications for one `sid` both
    succeed; a removed session is not restored as a logged-in session by a later session write.
  - Storage and cleanup: the stored value differs from the session identifier and decrypts to
    it; no session identifier or token appears in log output; the cleanup command removes
    associations of vanished sessions and keeps those of live sessions, those whose backend
    access failed and those that cannot be decrypted.

- D16: The fork's `README.md` gains a Back-Channel Logout section holding the configuration,
  the cleanup command and its periodic execution, the known limitations (D9, D11) and the
  integration checklist for a deployment against a real identity provider. It is generic and
  names no deployment-specific host, realm, client or credential. The checklist covers the
  identity-provider prerequisites (back-channel logout URL registered for the client, logout
  tokens and ID tokens carrying `sid`, `RS256` signatures, mutual network reachability over
  HTTPS with trusted certificates, synchronised clocks, an upstream logout ending the
  identity-provider session), the TYPO3 prerequisites (issuer and JWKS URL configured,
  certificate validation enabled, `sodium` and `openssl` loaded, schema updated, cleanup
  command scheduled) and the checks: login creates an association; upstream logout and direct
  identity-provider logout each end the TYPO3 session immediately with HTTP 200; only the
  sessions of the logged-out identity-provider session end, and all of them; logout after a
  local TYPO3 logout answers HTTP 200; non-OIDC logins are unaffected; logout works after a
  key rotation; an unreachable JWKS URL yields HTTP 400 with the session kept; the cleanup
  command removes only associations of vanished sessions; login works as before with
  Back-Channel Logout unconfigured. Final verification reports every checklist item as
  outstanding until it was run against a real identity provider.
- D17: Of the authentication security findings established in Grilling, two are prerequisites
  and are covered by this effort: the ID token is never read (D3) and no issuer or JWKS
  configuration exists (D8). Not prerequisites, out of scope and unchanged: the unsigned
  decoding of the access-token payload as user identity when no UserInfo endpoint is
  configured, the absence of a `nonce`, the `disableCSRFProtection` option, and access tokens
  written to the debug log. No earlier record of authentication security findings was found in
  the Issue or the fork.

- D18: Resolves the contradiction between D7 and D15 on a request to the Back-Channel Logout
  path whose method is not `POST`. D7 stands: such a request is not handled by the endpoint and
  passes through unaffected — no session is touched, no session association is read and no
  outbound JWKS request is made. D18 supersedes only the "wrong method" item of D15's
  "Rejected logout, HTTP 400" list: the minimum test for a wrong method asserts the
  pass-through instead of HTTP 400. Every other item of D15, the wrong content type on a
  `POST` included, is unchanged.

- D19: The session association is created only after the Core has persisted the authenticated
  TYPO3 session, with that session's final identifier — never in the authentication step that
  precedes the persisting. Where storing the association fails, the newly created session is
  invalidated and the authentication does not remain effective. No timestamp, grace period,
  locking or other mechanism compensates for the order. D19 supersedes the order in which D3
  and D11 were materialised (association stored in the authentication step, a failure reported
  as authentication failure so that no session is created); their requirements — the
  association is persisted within the login request, and a login whose association cannot be
  stored does not stay logged in — are unchanged. Found in Implementation of ticket 03: an
  association stored before its session exists is removed by the cleanup command of D12 when
  the command runs in between.

- D20: The session association of D19 is persisted in a listener of the Core's PSR-14
  `AfterUserLoggedInEvent`, without a fallback mechanism and without the `postUserLookUp` hook.
  While Back-Channel Logout is configured, the fork authenticates a frontend user through
  OpenID Connect only on an explicit frontend login request (`logintype=login`), which every
  documented and tested login flow of the fork sends; OIDC authentication without an explicit
  login request is incidental behaviour and is not supported together with Back-Channel
  Logout. The Core settings `FE_alwaysFetchUser` and `FE_fetchUserIfNoSession` are not
  restricted, and with Back-Channel Logout not configured the authentication behaves as before.

- D21: Where storing the session association fails and the session backend then also fails to
  remove the session, the fork cannot guarantee that the session is invalidated in the backend;
  this is an accepted, documented technical limitation. In that case the failure is logged
  without sensitive information, the current request is anonymous and the session cookie is
  removed. A later request of a client that kept the cookie of that session may still be
  logged in; the functional test asserts this observed behaviour. No retry, alternative session
  mutation or additional infrastructure is introduced. D21 qualifies D19 for this case only.

## Verification

Final verification, 2026-11-27, second run, after the return to Implementation for findings F1
and F2 of the first run (summarised at the end of this section). Specification
`Work/oidc-back-channel-logout/spec.md`; tickets 01, 02 and 03, each with `Status: implemented`.
One affected target repository. This record replaces the record of the first run.

**Effort outcome: passed.** Every declared check that can run passes on TYPO3 14.3 and 13.4; F1
and F2 are resolved; no finding introduced by the effort remains. The pre-existing defect F3
(static analysis as declared aborts on a stale baseline entry) is outside the effort and
unchanged. No end-to-end integration with a real identity provider is claimed; every item of
the README integration checklist is outstanding.

### Repository `webroot/vendor/causal/oidc` — passed

Verified revision: `cbbf61c15d7404913fbb121cb5ac9fc04d220224` (five commits ahead of
`origin/master`), working tree clean apart from the untracked `Issues/`. Effort baseline:
`d808bce2e8a90e278b9b7389594d0486756300f7` (ticket 01; the baselines of tickets 02 and 03,
`cde4d33` and `972f50f`, descend from it, ordered with `git merge-base --is-ancestor`). Changed
files: 37 (2927 insertions, 1 deletion), every one named by a ticket's `Changed:` line. Outside
the effort: `Issues/` (untracked, `Pre-existing changes:` of all three tickets).

Checks, run inside the fork's standalone environment (PHP 8.2.29, PHPUnit 11.5.58, SQLite, the
tests make no network access), invoked as the fork's CI workflow declares them unless stated:

| Check | Invocation | Result |
|---|---|---|
| Unit suite, TYPO3 14.3.12 | `.Build/vendor/bin/phpunit --configuration Tests/UnitTests-v10.xml --testdox` | OK, 59 tests, 98 assertions |
| Functional suite, TYPO3 14.3.12 | `.Build/vendor/bin/phpunit --configuration Tests/FunctionalTests.xml --testdox` | OK, 60 tests, 386 assertions |
| Unit suite, TYPO3 13.4.40 | same, in a copy of the tracked files after `composer require --dev --prefer-dist --no-progress "typo3/minimal:^13"` | OK, 59 tests, 98 assertions |
| Functional suite, TYPO3 13.4.40 | same copy | OK, 60 tests, 386 assertions; the same 60 cases as on 14.3 |
| Coding guideline | `PHP_CS_FIXER_IGNORE_ENV=1 .Build/vendor/bin/php-cs-fixer fix --dry-run --diff` | 0 of 71 files to fix |
| PHP lint | `find *.php Classes Configuration Tests -name '*.php' -print0 \| xargs -0 -n 1 -P 4 php -l` | 69 files, no syntax error |
| Composer manifest | `composer validate` | valid |
| PHPUnit configuration lint | schema validation of `Tests/UnitTests-v10.xml` and `Tests/FunctionalTests.xml` against `.Build/vendor/phpunit/phpunit/phpunit.xsd` with PHP's `DOMDocument::schemaValidate` (`xmllint` not available) | both valid |
| Static analysis, as declared | `phpstan analyse --no-progress` (2.3.7, tool set of `scripts/phpstan.sh`, `phpstan.dist.neon`) | aborts before analysing (F3, pre-existing) |
| Static analysis, F3 neutralised | same with `reportUnmatchedIgnoredErrors: false` in an including configuration outside the repository | 10 errors; 34 at the effort baseline; none exists only in the verified revision |

Findings:

- **F1 — resolved.** `BackChannelLogoutCleanupCommandTest::commandCanBeScheduled` reads the
  schedulable commands through `CommandRegistry::getSchedulableCommands()`, which TYPO3 13.4 and
  14.3 both have; the assertion (the key `oidc:backchannel:cleanup` is among them) is unchanged.
  The case passes on 13.4.40 and 14.3.12. No production file changed for it.
- **F2 — resolved.** The six phpstan errors of the first run are gone. Compared by file and
  message, the verified revision has no error the baseline lacks. The 24 errors only the
  baseline has are all "Cannot call method …() on Causal\Oidc\Model\Provider|false" in
  `Classes/OidcConfiguration.php`, removed by `assert($provider instanceof Provider)` in front
  of the block (instruction of 2026-11-27, ticket 01). The 10 remaining errors are the
  baseline's own: `Classes/Factory/RequestFactory.php:36`, `Classes/Model/Provider.php:100`,
  `:109`, `:116`, `:120`, `Classes/Security/JwtTrait.php:75`,
  `Classes/Service/AuthenticationService.php:290`, `:321` (two dead catches),
  `Classes/Updates/PluginUpdater.php:257`, `Tests/Unit/AbstractUnitTestBase.php:61`.
  `phpstan-baseline.neon` and `phpstan.dist.neon` are unchanged since the baseline; nothing was
  suppressed.
- **F3 — pre-existing, outside the effort, unresolved, non-blocking.** phpstan as declared
  aborts: `phpstan-baseline.neon` (unchanged since the baseline revision) names
  `Classes/Frontend/FrontendSimulationV13.php`, a file that does not exist. The CI workflow has
  no static-analysis job. A matter for a separate effort, as are the 10 pre-existing errors.

Deviations and skipped checks:

- The CI workflow was not executed: the branch is unpushed. PHP 8.3, 8.4 and 8.5 were not run;
  TYPO3 13 was run on PHP 8.2 only, in a copy of the tracked files, with the newest 13.4
  release rather than a pinned one. Six of the eight matrix entries are therefore unverified
  until CI runs.
- The Playwright acceptance setup under `Build/` was not run; the effort did not change it.
- `xmllint` and the container-based `scripts/phpstan.sh` were replaced by the equivalent
  invocations named in the table.
- phpstan at the baseline analysed the exported baseline sources with the dependencies and the
  class map of the current installation; types of classes referenced across files may therefore
  resolve to the current sources. The comparison of the error sets by file and message is
  unaffected for the files the effort changed.
- Test-first implementation and the security-focused review the Issue's Known facts require are
  evidenced by the tickets' outcome records (a full code review per ticket, three rounds on
  ticket 03), not re-performed here. The fixes made after the reviews of tickets 01 and 02 and
  the four files changed for F1 and F2 were not reviewed again.
- The corrections for F1 and F2 were made for all three tickets in one session without a context
  reset between them, on the instruction of 2026-11-27 — a deviation from the
  one-ticket-per-session rule, recorded in ticket 03.
- Limits recorded by the tickets and unchanged: the password-credentials and request-path login
  variants have no functional case of their own (shared code path, established by reading);
  the failure path of the association stored in the authentication step for a kept session of
  the same user is untested; two concurrent notifications are tested as the step orders
  reachable through the request seam, not interleaved; "timing out" is a transport failure
  raised through `HTTP.handler` with the timeouts asserted as request options; a failing
  session backend is a fixture subclass of the Core's backend; the registration of the command
  through `#[AsCommand]` is exercised by the functional suite on both TYPO3 versions only.

What the checks establish, reported separately as the Specification requires:

- **Verified with simulated OIDC infrastructure** (token endpoint, UserInfo endpoint and JWKS URL
  answered through `HTTP.handler`; RSA keys generated at test runtime; tokens really signed):
  the ID-token checks at login and every denial (wrong `iss`, wrong `aud`, a further client in
  `aud`, expired, `exp` not a number, missing or non-string `sid`, differing UserInfo `sub`,
  missing ID token); the transport rule for `endpointToken` and `endpointJwks` and for
  `HTTP.verify`; the logout-token validation matrix (52 unit cases on the concrete class); the
  endpoint's request-form checks, status codes, headers, key retrieval failures (server error,
  timeout, redirect, body not JSON, body not a JWK Set) and key publication and withdrawal; that
  no JWKS request is made at login and none for a malformed logout request or over an untrusted
  transport.
- **Verified through real TYPO3 session handling** (full frontend middleware stack, the Core's
  frontend authentication and database session backend, the real association table and the real
  encryption, on TYPO3 14.3.12 and 13.4.40): a login persists a session and one association
  that decrypts to the identifier the backend holds; no association where the Core cannot
  persist the session; a failed association write ends the login, also in an existing session;
  the double failure of D21 behaves as documented, the later request with the kept cookie
  asserted as logged in; a request without `logintype=login` does not authenticate with
  Back-Channel Logout configured and authenticates as before without it; a repeated login in an
  existing session leaves only the association of the latest login; a logout removes exactly
  the sessions of the `sid` and their associations; other `sid`s and non-OIDC sessions stay;
  repeated and late notifications answer 200; a removed session is not restored by a later
  session write; an undecryptable association and a failing session backend answer 400 with
  the association kept; a request with another method, a request to another path and a `POST`
  with the feature not configured pass through; the cleanup command removes only associations
  of vanished sessions and is schedulable; no token or session identifier appears in log or
  command output.
- **Outstanding — not verified, no end-to-end integration is claimed:** everything against a
  real identity provider or the external identity management system. Every item of the README
  integration checklist is outstanding:
  - Identity provider: back-channel logout URL registered for the client; logout tokens and ID
    tokens carry `sid`; tokens signed with `RS256`; mutual reachability over HTTPS with trusted
    certificates; synchronised clocks; an upstream logout ends the identity-provider session.
  - TYPO3: `issuer` and `endpointJwks` configured; certificate validation enabled; `sodium` and
    `openssl` loaded; database schema updated; cleanup command scheduled.
  - Checks: a login creates an association; an upstream logout ends the TYPO3 session
    immediately with 200; a direct identity-provider logout does the same; only the sessions of
    the logged-out identity-provider session end, and all of them; a logout after a local TYPO3
    logout answers 200; logins through other mechanisms are unaffected; logout works after a
    key rotation; an unreachable `endpointJwks` yields 400 with the session kept; the cleanup
    command removes only associations of vanished sessions; login works as before with
    Back-Channel Logout unconfigured.

Acceptance criteria across ticket boundaries: every criterion of tickets 01, 02 and 03 maps to
a named case of the suites run above on the verified revision, including the criteria of ticket
01 after its correction by D19 and D20 in ticket 03; all hold together on TYPO3 14.3 and on
TYPO3 13.4. Ticket 01 criterion 7 holds as far as it can be shown locally: the workflow runs the
functional suite after the unit suite in the `tests` job for every matrix entry, and both suites
pass for the two entries run here; the workflow itself was not executed. The documentation
criteria of ticket 03 were verified by reading the README and changelog diff against the list of
D16: three keys with defaults, endpoint behaviour with its two status codes, cleanup command
with its periodic execution, the limitations of D9, D11 and D21, 6 identity-provider
prerequisites, 5 TYPO3 prerequisites and 10 checks; one changelog entry.

Binding set, checked against the diff to the baseline:

- Additive only: the single deleted line is the CI `xmllint` step, replaced by the same step
  naming both configurations; no file was deleted or renamed; no existing class, method,
  service, configuration key or schema element was removed or changed. The one change inside
  existing logic is the assertion in `OidcConfiguration`, which changes no runtime behaviour.
- `composer.json`: `ext-openssl` and `ext-sodium` added, nothing else.
- `tx_oidc_backchannel_session`: two `varchar(255)` not-null columns, primary key over both, no
  TCA. The endpoint middleware `oidcbackchannellogout` is registered after
  `typo3/cms-core/normalized-params-attribute` and before `typo3/cms-frontend/eid`, as
  `oidccallback` is. The association is stored in a listener of `AfterUserLoggedInEvent`; the
  diff registers no hook, Scheduler task, cache or further middleware.
- Key retrieval: connect timeout, total timeout and `allow_redirects: false` set on the one
  request; no interface was introduced for the logout-token validation.
- Public fork: `Issues/` is untracked; the added lines name no deployment-specific host, realm,
  client or credential (test fixtures use `idp.example` and `website.example`), no key material
  is committed, and the five commit messages carry no confidential detail.
- IBBW project: its working tree carries uncommitted changes unrelated to this effort; none of
  them names an OIDC, issuer, JWKS or back-channel setting. Composer ran inside the fork and in
  a temporary copy of the fork only.

Decision IDs covered: D1–D21. D6 and D13 ("one implementation serves TYPO3 v13 and v14", one
suite on the existing matrix) are covered on both TYPO3 versions with PHP 8.2. D16 is covered as
documentation read against its list; its checklist items are outstanding. The limitations of D9
and D11 carry no test, as D15 states. None could not be covered.

Decision records checked: none applies. `sh .ai-rules/bin/discover-decision-records .
webroot/vendor/causal/oidc webroot/vendor/causal/oidc` (2026-11-27, exit 0) reported identity
`causal/oidc`, `global-scope: not-member` and enumerated no record — matching D1, the
Specification and the three ticket outcomes. No violation to classify.

What remains: nothing for this effort. Outside it: the integration checklist against a real
identity provider, the CI run for the remaining matrix entries once the branch is pushed, and
F3 with the 10 pre-existing phpstan errors as a separate effort.

### Accessibility review

Tier: 0 — no frontend-relevant file changed (triage, 19 ms)

### First run, 2026-11-27 — failed

Verified revision `a0113e344fc35be732ad605e58e4378c9eae40cb`, same baseline. Unit and functional
suites passed on TYPO3 14.3.12 (59 and 60 tests); coding guideline, PHP lint, manifest and
PHPUnit configuration lint passed. Failed with:

- F1 (blocking): `BackChannelLogoutCleanupCommandTest::commandCanBeScheduled` called
  `CommandRegistry::getSchedulableCommandsConfiguration()`, which TYPO3 13.4.40 does not have;
  the functional suite failed there with 1 error of 60 tests.
- F2 (introduced by the effort): six phpstan errors — `Classes/OidcConfiguration.php:130–132`,
  `Tests/Functional/AbstractFunctionalTestBase.php:197`,
  `Tests/Unit/BackChannelLogout/LogoutTokenValidatorTest.php:149` and `:257`.
- F3 (pre-existing): as above.

Returned to Implementation on the instruction of Timo Nussbaum, 2026-11-27 (tickets 01, 02 and
03, "Return from Final verification"); corrected in commit `cbbf61c`, four files.

## Routing

- Result: owning repository `webroot/vendor/causal/oidc`.
- Basis: human routing decision — a one-off decision that routes only this request; the routing
  map is unchanged.
- Deviation: the owning repository is not among the candidates repository discovery emitted.
  Discovery enumerates the workspace root and the package entries of the `vendor/bitbw` marker
  tree only, so a repository at `webroot/vendor/causal/oidc` cannot be discovered. The human
  routing decision was given in knowledge of this limitation; the discovery mechanism is
  unchanged.
- Supporting artifacts: none registered with this request.

## Raw request (verbatim)

# Intake: Externally initiated Single Sign-Out for TYPO3

Use the project's established AI Development Workflow and start with `/intake`.

## Context

The following repository has been added to the IBBW project:

https://github.com/Inchie/t3ext-oidc

The extension `causal/oidc` provides OpenID Connect authentication but does not currently support externally initiated logout.

Previous source code investigations established that:

- The extension creates independent TYPO3 frontend sessions.
- The ID token is currently not processed or validated.
- The Keycloak session identifier (`sid`) is not persisted.
- No Back-Channel Logout endpoint exists.
- TYPO3 Core provides APIs for invalidating existing frontend sessions.
- Existing extension events may allow the required functionality to be implemented without modifying `causal/oidc`.

Verify these findings against the actual repository and version now available in the workspace.

## Objective

Implement externally initiated Single Sign-Out in the following direction:

**External IDAM → Keycloak → TYPO3**

When a customer logs out of the external authentication system and Keycloak forwards the logout notification, TYPO3 must invalidate the corresponding frontend sessions.

Use the standardized OpenID Connect Back-Channel Logout mechanism.

## Functional requirements

- Establish a reliable association between Keycloak sessions and TYPO3 frontend sessions during authentication.
- Receive Back-Channel Logout requests from Keycloak.
- Validate the authenticity and required claims of incoming logout tokens.
- Invalidate only the TYPO3 frontend sessions covered by the validated logout token.
- Prevent unauthorized session invalidation.
- Ensure unrelated frontend sessions and authentication mechanisms remain unaffected.
- Handle repeated logout notifications safely.
- Ensure appropriate cleanup of obsolete session associations.

## Local development constraints

There is currently no connection to a real Keycloak instance in the local development environment.

The implementation must therefore be fully testable without Keycloak, an external IDAM or any external network connection.

Simulate the external OIDC infrastructure using controlled test fixtures and mocked HTTP communication.

In particular:

- Generate test signing keys and corresponding JWKS fixtures.
- Generate cryptographically signed ID tokens and logout tokens.
- Simulate incoming Back-Channel Logout HTTP requests.
- Mock outgoing HTTP requests to external endpoints, including JWKS retrieval.
- Exercise the actual production token-validation implementation.
- Exercise the actual TYPO3 session creation, correlation and invalidation mechanisms in functional tests.

Do not mock the security-critical behaviour that the tests are intended to verify.

No automated test may depend on the availability of an external service.

## Required test coverage

Cover at least the following scenarios:

### Successful logout

- Valid ID token during authentication.
- Successful association of Keycloak and TYPO3 sessions.
- Valid signed Back-Channel Logout token.
- Successful invalidation of the corresponding TYPO3 sessions.
- Multiple concurrent sessions.
- Unrelated sessions remain active.
- Repeated logout notifications.
- Logout notification for an already expired or removed session.

### Security and error scenarios

- Invalid token signature.
- Unknown signing key.
- Unexpected signing algorithm.
- Incorrect issuer.
- Incorrect audience.
- Expired token.
- Invalid issuance time.
- Missing required claims.
- Invalid logout event claim.
- Prohibited nonce claim.
- Malformed token.
- Missing or invalid session identifier.
- Cross-provider and cross-client session isolation.
- Failed or unavailable JWKS retrieval.
- Signing key rotation.
- Concurrent login and logout operations.

Derive the final test requirements from the applicable OpenID Connect specifications and the approved architectural decisions.

## Architectural constraints

Prefer a solution that does not require modifying or maintaining a fork of `causal/oidc`.

However, do not treat a separate integration extension, additional database table or specific middleware architecture as an approved decision.

Investigate the available extension points, TYPO3 Core APIs and existing project conventions before proposing an implementation.

Reuse existing cryptographic libraries rather than implementing JWT cryptography manually.

Persist only the information required for session correlation and logout. Avoid unnecessary persistence of complete tokens or personal data.

## Out of scope

- TYPO3-initiated logout at Keycloak or the external IDAM.
- RP-Initiated Logout.
- Front-Channel Logout.
- Token revocation unrelated to Back-Channel Logout.
- Configuration of the actual Keycloak or external IDAM infrastructure.
- Unrelated changes to existing authentication mechanisms.
- Remediation of previously identified authentication security findings that are not prerequisites for this implementation.

Report any security findings that are prerequisites separately during Grilling.

## Workflow

1. Inspect the workspace, repository versions, project rules and applicable ADRs.
2. Create the Intake using the established workflow.
3. Identify open architectural and functional questions.
4. Proceed through Grilling, discussing exactly one open question at a time.
5. Obtain the required human approvals.
6. Create the Specification and implementation tickets.
7. Implement the approved solution using test-driven development.
8. Execute all applicable verification steps and perform a security-focused review.
9. Complete Final Verification.

Do not bypass approval gates or begin implementation before the required decisions have been approved.

## Final verification constraints

Clearly distinguish between:

- Verified local functionality using simulated OIDC infrastructure.
- Functionality verified through real TYPO3 session handling.
- Outstanding integration verification against an actual Keycloak instance and the external IDAM.

The absence of a local Keycloak instance must not result in weakened automated test coverage.

Prepare a concise integration test checklist for the later deployment to the Test environment.

Do not claim successful end-to-end integration with Keycloak or the external IDAM until it has actually been tested.

Start with the Intake.

## Comments

- 2026-11-27, Timo Nussbaum — routing decision: "Owning repository: webroot/vendor/causal/oidc.
  Approve R1 as a one-off human routing decision. Record the deviation caused by the repository
  discovery limitation. Do not modify the routing map or repository discovery mechanism."
- 2026-11-27, Timo Nussbaum — constraints: "All implementation, documentation and automated
  tests must reside in the causal/oidc fork. The IBBW project serves exclusively as the
  development and integration environment. Do not create a separate BITBW extension. The
  explicit decision to implement within the fork supersedes the original request's preference
  to avoid fork modifications."
- 2026-11-27, Timo Nussbaum — public fork: "The Issues/ directory and durable development
  records may reside within the causal/oidc fork. Since this is a public fork, ensure that
  committed documentation does not contain confidential BITBW information, internal
  infrastructure details or credentials."
- 2026-11-27, Timo Nussbaum — scope of open decisions: "Explicitly exclude a separate
  integration extension, as this decision has already been made. Keep the database model,
  session correlation mechanism and middleware architecture open for Grilling."
- 2026-11-27, Timo Nussbaum — records and authorization: "The causal/oidc fork at
  webroot/vendor/causal/oidc is an authorized development repository within this project. The
  fork remains the owning repository for the Back-Channel Logout effort. All implementation,
  automated tests and product documentation belong in the fork. Internal workflow records must
  not become part of the public Git history. Existing approval gates and provenance
  requirements remain unchanged."
- 2026-11-27, Timo Nussbaum — local records and Composer: "Keep Issues/ and internal workflow
  records untracked in the public fork. Accept the lack of Git history for these local workflow
  records as an explicit project-specific deviation. Always use explicit file paths when
  proposing git add commands. Approve Composer installation exclusively inside the causal/oidc
  fork for its standalone development and testing environment. Do not execute Composer in the
  IBBW project or modify its dependencies."
- 2026-11-27, Timo Nussbaum — Intake approval: "I approve this Intake"
- 2026-11-27, Timo Nussbaum — Grilling approval: "I approve the consolidated Grilling
  decisions D1–D17. The Specification must remain strictly within the approved scope and follow
  the Slim-first principle. Derive technical details independently from the approved decisions,
  existing source code and applicable specifications. Do not reopen settled decisions or
  introduce additional abstractions, optimizations or functionality."
- 2026-11-27, Timo Nussbaum — D18 approval (Specification, contradiction D7 / D15 on a
  non-`POST` request to the Back-Channel Logout path): "Pass through (Recommended)"
- 2026-11-27, Timo Nussbaum — Specification approval: "I approve the Specification, including
  D18. Do not introduce a validator interface solely for testing purposes. Prefer testing the
  concrete implementation using the already approved HTTP mocking mechanism. Proceed with
  Tickets. Keep the number of tickets proportionate to the actual implementation scope. Do not
  create a separate ticket for every decision, class or technical detail. Do not reopen settled
  decisions or expand the approved functionality."
- 2026-11-27, Timo Nussbaum — Tickets approval: "I approve all three tickets as drafted. Keep
  the proposed dependencies and test strategy unchanged. Implement strictly within the approved
  scope. Prefer the smallest correct implementation and avoid unnecessary abstractions,
  refactoring or additional functionality. Resolve ordinary implementation details
  independently."
- 2026-11-27, Timo Nussbaum — D19 approval (Implementation stop, ticket 03, association stored
  before the session is persisted): "Option 2 is rejected. Persisting the association before
  TYPO3 has successfully persisted the authenticated session is conceptually incorrect. Our
  extension cannot control what happens between authentication and session persistence. The
  association must be created only after the actual authenticated TYPO3 session has been
  successfully persisted, using its final session identifier. Investigate the most appropriate
  existing TYPO3 extension point for this purpose and correct the implementation of ticket 01.
  If association persistence fails, invalidate the newly created session and ensure that
  authentication does not remain effective. Do not introduce timestamps, grace periods, locking
  or other mechanisms to compensate for an incorrect integration point. Keep the existing
  functional requirements unchanged. This is a correction of the implementation, not an
  expansion of scope. Verify the corrected behaviour with regression tests and continue with
  ticket 03."
- 2026-11-27, Timo Nussbaum — D20 approval (Implementation, ticket 03, integration point of the
  session association): "Option C is approved. Use the PSR-14 AfterUserLoggedInEvent as the
  integration point for persisting the OIDC session association. When Back-Channel Logout is
  configured, require an explicit frontend login request (logintype=login) for OIDC
  authentication. The investigation has demonstrated that all documented and tested OIDC login
  flows already satisfy this requirement. Authentication without an explicit login request is
  incidental behaviour and does not need to be supported by Back-Channel Logout. Requirements:
  Remove the postUserLookUp hook and its registration. Use the existing PSR-14 event without
  introducing a fallback mechanism. Persist the association using the final, successfully
  persisted session identifier. Ensure that failed association persistence does not leave an
  effective authenticated session or authenticated request context. Preserve existing
  authentication behaviour when Back-Channel Logout is disabled. Do not restrict the global
  TYPO3 settings FE_alwaysFetchUser or FE_fetchUserIfNoSession. Preserve all regular OIDC login
  flows. Verify the remaining double-failure scenario. Update the Specification and affected
  tickets to reflect the approved target state. Prefer the smallest correct implementation. Do
  not introduce additional infrastructure, compatibility layers or unrelated security fixes.
  Run the relevant regression tests before reporting completion."
- 2026-11-27, Timo Nussbaum — D21 approval (Implementation, ticket 03, association persistence
  and session invalidation both failing): "Accept the double-failure scenario as a documented
  technical limitation. If both association persistence and session invalidation fail, the
  extension cannot guarantee backend session invalidation. Ensure that the failure is logged
  without exposing sensitive information, the current request is anonymous, and the session
  cookie is removed. Keep the observed follow-up request behaviour explicitly covered by the
  functional test. Assert and document the actual limitation rather than omitting the
  assertion. Do not introduce retries, alternative session mutation mechanisms, additional
  infrastructure or unrelated changes. Proceed with the existing implementation and prepare it
  for Final Verification."
