<?php

declare(strict_types=1);

namespace UiChemy\Deps\WP\McpSchema\Server\Lifecycle\Factory;

use UiChemy\Deps\WP\McpSchema\Server\Lifecycle\Union\ServerNotificationInterface;
use UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\CancelledNotification;
use UiChemy\Deps\WP\McpSchema\Common\Protocol\DTO\ProgressNotification;
use UiChemy\Deps\WP\McpSchema\Server\Logging\DTO\LoggingMessageNotification;
use UiChemy\Deps\WP\McpSchema\Server\Resources\DTO\ResourceUpdatedNotification;
use UiChemy\Deps\WP\McpSchema\Server\Resources\DTO\ResourceListChangedNotification;
use UiChemy\Deps\WP\McpSchema\Server\Tools\DTO\ToolListChangedNotification;
use UiChemy\Deps\WP\McpSchema\Server\Prompts\DTO\PromptListChangedNotification;
use UiChemy\Deps\WP\McpSchema\Client\Elicitation\DTO\ElicitationCompleteNotification;
use UiChemy\Deps\WP\McpSchema\Common\Tasks\DTO\TaskStatusNotification;

/**
 * Factory for creating ServerNotification union type instances.
 *
 * @mcp-domain Server
 * @mcp-subdomain Lifecycle
 * @mcp-version 2025-11-25
 */
final class ServerNotificationFactory
{
    /**
     * Registry mapping discriminator values to implementation classes.
     *
     * @var array<string, class-string<ServerNotificationInterface>>
     */
    public const REGISTRY = [
        'notifications/cancelled' => CancelledNotification::class,
        'notifications/progress' => ProgressNotification::class,
        'notifications/message' => LoggingMessageNotification::class,
        'notifications/resources/updated' => ResourceUpdatedNotification::class,
        'notifications/resources/list_changed' => ResourceListChangedNotification::class,
        'notifications/tools/list_changed' => ToolListChangedNotification::class,
        'notifications/prompts/list_changed' => PromptListChangedNotification::class,
        'notifications/elicitation/complete' => ElicitationCompleteNotification::class,
        'notifications/tasks/status' => TaskStatusNotification::class,
    ];

    /**
     * Creates an instance from an array.
     *
     * @param array<string, mixed> $data
     * @return ServerNotificationInterface
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): ServerNotificationInterface
    {
        if (!isset($data['method'])) {
            throw new \InvalidArgumentException('Missing discriminator field: method');
        }

        /** @var string $method */
        $method = $data['method'];
        if (!isset(self::REGISTRY[$method])) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown method value '%s'. Valid values: %s",
                $method,
                implode(', ', array_keys(self::REGISTRY))
            ));
        }

        $class = self::REGISTRY[$method];
        return $class::fromArray($data);
    }

    /**
     * Checks if a method value is supported by this factory.
     *
     * @param string $method
     * @return bool
     */
    public static function supports(string $method): bool
    {
        return isset(self::REGISTRY[$method]);
    }

    /**
     * Returns all supported method values.
     *
     * @return array<string>
     */
    public static function methods(): array
    {
        return array_keys(self::REGISTRY);
    }
}
