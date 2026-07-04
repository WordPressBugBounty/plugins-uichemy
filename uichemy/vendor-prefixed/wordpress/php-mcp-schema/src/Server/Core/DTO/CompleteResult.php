<?php

declare(strict_types=1);

namespace UiChemy\Deps\WP\McpSchema\Server\Core\DTO;

use UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\Result;
use UiChemy\Deps\WP\McpSchema\Common\Traits\ValidatesRequiredFields;
use UiChemy\Deps\WP\McpSchema\Server\Lifecycle\Union\ServerResultInterface;

/**
 * The server's response to a completion/complete request
 *
 * @since 2024-11-05
 *
 * @mcp-domain Server
 * @mcp-subdomain Core
 * @mcp-version 2025-11-25
 */
class CompleteResult extends Result implements ServerResultInterface
{
    use ValidatesRequiredFields;

    /**
     * @since 2024-11-05
     *
     * @var \UiChemy\Deps\WP\McpSchema\Server\Core\DTO\CompleteResultCompletion
     */
    protected CompleteResultCompletion $completion;

    /**
     * @param \UiChemy\Deps\WP\McpSchema\Server\Core\DTO\CompleteResultCompletion $completion @since 2024-11-05
     * @param array<string, mixed>|null $_meta @since 2024-11-05
     */
    public function __construct(
        CompleteResultCompletion $completion,
        ?array $_meta = null
    ) {
        parent::__construct($_meta);
        $this->completion = $completion;
    }

    /**
     * Creates an instance from an array.
     *
     * @param array{
     *     _meta?: array<string, mixed>|null,
     *     completion: array<string, mixed>|\UiChemy\Deps\WP\McpSchema\Server\Core\DTO\CompleteResultCompletion
     * } $data
     * @phpstan-param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        self::assertRequired($data, ['completion']);

        /** @var \UiChemy\Deps\WP\McpSchema\Server\Core\DTO\CompleteResultCompletion $completion */
        $completion = is_array($data['completion'])
            ? CompleteResultCompletion::fromArray(self::asArray($data['completion']))
            : $data['completion'];

        return new self(
            $completion,
            self::asArrayOrNull($data['_meta'] ?? null)
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

        $result['completion'] = $this->completion->toArray();

        return $result;
    }

    /**
     * @return \UiChemy\Deps\WP\McpSchema\Server\Core\DTO\CompleteResultCompletion
     */
    public function getCompletion(): CompleteResultCompletion
    {
        return $this->completion;
    }
}
