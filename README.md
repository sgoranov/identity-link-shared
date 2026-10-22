# Identity Link Shared

![License](https://img.shields.io/github/license/sgoranov/identity-link-shared)
![Issues](https://img.shields.io/github/issues/sgoranov/identity-link-shared)
[![PHPUnit Tests](https://github.com/sgoranov/identity-link-shared/actions/workflows/phpunit.yml/badge.svg)](https://github.com/sgoranov/identity-link-shared/actions/workflows/phpunit.yml)
[![Security Audit](https://github.com/sgoranov/identity-link-shared/actions/workflows/vulnerability-scan.yml/badge.svg)](https://github.com/sgoranov/identity-link-shared/actions/workflows/vulnerability-scan.yml)
[![Packagist](https://img.shields.io/packagist/v/sgoranov/identity-link-shared)](https://packagist.org/packages/sgoranov/identity-link-shared)

Shared Symfony building blocks for Identity Link services: JWT access token
authentication, Doctrine-aware serialization and validation, and a few
ready-made controllers.

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

The bundle registers no services or routes. Register the classes you use in
`config/services.yaml`, e.g. `sgoranov\IdentityLinkShared\Serializer\Deserializer: ~`,
and give the controllers routes.

## JWT authentication

```yaml
# config/services.yaml
services:
    sgoranov\IdentityLinkShared\Security\AccessTokenHandlerConfiguration:
        arguments:
            $issuer: '%env(JWT_ISSUER)%'
            $audiences: '%env(csv:JWT_AUDIENCES)%'
            # Set exactly one key source:
            $publicKeyPath: '%kernel.project_dir%/config/jwt/public.pem'
            # $jwksUri: '%env(JWT_JWKS_URI)%'

    sgoranov\IdentityLinkShared\Security\AccessTokenHandler: ~

    # Used to fetch the JWKS
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

A token is accepted when its signature, `exp`, `nbf` and `iat` are valid, `iss`
matches and one of its `aud` values is in `$audiences`. The user identifier is
the `sub` claim, and the roles are the scopes from the `scope` claim.

> [!IMPORTANT]
> Scopes are not prefixed with `ROLE_`, so `#[IsGranted('read')]` denies access.
> Check them with `in_array('read', $user->getRoles(), true)` or a custom voter.

## Components

All class names are relative to `sgoranov\IdentityLinkShared`.

| Class                                    | Purpose                                                         |
|------------------------------------------|-----------------------------------------------------------------|
| `Controller\PingController`              | Returns `pong`, for health checks.                              |
| `Controller\LocaleController`            | Stores the chosen locale in a cookie and redirects back.        |
| `Controller\PasswordController`          | Scores the `password` form field with zxcvbn.                   |
| `Controller\OpenApiController`           | Serves `docs/openapi.yaml`, in the `dev` environment only.      |
| `Controller\QueryController`             | Paginated, filterable Doctrine queries over one entity type.    |
| `EventListener\LocaleListener`           | Sets the request locale from the `user_lang` cookie.            |
| `EventListener\XForwardedPrefixListener` | Uses `X-Forwarded-Prefix` from trusted proxies as the base URL. |
| `Monolog\RequestIdProcessor`             | Adds `X-Request-Id` to every log line as `extra.request_id`.    |
| `Serializer\Deserializer`                | Deserializes and validates a JSON request body into an object.  |
| `Serializer\Normalizer\*`                | Turn Doctrine entity relations into IDs and back.               |
| `Validator\*`                            | `UniqueEntry`, `PasswordStrength` and `JsonChoice` constraints. |
| `Security\PasswordHashGenerator`         | Creates bcrypt hashes, e.g. for fixtures.                       |

Requirements:

- `LocaleController` needs a route named `security_login` and the
  `translations/` and `local_theme/translations/` directories.
- `PasswordStrength` needs the `password_validation_enabled` parameter.

> [!WARNING]
> `QueryController` puts `query`, `joins` and `orderBy` from the request into the
> DQL unchanged, so clients can filter on any mapped field, including password
> hashes. Expose it only to trusted, authenticated clients.

## Development

```bash
bin/test-run                                  # install dependencies and run PHPUnit
bin/test-debug                                # open a shell in the PHP container
docker compose run --rm php composer audit    # check dependencies for known vulnerabilities
```

## License

## License

This is open source software licensed under the [MIT License](LICENSE), which permits reuse, 
modification, and distribution with minimal restrictions.
