<?php

declare(strict_types=1);

namespace UiChemy\Deps\WP\McpSchema\Server\Resources\DTO;

use UiChemy\Deps\WP\McpSchema\Common\JsonRpc\DTO\RequestParamsMeta;
use UiChemy\Deps\WP\McpSchema\Common\Traits\ValidatesRequiredFields;

/**
 * Parameters for a `resources/subscribe` request.
 *
 * Note: This class is structurally identical to ResourceRequestParams.
 * It exists as a separate type for semantic distinction per MCP specification.
 *
 * @since 2025-11-25
 *
 * @mcp-domain Server
 * @mcp-subdomain Resources
 * @mcp-version 2025-11-25
 * @see ResourceRequestParams
 */
class SubscribeRequestParams extends ResourceRequestParams
{
    use ValidatesRequiredFields;

    /**
     * @param string $uri @since 2025-11-25
     * @param \UiChemy\Deps\WP\McpSchema\Common\JsonRpc\DTO\RequestParamsMeta|null $_meta @since 2025-11-25
     */
    public function __construct(
        string $uri,
        ?RequestParamsMeta $_meta = null
    ) {
        parent::__construct($uri, $_meta);
    }

    /**
     * Creates an instance from an array.
     *
     * @param array{
     *     uri: string,
     *     _meta?: array<string, mixed>|\UiChemy\Deps\WP\McpSchema\Common\JsonRpc\DTO\RequestParamsMeta|null
     * } $data
     * @phpstan-param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        self::assertRequired($data, ['uri']);

        /** @var \UiChemy\Deps\WP\McpSchema\Common\JsonRpc\DTO\RequestParamsMeta|null $_meta */
        $_meta = isset($data['_meta'])
            ? (is_array($data['_meta'])
                ? RequestParamsMeta::fromArray(self::asArray($data['_meta']))
                : $data['_meta'])
            : null;

        return new self(
            self::asString($data['uri']),
            $_meta
        );
    }

    /**
     * Converts the instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = parent::toArray();

        return $result;
    }
}
