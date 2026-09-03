<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A tool-level failure (not found, forbidden, bad input) that McpController
 * turns into an MCP `isError: true` tool result rather than a JSON-RPC
 * protocol error - the calling model sees the message and can retry/adjust,
 * same distinction the MCP spec draws between "the tool ran and failed" and
 * "the request itself was malformed".
 */
final class McpToolException extends \RuntimeException
{
}
