<?php

namespace PHPDeobfuscator\Exceptions;

use PHPDeobfuscator\ValRef;

class MutableValueException extends BadValueException
{
    /** The value that was refused; kept for diagnostics instead of being rendered eagerly. */
    public ValRef $valRef;

    public function __construct(ValRef $val)
    {
        // This exception is control flow: it is raised for nearly every node of a
        // large file. Rendering a big array or a megabyte string into the message
        // each time dominated the runtime on large webshells.
        $this->valRef = $val;
        parent::__construct("Value could be mutable: " . (new \ReflectionClass($val))->getShortName());
    }
}
