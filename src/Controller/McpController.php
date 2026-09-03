<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\McpToolService;
use App\Support\McpToolException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * MCP (Model Context Protocol) server, "Streamable HTTP" transport - a
 * single JSON-RPC 2.0 endpoint (POST /mcp) implementing just enough of the
 * spec for tool calls (initialize, notifications/initialized, tools/list,
 * tools/call): no SSE streaming, no resources/prompts - every tool call
 * here is a quick, synchronous request/response, so a single JSON body per
 * request is a fully spec-compliant response and far simpler to run on
 * shared hosting than a persistent SSE connection would be.
 *
 * Auth is handled entirely by McpAuthMiddleware (route-level, see
 * config/routes.php) before this controller ever runs - by the time
 * handle() executes, $request's "user" attribute is always a real, active
 * user row. Stefan's use case: dictate a trip report / send photos to his
 * own agent, which calls these tools to write them into the diary.
 */
final class McpController
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function __construct(private readonly McpToolService $tools)
    {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = (string) $request->getBody();
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonRpcError($response, null, -32700, 'Parse error');
        }
        if (!is_array($payload) || !isset($payload['method']) || !is_string($payload['method'])) {
            return $this->jsonRpcError($response, $payload['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $payload['id'] ?? null;
        $method = $payload['method'];
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        // A notification (no "id") gets no response body at all - per the
        // JSON-RPC/MCP spec, the client isn't waiting for one.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return $response->withStatus(202);
        }

        return match ($method) {
            'initialize' => $this->respond($response, $id, [
                'protocolVersion' => is_string($params['protocolVersion'] ?? null)
                    ? $params['protocolVersion']
                    : self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'travel-companion', 'version' => '1.0.0'],
            ]),
            'tools/list' => $this->respond($response, $id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($response, $id, $params, $request),
            default => $this->jsonRpcError($response, $id, -32601, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function callTool(ResponseInterface $response, mixed $id, array $params, ServerRequestInterface $request): ResponseInterface
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : null;
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === null) {
            return $this->jsonRpcError($response, $id, -32602, 'Invalid params: "name" is required.');
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user');

        try {
            $result = match ($name) {
                'list_trips' => $this->tools->listTrips($user),
                'get_trip' => $this->tools->getTrip($user, $this->requiredString($args, 'trip')),
                'get_day_entry' => $this->tools->getDayEntry(
                    $user,
                    $this->requiredString($args, 'trip'),
                    $this->requiredString($args, 'date'),
                ),
                'append_day_entry_text' => $this->tools->appendDayEntryText(
                    $user,
                    $this->requiredString($args, 'trip'),
                    $this->requiredString($args, 'date'),
                    $this->requiredString($args, 'text'),
                    is_string($args['title'] ?? null) ? $args['title'] : null,
                    is_string($args['mood'] ?? null) ? $args['mood'] : null,
                ),
                'replace_day_entry_text' => $this->tools->replaceDayEntryText(
                    $user,
                    $this->requiredString($args, 'trip'),
                    $this->requiredString($args, 'date'),
                    $this->requiredString($args, 'text'),
                    is_string($args['title'] ?? null) ? $args['title'] : null,
                    is_string($args['mood'] ?? null) ? $args['mood'] : null,
                ),
                'add_day_entry_photo' => $this->tools->addDayEntryPhoto(
                    $user,
                    $this->requiredString($args, 'trip'),
                    $this->requiredString($args, 'date'),
                    $this->requiredString($args, 'image_base64'),
                    is_string($args['filename'] ?? null) ? $args['filename'] : null,
                ),
                default => throw new McpToolException('Unknown tool: ' . $name),
            };
        } catch (McpToolException $e) {
            return $this->respond($response, $id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }

        return $this->respond($response, $id, [
            'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            'isError' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function requiredString(array $args, string $key): string
    {
        if (!is_string($args[$key] ?? null) || trim($args[$key]) === '') {
            throw new McpToolException('"' . $key . '" is required.');
        }
        return $args[$key];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        $tripProperty = ['type' => 'string', 'description' => 'Trip slug or numeric id (see list_trips).'];
        $dateProperty = ['type' => 'string', 'description' => 'Calendar date, YYYY-MM-DD.'];

        return [
            [
                'name' => 'list_trips',
                'description' => "List the authenticated user's own trips: id, slug, title, country, date range, "
                    . 'and whether today falls within it (is_current).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_trip',
                'description' => 'Get one trip\'s metadata plus its list of diary days (date, whether it already has text).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['trip' => $tripProperty],
                    'required' => ['trip'],
                ],
            ],
            [
                'name' => 'get_day_entry',
                'description' => 'Get the current diary text/mood/photo count for one day of a trip (exists: false if nothing recorded yet).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['trip' => $tripProperty, 'date' => $dateProperty],
                    'required' => ['trip', 'date'],
                ],
            ],
            [
                'name' => 'append_day_entry_text',
                'description' => 'Append dictated diary text to a day (creates the day entry if it does not exist yet). '
                    . 'Never replaces existing text - the new text is added as a new paragraph after whatever is already '
                    . 'there. title/mood are only set if the entry doesn\'t already have one. Use this for straightforward '
                    . "additions; if the new dictation needs to change or weave into text you already wrote earlier that "
                    . 'same day (e.g. "we also met a monk at the temple from this morning"), use get_day_entry to fetch '
                    . 'the current text, merge it yourself, and call replace_day_entry_text with the full result instead.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'trip' => $tripProperty,
                        'date' => $dateProperty,
                        'text' => ['type' => 'string', 'description' => 'The diary text to add.'],
                        'title' => ['type' => 'string', 'description' => 'Optional day title, only used if the entry has none yet.'],
                        'mood' => [
                            'type' => 'string',
                            'enum' => ['very_bad', 'bad', 'neutral', 'good', 'very_good'],
                            'description' => 'Optional mood, only used if the entry has none yet.',
                        ],
                    ],
                    'required' => ['trip', 'date', 'text'],
                ],
            ],
            [
                'name' => 'replace_day_entry_text',
                'description' => 'Overwrite a day\'s ENTIRE diary text (creates the day entry if it does not exist yet) - '
                    . 'unlike append_day_entry_text, this replaces whatever was there and any title/mood given IS '
                    . 'overwritten. Use this when a new dictation needs to revise, correct, or integrate a detail into '
                    . 'text already written for that day: call get_day_entry first, merge the new information into the '
                    . 'existing text yourself, then send the complete revised text here.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'trip' => $tripProperty,
                        'date' => $dateProperty,
                        'text' => ['type' => 'string', 'description' => 'The complete, revised diary text for this day.'],
                        'title' => ['type' => 'string', 'description' => 'Optional day title - overwrites any existing title.'],
                        'mood' => [
                            'type' => 'string',
                            'enum' => ['very_bad', 'bad', 'neutral', 'good', 'very_good'],
                            'description' => 'Optional mood - overwrites any existing mood.',
                        ],
                    ],
                    'required' => ['trip', 'date', 'text'],
                ],
            ],
            [
                'name' => 'add_day_entry_photo',
                'description' => 'Attach a photo (JPEG, PNG, or WebP, base64-encoded) to a day (creates the day entry '
                    . 'if it does not exist yet). Runs through the same processing as a normal upload '
                    . '(thumbnail, EXIF/GPS extraction) in the background.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'trip' => $tripProperty,
                        'date' => $dateProperty,
                        'image_base64' => ['type' => 'string', 'description' => 'Raw image bytes, base64-encoded.'],
                        'filename' => ['type' => 'string', 'description' => 'Optional original filename (used to infer the extension).'],
                    ],
                    'required' => ['trip', 'date', 'image_base64'],
                ],
            ],
        ];
    }

    private function respond(ResponseInterface $response, mixed $id, mixed $result): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonRpcError(ResponseInterface $response, mixed $id, int $code, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
