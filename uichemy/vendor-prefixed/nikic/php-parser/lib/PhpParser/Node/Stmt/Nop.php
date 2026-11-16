<?php declare(strict_types=1);

namespace UiChemy\Deps\PhpParser\Node\Stmt;

use UiChemy\Deps\PhpParser\Node;

/** Nop/empty statement (;). */
class Nop extends Node\Stmt {
    public function getSubNodeNames(): array {
        return [];
    }

    public function getType(): string {
        return 'Stmt_Nop';
    }
}
