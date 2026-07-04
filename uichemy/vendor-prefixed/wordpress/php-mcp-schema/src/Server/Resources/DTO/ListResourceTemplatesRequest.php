<?php

declare(strict_types=1);

namespace UiChemy\Deps\WP\McpSchema\Server\Resources\DTO;

use UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\PaginatedRequest;
use UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\PaginatedRequestParams;
use UiChemy\Deps\WP\McpSchema\Common\Protocol\Union\ClientRequestInterface;
use UiChemy\Deps\WP\McpSchema\Common\Traits\ValidatesRequiredFields;

/**
 * Sent from the client to request a list of resource templates the server has.
 *
 * Note: This class is structurally identical to PaginatedRequest.
 * It exists as a separate type for semantic distinction per MCP specification.
 *
 * @since 2024-11-05
 * @last-updated 2025-11-25 (modified property: params)
 *
 * @mcp-domain Server
 * @mcp-subdomain Resources
 * @mcp-version 2025-11-25
 * @see PaginatedRequest
 */
class ListResourceTemplatesRequest extends PaginatedRequest implements ClientRequestInterface
{
    use ValidatesRequiredFields;

    public const METHOD = 'resources/templates/list';

    public const DISCRIMINATOR_FIELD = 'method';
    public const DISCRIMINATOR_VALUE = 'resources/templates/list';

    /**
     * @param '2.0' $jsonrpc @since 2025-11-25
     * @param string|number $id @since 2025-11-25
     * @param \UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\PaginatedRequestParams|null $params @since 2024-11-05
     */
    public function __construct(
        string $jsonrpc,
        $id,
        ?PaginatedRequestParams $params = null
    ) {
        parent::__construct($jsonrpc, $id, self::METHOD, $params);
    }

    /**
     * Creates an instance from an array.
     *
     * @param array{
     *     params?: array<string, mixed>|\UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\PaginatedRequestParams|null,
     *     jsonrpc: '2.0',
     *     id: string|number,
     *     method: 'resources/templates/list'
     * } $data
     * @phpstan-param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        self::assertRequired($data, ['jsonrpc', 'id']);

        /** @var '2.0' $jsonrpc */
        $jsonrpc = self::asString($data['jsonrpc']);

        /** @var string|number $id */
        $id = self::asStringOrNumber($data['id']);

        /** @var \UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\PaginatedRequestParams|null $params */
        $params = isset($data['params'])
            ? (is_array($data['params'])
                ? PaginatedRequestParams::fromArray(self::asArray($data['params']))
                : $data['params'])
            : null;

        return new self(
            $jsonrpc,
            $id,
            $params
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
