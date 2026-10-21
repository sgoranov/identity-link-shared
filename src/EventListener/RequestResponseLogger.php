<?php
declare(strict_types=1);

namespace sgoranov\IdentityLinkShared\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RequestResponseLogger
{
    public const DEFAULT_SENSITIVE_FIELD_PATTERN = '/passw(or)?d|secret|tokens?(_hint)?$|credential|api[_-]?key|^(code|code_verifier|otp|pin)$/i';

    private const MAX_DECODED_BODY_LENGTH = 1_000_000;
    private const MAX_LOGGED_BODY_LENGTH = 2000;
    private const MASK = '*** MASKED ***';
    private const BODY_LOGGING_DISABLED = '*** BODY LOGGING IS DISABLED ***';
    private const BODY_TOO_LARGE = '*** BODY IS TOO LARGE ***';
    private const BODY_CANNOT_BE_MASKED = '*** BODY CANNOT BE MASKED ***';
    private const SENSITIVE_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $sensitiveFieldPattern = self::DEFAULT_SENSITIVE_FIELD_PATTERN,
        private readonly bool $logBodies = true,
        private readonly bool $logHeaders = true,
    )
    {
        if (false === @preg_match($this->sensitiveFieldPattern, '')) {
            throw new \InvalidArgumentException(sprintf('The sensitive field pattern "%s" is not a valid regular expression.', $this->sensitiveFieldPattern));
        }
    }

    #[AsEventListener(event: RequestEvent::class)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $content = $request->getContent();

        $logData = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ];

        if ($this->logHeaders) {
            $logData['headers'] = $this->maskHeaders($request->headers->all());
        }

        $logData['body_length'] = strlen($content);
        $logData['body'] = $this->maskBody($content);

        $request->attributes->set('request_log', $logData);
    }

    #[AsEventListener(event: ResponseEvent::class)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $logData = $request->attributes->get('request_log', []);
        $logData['status'] = $response->getStatusCode();
        $logData = array_merge($logData, $this->appendResponseData($response));

        $this->logger->info('REST API Log', $logData);
    }

    #[AsEventListener(event: ExceptionEvent::class)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $logData = $request->attributes->get('request_log', []);
        $exception = $event->getThrowable();

        if ($response instanceof Response) {
            $logData['status'] = $response->getStatusCode();
            $logData = array_merge($logData, $this->appendResponseData($response));
        } else {
            if ($exception instanceof NotFoundHttpException) {
                $logData['status'] = 404;
            } elseif ($exception instanceof AccessDeniedException) {
                $logData['status'] = 403;
            } elseif ($exception instanceof UnauthorizedHttpException) {
                $logData['status'] = 401;
            } else {
                $logData['status'] = 500;
            }
        }

        $logData['error'] = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        $this->logger->error('REST API Log', $logData);
    }

    private function appendResponseData(Response $response): array
    {
        $content = $response->getContent();
        $content = is_string($content) ? $content : '';

        return [
            'response_length' => strlen($content),
            'response_body' => $this->maskBody($content),
        ];
    }

    private function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if ($this->isSensitiveHeader((string) $name)) {
                $headers[$name] = [self::MASK];
            }
        }

        return $headers;
    }

    /**
     * A JSON object or list is logged with its sensitive fields masked, cut to MAX_LOGGED_BODY_LENGTH bytes.
     * Any other body (form data, text, binary, invalid JSON, a bare JSON value) has no fields to mask, so it is not logged.
     * Neither is a body over MAX_DECODED_BODY_LENGTH bytes, because decoding it takes about ten times its size in memory.
     * The Content-Type header is ignored, because the client sets it.
     */
    private function maskBody(string $content): string
    {
        if ('' === $content) {
            return '';
        }

        if (!$this->logBodies) {
            return self::BODY_LOGGING_DISABLED;
        }

        if (strlen($content) > self::MAX_DECODED_BODY_LENGTH) {
            return self::BODY_TOO_LARGE;
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return self::BODY_CANNOT_BE_MASKED;
        }

        $data = $this->maskSensitiveFields($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Encoding fails only for numbers JSON cannot hold, such as 1e999
        if (false === $json) {
            return self::BODY_CANNOT_BE_MASKED;
        }

        if (strlen($json) > self::MAX_LOGGED_BODY_LENGTH) {
            // Cuts at a character boundary, so a multibyte character is never split
            return mb_strcut($json, 0, self::MAX_LOGGED_BODY_LENGTH, 'UTF-8').'... (TRUNCATED)';
        }

        return $json;
    }

    /**
     * Replaces the value of every sensitive field with the mask, however deeply it is nested.
     */
    private function maskSensitiveFields(array $data): array
    {
        foreach ($data as $field => $value) {
            if ($this->isSensitiveField((string) $field)) {
                $data[$field] = self::MASK;
            } elseif (is_array($value)) {
                $data[$field] = $this->maskSensitiveFields($value);
            }
        }

        return $data;
    }

    private function isSensitiveField(string $name): bool
    {
        // A matching error counts as sensitive, so values are never logged by mistake
        return 0 !== preg_match($this->sensitiveFieldPattern, $name);
    }

    private function isSensitiveHeader(string $name): bool
    {
        return in_array(strtolower($name), self::SENSITIVE_HEADERS, true) || $this->isSensitiveField($name);
    }
}
