# Identity Link Shared

[![Tests](https://github.com/sgoranov/php-identity-link-shared/actions/workflows/phpunit.yml/badge.svg?branch=develop)](https://github.com/sgoranov/php-identity-link-shared/actions/workflows/phpunit.yml)
[![Packagist](https://img.shields.io/packagist/v/sgoranov/identity-link-shared)](https://packagist.org/packages/sgoranov/identity-link-shared)

Shared Symfony building blocks for Identity Link services: JWT access token
authentication, request/response logging, Doctrine-aware serialization and
validation, and a few ready-made controllers.

## Installation

```bash
composer require sgoranov/identity-link-shared
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    sgoranov\IdentityLinkShared\IdentityLinkSharedBundle::class => ['all' => true],
];
```

The bundle does not register any services or routes on its own. Wire the parts
you use in your application's `config/services.yaml` and `config/routes.yaml`,
as shown below. The examples assume the default `autowire: true` and
`autoconfigure: true`.

## JWT authentication

`AccessTokenHandler` plugs into Symfony's `access_token` authenticator and
validates bearer tokens issued by your identity provider.

### Configuration

```yaml
# config/services.yaml
services:
    sgoranov\IdentityLinkShared\Security\AccessTokenHandlerConfiguration:
        arguments:
            $issuer: '%env(JWT_ISSUER)%'
            $audiences: '%env(csv:JWT_AUDIENCES)%'
            # Configure exactly one key source:
            $publicKeyPath: '%kernel.project_dir%/config/jwt/public.pem'
            # $jwksUri: '%env(JWT_JWKS_URI)%'

    sgoranov\IdentityLinkShared\Security\AccessTokenHandler: ~

    # PSR-17 request factory used to fetch the JWKS. With symfony/http-client
    # installed, Symfony already autowires the PSR-18 ClientInterface.
    Nyholm\Psr7\Factory\Psr17Factory: ~
    Psr\Http\Message\RequestFactoryInterface: '@Nyholm\Psr7\Factory\Psr17Factory'
```

```yaml
# config/packages/security.yaml
security:
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            access_token:
                token_handler: sgoranov\IdentityLinkShared\Security\AccessTokenHandler
```

| Argument         | Description                                                                                 |
|------------------|---------------------------------------------------------------------------------------------|
| `$issuer`        | Expected `iss` claim. Required.                                                             |
| `$audiences`     | Non-empty list of accepted `aud` values.                                                    |
| `$publicKeyPath` | Path to a PEM public key, read when the service is created. Tokens must be signed with RS256. |
| `$jwksUri`       | JWKS endpoint. Every key in the set must declare its `alg`.                                 |

Set exactly one of `$publicKeyPath` and `$jwksUri`. The configuration throws an
`InvalidArgumentException` when the issuer or an audience is empty, when both or
neither key source is set, or when the key file is missing or empty.

### Validation rules

A token is accepted when:

- its signature is valid for the configured key, or for the JWKS key matching its `kid`;
- `exp`, `nbf` and `iat` are within the allowed time;
- `iss` equals the configured issuer;
- at least one of its `aud` values is one of the configured audiences.

Anything else is rejected with a `BadCredentialsException`. Failures while
decoding the token, such as a bad signature, an unknown key or an expired token,
are also logged.

JWKS responses are cached on the filesystem for one hour, in the system
temporary directory. A token with an unknown `kid` triggers a refetch, limited
to 10 requests per minute.

### The authenticated user

The authenticated user is a `sgoranov\IdentityLinkShared\Security\User`:

- **Identifier:** the token's `sub`. For tokens without a subject, such as
  client credentials tokens, it is the audience that matched.
- **Roles:** the scopes from the space-separated `scope` claim, as-is. A token
  with `scope: "read write"` gives the roles `read` and `write`.
- **Claims:** `getAccessToken()` returns the decoded token.

Scopes are not prefixed with `ROLE_`, so Symfony's built-in `RoleVoter` ignores
them and `#[IsGranted('read')]` will deny access. Check them with
`in_array('read', $user->getRoles(), true)` or a custom voter.

## Components

| Component                 | Class                                                         | Purpose                                                                                       |
|---------------------------|---------------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| Ping                      | `Controller\PingController::ping`                             | Returns `pong`, for health checks.                                                            |
| Locale switch             | `Controller\LocaleController::switchLocale`                   | Stores the chosen locale in a cookie and redirects back.                                      |
| Password strength         | `Controller\PasswordController::checkPasswordStrength`        | Scores the `password` form field with zxcvbn.                                                 |
| OpenAPI spec              | `Controller\OpenApiController::openapi`                       | Serves `docs/openapi.yaml`, in the `dev` environment only.                                    |
| Entity query API          | `Controller\QueryController::query`                           | Paginated, filterable queries over one entity type.                                           |
| Locale listener           | `EventListener\LocaleListener`                                | Sets the request locale from the `user_lang` cookie.                                          |
| Forwarded prefix listener | `EventListener\XForwardedPrefixListener`                      | Uses `X-Forwarded-Prefix` from trusted proxies as the base URL when generating URLs.          |
| Request/response logger   | `EventListener\RequestResponseLogger`                         | Logs every request and response, with credentials masked.                                     |
| Deserializer              | `Serializer\Deserializer`                                     | Deserializes and validates a JSON request body into an object.                                |
| Doctrine normalizers      | `Serializer\Normalizer\DoctrineManyToOneNormalizer`, `DoctrineManyToManyNormalizer` | Turn entity relations into IDs and back.                                |
| Validators                | `Validator\UniqueEntry`, `PasswordStrength`, `JsonChoice`     | Constraints for uniqueness, password strength and lists of allowed values.                    |
| Password hashing          | `Security\PasswordHashGenerator::create()`                    | Creates a bcrypt hash with cost 10, e.g. for fixtures.                                        |

All class names are relative to `sgoranov\IdentityLinkShared`.

### Controllers

Register the controllers and give them routes:

```yaml
# config/services.yaml
services:
    sgoranov\IdentityLinkShared\Controller\:
        resource: '../vendor/sgoranov/identity-link-shared/src/Controller/'
```

```yaml
# config/routes.yaml
ping:
    path: /ping
    controller: sgoranov\IdentityLinkShared\Controller\PingController::ping
    methods: [GET]

locale_switch:
    path: /locale/{locale}
    controller: sgoranov\IdentityLinkShared\Controller\LocaleController::switchLocale
    methods: [GET]

password_strength:
    path: /password/strength
    controller: sgoranov\IdentityLinkShared\Controller\PasswordController::checkPasswordStrength
    methods: [POST]

openapi:
    path: /openapi.yaml
    controller: sgoranov\IdentityLinkShared\Controller\OpenApiController::openapi
    methods: [GET]
```

The paths are only examples. Notes on individual controllers:

- **`LocaleController`** finds the available locales from
  `translations/*.<locale>.yaml` and `local_theme/translations/*.<locale>.yaml`
  and caches the list for 24 hours. Both directories must exist. Unknown
  locales fall back to `en`. The `user_lang` cookie lasts one year and is set
  on the parent domain, so sibling subdomains share it. Without a `Referer`
  header, the controller redirects to a route named `security_login`, which
  your application must define.
- **`PasswordController`** reads `password` from a form-encoded body, not JSON.
  It returns zxcvbn's full result as JSON, including the submitted password, or
  a 400 error when the field is missing.

### Entity query API

`QueryController::query()` runs a paginated Doctrine query described by the
JSON request body. Create a request class per entity and an action that passes
it in:

```php
use sgoranov\IdentityLinkShared\Api\DTO\AbstractQueryRequest;

final class UserQueryRequest extends AbstractQueryRequest
{
    public function getType(): string
    {
        return User::class;
    }

    public function setType(string $type): void
    {
        // The entity type is fixed. Ignore any "type" sent by the client.
    }
}
```

```php
use sgoranov\IdentityLinkShared\Controller\QueryController;

final class UserController extends QueryController
{
    #[Route('/api/users/query', methods: ['POST'])]
    public function queryUsers(): Response
    {
        return $this->query(new UserQueryRequest());
    }
}
```

Request body (all fields optional):

```json
{
    "alias": "t",
    "query": "t.email LIKE :email",
    "parameters": { "email": "%@example.com" },
    "joins": { "g": "t.groups" },
    "orderBy": { "t.email": "ASC" },
    "limit": 10,
    "offset": 0
}
```

- `alias` is the root entity alias and defaults to `t`.
- `parameters` are always bound as strings.
- `limit` defaults to 10 and `offset` to 0.

The response contains the serialized entities and whether more results exist:

```json
{ "response": { "result": [ ... ], "hasMore": true } }
```

Invalid input and query errors return `400` with `{"error": "..."}`.

> [!WARNING]
> `query`, `joins` and `orderBy` go into the DQL unchanged. A client that can
> call the endpoint can filter, join and sort on any mapped field, including
> fields you never serialize, such as password hashes. Expose it only to trusted,
> authenticated clients, and keep `setType()` a no-op so clients cannot query a
> different entity.

### Request/response logger

```yaml
# config/services.yaml
services:
    sgoranov\IdentityLinkShared\EventListener\RequestResponseLogger: ~
```

`RequestResponseLogger` logs every request together with its response at `info`
level, and requests that end in an exception at `error` level. Each entry has
the method, path, headers, status, body lengths and the bodies, with sensitive
data masked:

- The `Authorization`, `Proxy-Authorization`, `Cookie` and `Set-Cookie` headers,
  and any header whose name matches the sensitive field pattern, are replaced
  with `*** MASKED ***`.
- A body that is a JSON object or list is logged with the value of every field
  whose name matches the pattern replaced with `*** MASKED ***`, however deeply
  it is nested. The masked body is cut to 2,000 bytes, ending in
  `... (TRUNCATED)`.
- Any other body has no fields to mask, so it is replaced with
  `*** BODY CANNOT BE MASKED ***`: form data, multipart, text, binary, invalid
  JSON, or a bare JSON value such as `"hunter2"`. The `Content-Type` header is
  ignored, because the client sets it.
- A body over 1 MB takes too much memory to decode, so it is replaced with
  `*** BODY IS TOO LARGE ***`.

The default pattern is `RequestResponseLogger::DEFAULT_SENSITIVE_FIELD_PATTERN`:

```
/passw(or)?d|secret|tokens?(_hint)?$|credential|api[_-]?key|^(code|code_verifier|otp|pin)$/i
```

It matches field names that contain `password`, `secret`, `credential` or an API
key, such as `plainPassword` or `client_secret`, and names that end in `token`,
such as `access_token`, `refresh_token`, `id_token`, `csrf_token` or `_token`.
Public OAuth metadata like `token_type` and `token_endpoint` stays readable.
`code`, `code_verifier`, `otp` and `pin` only match as whole names, so
`countryCode` and `opinion` are logged as is. Replace the pattern, or log bodies
and headers only in debug mode, with the constructor arguments. With
`$logBodies` set to `false`, bodies are replaced with
`*** BODY LOGGING IS DISABLED ***`. With `$logHeaders` set to `false`, the
`headers` field is left out:

```yaml
services:
    sgoranov\IdentityLinkShared\EventListener\RequestResponseLogger:
        arguments:
            $sensitiveFieldPattern: '/passw(or)?d|secret|token|^pwd$/i'
            $logBodies: '%kernel.debug%'
            $logHeaders: '%kernel.debug%'
```

> [!NOTE]
> Masking works by field name. A value under a name the pattern does not match,
> such as `pwd` or `pass1`, is logged unmasked. Extend the pattern for your field
> names, or set `$logBodies` to `false`.

### Serialization

```yaml
# config/services.yaml
services:
    sgoranov\IdentityLinkShared\Serializer\Deserializer: ~
    sgoranov\IdentityLinkShared\Serializer\Normalizer\DoctrineManyToOneNormalizer: ~
    sgoranov\IdentityLinkShared\Serializer\Normalizer\DoctrineManyToManyNormalizer: ~
```

`Deserializer` fills an existing object from the JSON request body, rejects
unknown fields and validates the result. When anything fails, it keeps the first
error so you can return it:

```php
public function create(Deserializer $deserializer): Response
{
    $user = new User();
    if (!$deserializer->deserialize($user, ['create'])) {
        return $deserializer->respondWithError(); // 400 {"error": "..."}
    }

    // ...
}
```

The Doctrine normalizers work with entities that have a `getId()` method:

- **`DoctrineManyToOneNormalizer`** serializes a related entity as its ID. On
  deserialization it loads the entity for the given ID. An unknown ID produces
  the error `Invalid UUID <id> passed.`
- **`DoctrineManyToManyNormalizer`** serializes a to-many collection as
  `{"data": [<up to 10 IDs>], "hasMore": true|false}`. It deserializes a list of
  IDs into an `ArrayCollection` when the target type is `Entity[]`.

### Validators

```yaml
# config/services.yaml
parameters:
    password_validation_enabled: true

services:
    sgoranov\IdentityLinkShared\Validator\UniqueEntryValidator: ~
    sgoranov\IdentityLinkShared\Validator\PasswordStrengthValidator: ~
```

```php
use sgoranov\IdentityLinkShared\Validator\JsonChoice;
use sgoranov\IdentityLinkShared\Validator\PasswordStrength;
use sgoranov\IdentityLinkShared\Validator\UniqueEntry;

class User
{
    #[UniqueEntry]
    private string $email;

    #[PasswordStrength]
    private ?string $plainPassword = null;

    #[JsonChoice(choices: ['read', 'write'])]
    private array $scopes = [];
}
```

- **`UniqueEntry`** fails when another entity of the same class already has the
  value. The entity being validated may keep its own value.
- **`PasswordStrength`** requires a zxcvbn score of at least 3 and includes
  zxcvbn's suggestions in the message. It is skipped when
  `password_validation_enabled` is `false`. The parameter must always be defined.
- **`JsonChoice`** requires every element of an array to be one of `choices`.
  Values that are not arrays are ignored.

## Development

The test suite and security checks run in Docker:

```bash
bin/test-run                                  # install dependencies and run PHPUnit
bin/test-debug                                # open a shell in the PHP container
docker compose run --rm php composer audit    # check dependencies for known vulnerabilities
```1

## License

MIT. See [LICENSE](LICENSE).
