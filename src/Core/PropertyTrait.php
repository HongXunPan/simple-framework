<?php /** @noinspection PhpMissingFieldTypeInspection */

namespace HongXunPan\Framework\Core;

trait PropertyTrait
{
    protected $property;

    public function __get(string $name)
    {
        if (!isset($this->property[$name])) {
            /** @noinspection PhpUndefinedFunctionInspection */
            $this->property[$name] = collect();
        }
        return $this->property[$name];
    }
}
