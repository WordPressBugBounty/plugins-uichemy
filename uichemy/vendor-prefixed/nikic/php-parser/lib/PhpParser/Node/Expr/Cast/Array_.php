<?php declare(strict_types=1);

namespace UiChemy\Deps\PhpParser\Node\Expr\Cast;

use UiChemy\Deps\PhpParser\Node\Expr\Cast;

class Array_ extends Cast {
    public function getType(): string {
        return 'Expr_Cast_Array';
    }
}
