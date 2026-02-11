<?php

declare(strict_types=1);

namespace App\Domain\Shared;

abstract class ValueObject
{
    abstract public function toArray(): array;

    public function equals(ValueObject $other): bool
    {
        return $other instanceof static && $this->toArray() === $other->toArray();
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
