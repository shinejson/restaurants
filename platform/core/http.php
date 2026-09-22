<?php
/**
 * RestaurantOS — HTTP layer.
 *
 * A tiny request/response kit plus the JSON router that powers /api/v1, the
 * API behind the React superadmin console and the tenant-side billing pages.
 *
 * @package Resto\Http
 */

namespace Resto\Http;

use Resto\Support\Clock;

/* -------------------------------------------------------------------------
 * ApiException — anything the router should render as a JSON error
 * ---------------------------------------------------------------------- */

class ApiException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400, private array $details = [], private string $errorCode = '')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404, [], 'not_found');
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self($message, 401, [], 'unauthorized');
    }

    public static function forbidden(string $message = 'You do not have access to this resource'): self
    {
        return new self($message, 403, [], 'forbidden');
    }

    /** Validation failure: 422 with a field => messages map. */
    public static function invalid(array $details, string $message = 'The given data was invalid'): self
    {
        return new self($message, 422, $details, 'validation_error');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, [], 'conflict');
    }
}

/* -------------------------------------------------------------------------
 * Request
 * ---------------------------------------------------------------------- */

final class Request
{
    private ?array $jsonCache = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $rawBody = '',
        public readonly array $server = [],
    ) {
    }

    public static function capture(): self
    {
        $uri   = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $query = $_GET ?? [];

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // Front controllers are reached through a rewrite. The wasm dev server
        // replaces REQUEST_URI with the script path and forwards the original
        // URL in a header. Apache keeps the original REQUEST_URI — which for
        // sub-directory installs is prefixed ("…/restaurants/api/v1/…") — and
        // nginx proxies may send x-original-uri. The router only ever speaks
        // /api/…, so cut everything before it in every one of those shapes.
        foreach ([
            $headers['x-original-uri'] ?? null,
            $uri,
            $_SERVER['REDIRECT_URL'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && ($at = strpos($candidate, '/api/')) !== false) {
                $uri = substr($candidate, $at);
                break;
            }
        }

        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $headers,
            (string) file_get_contents('php://input'),
            $_SERVER,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header && preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function isJson(): bool
    {
        return str_contains((string) $this->header('content-type', ''), 'json');
    }

    /** Decoded JSON body, falling back to form data. */
    public function all(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if ($this->isJson() && $this->rawBody !== '') {
            $decoded = json_decode($this->rawBody, true);
            return $this->jsonCache = is_array($decoded) ? $decoded : [];
        }
        return $this->jsonCache = array_merge($_POST ?? [], $_GET ?? []);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return $data[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /** Only the listed keys (whitelist for mass-assignment). */
    public function only(array $keys): array
    {
        $data = $this->all();
        return array_intersect_key($data, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function ip(): string
    {
        foreach (['x-forwarded-for', 'x-real-ip'] as $header) {
            $value = $this->header($header);
            if ($value) {
                $first = trim(explode(',', $value)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) $this->header('user-agent', ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return ($this->header('x-forwarded-proto') === 'https') || !empty($this->server['HTTPS']);
    }
}

/* -------------------------------------------------------------------------
 * Response
 * ---------------------------------------------------------------------- */

final class Response
{
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            array_merge(['content-type' => 'application/json; charset=utf-8'], $headers),
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}'
        );
    }

    public static function ok(mixed $data = null, array $meta = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::json($payload);
    }

    public static function created(mixed $data = null, array $meta = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, 201);
    }

    public static function error(string $message, int $status = 400, array $details = [], string $code = ''): self
    {
        return self::json([
            'error'   => [
                'message' => $message,
                'code'    => $code !== '' ? $code : strtolower(str_replace(' ', '_', $message)),
                'details' => $details,
            ],
        ], $status);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['location' => $url], '');
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($this->headers, $headers), $this->body);
    }
}

/* -------------------------------------------------------------------------
 * Router
 * ---------------------------------------------------------------------- */

final class Router
{
    /** @var array<int,array{method:string,regex:string,params:string[],handler:callable,middleware:array}> */
    private array $routes = [];
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    /** @param callable|array $handler */
    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $pattern = $this->groupPrefix . $pattern;
        $params  = [];
        $regex   = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . rtrim($regex, '/') . '/?$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function get(string $p, callable|array $h, array $m = []): void
    {
        $this->add('GET', $p, $h, $m);
    }

    public function post(string $p, callable|array $h, array $m = []): void
    {
        $this->add('POST', $p, $h, $m);
    }

    public function put(string $p, callable|array $h, array $m = []): void
    {
        $this->add('PUT', $p, $h, $m);
    }

    public function patch(string $p, callable|array $h, array $m = []): void
    {
        $this->add('PATCH', $p, $h, $m);
    }

    public function delete(string $p, callable|array $h, array $m = []): void
    {
        $this->add('DELETE', $p, $h, $m);
    }

    /** Group routes under a prefix and shared middleware. */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $routes($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** Load route definitions from a file. */
    public function load(string $file): void
    {
        $router = $this;
        $api    = $this;
        (require $file)($router);
    }

    public function dispatch(Request $request): Response
    {
        $path   = rtrim($request->path, '/');
        $path   = $path === '' ? '/' : $path;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($route['method'] === 'GET' && $request->method === 'HEAD')) {
                $allowed[$route['method']] = true;
                continue;
            }

            array_shift($matches);
            $args     = array_combine($route['params'], $matches) ?: [];
            $handler  = $route['handler'];

            // Build the middleware chain (outermost first).
            $pipeline = array_reverse($route['middleware']);
            $next     = function () use ($handler, $request, $args): Response {
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $controller = is_string($class) ? new $class() : $class;
                    return $controller->{$method}($request, ...array_values($args));
                }
                return $handler($request, ...array_values($args));
            };

            foreach ($pipeline as $middleware) {
                $inner = $next;
                $next  = static fn (): Response => $middleware($request, $inner);
            }

            return $next();
        }

        if ($allowed !== []) {
            return Response::error('Method not allowed', 405, ['allowed' => array_keys($allowed)]);
        }

        return Response::error('Endpoint not found: ' . $request->path, 404, [], 'not_found');
    }

    /** Send a response to the client and stop. */
    public static function send(Response $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->status);
            foreach ($response->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $response->body;
    }

    /** Run the router against the current request, rendering errors as JSON. */
    public function handle(Request $request): void
    {
        try {
            $response = $this->dispatch($request);
        } catch (ApiException $e) {
            $response = Response::error($e->getMessage(), $e->status(), $e->details(), $e->errorCode());
        } catch (\PDOException $e) {
            error_log('[api] database error: ' . $e->getMessage());
            $response = Response::error(
                \Resto\Support\Config::get('app.debug') ? $e->getMessage() : 'A database error occurred',
                500,
                [],
                'database_error'
            );
        } catch (\Throwable $e) {
            error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::error(
                \Resto\Support\Config::get('app.debug') ? $e->getMessage() : 'Something went wrong',
                500,
                \Resto\Support\Config::get('app.debug')
                    ? ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 6)]
                    : [],
                'server_error'
            );
        }

        self::send($response);
    }
}

/* -------------------------------------------------------------------------
 * Validator — small rule engine for API input
 * ---------------------------------------------------------------------- */

final class Validator
{
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules  "required|email|max:191"
     */
    public function __construct(private array $data, private array $rules)
    {
    }

    public static function make(array $data, array $rules): self
    {
        return (new self($data, $rules))->validate();
    }

    public function validate(): self
    {
        foreach ($this->rules as $field => $ruleString) {
            $value    = $this->data[$field] ?? null;
            $rules    = explode('|', $ruleString);
            $required = in_array('required', $rules, true);
            $label    = str_replace(['_', '.'], ' ', $field);

            if ($required && ($value === null || $value === '' || $value === [])) {
                $this->errors[$field][] = ucfirst($label) . ' is required';
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                switch ($name) {
                    case 'email':
                        if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                            $this->errors[$field][] = ucfirst($label) . ' must be a valid email address';
                        }
                        break;
                    case 'min':
                        if (is_string($value) && mb_strlen($value) < (int) $arg) {
                            $this->errors[$field][] = ucfirst($label) . " must be at least {$arg} characters";
                        }
                        if (is_numeric($value) && (float) $value < (float) $arg) {
                            $this->errors[$field][] = ucfirst($label) . " must be at least {$arg}";
                        }
                        break;
                    case 'max':
                        if (is_string($value) && mb_strlen($value) > (int) $arg) {
                            $this->errors[$field][] = ucfirst($label) . " may not be longer than {$arg} characters";
                        }
                        if (is_numeric($value) && (float) $value > (float) $arg) {
                            $this->errors[$field][] = ucfirst($label) . " may not be greater than {$arg}";
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $this->errors[$field][] = ucfirst($label) . ' is not a valid option';
                        }
                        break;
                    case 'slug':
                        if (!preg_match('/^[a-z0-9]([a-z0-9-]{1,62})[a-z0-9]$/', (string) $value)) {
                            $this->errors[$field][] = 'Use 3-64 lowercase letters, numbers and dashes';
                        }
                        break;
                    case 'numeric':
                        if (!is_numeric($value)) {
                            $this->errors[$field][] = ucfirst($label) . ' must be a number';
                        }
                        break;
                    case 'boolean':
                        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                            $this->errors[$field][] = ucfirst($label) . ' must be true or false';
                        }
                        break;
                    case 'date':
                        if (strtotime((string) $value) === false) {
                            $this->errors[$field][] = ucfirst($label) . ' must be a valid date';
                        }
                        break;
                    case 'currency':
                        if (!preg_match('/^[A-Z]{3}$/', (string) $value)) {
                            $this->errors[$field][] = 'Use a 3-letter currency code (e.g. USD)';
                        }
                        break;
                }
            }
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validateOrFail(): void
    {
        if ($this->fails()) {
            throw ApiException::invalid($this->errors());
        }
    }
}
