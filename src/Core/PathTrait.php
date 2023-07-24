<?php

namespace HongXunPan\Framework\Core;

use Exception;
use stdClass;

trait PathTrait
{
    private ?object $path = null;

    public function setPath($name, $path): void
    {
        if (!($this->path)) {
            $this->path = new stdClass();
        }
        if (!str_ends_with(DIRECTORY_SEPARATOR, $path)) {
            $path .= DIRECTORY_SEPARATOR;
        }
        $this->path->$name = $path;
    }

    public function getPath($name): string
    {
        if (isset($this->path->$name)) {
            return $this->path->$name;
        }
        throw new Exception($name . ' not set');
    }
}