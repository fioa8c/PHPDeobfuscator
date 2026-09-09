<?php

namespace PHPDeobfuscator\ValRef;

use PHPDeobfuscator\ValRef;

class ArrayVal extends AbstractValRef
{
    private $backingArray;

    public function __construct(array $items = array())
    {
        $this->backingArray = $items;
    }

    protected function &backingArray()
    {
        return $this->backingArray;
    }

    protected function getValueImpl()
    {
        $value = array();
        foreach ($this->backingArray() as $name => $ref) {
            $value[$name] = $ref->getValue();
        }
        return $value;
    }

    public function arrayFetch($dim)
    {
        $this->checkMutable();
        if ($dim === null) {
            $dim = '';
        }
        if (!isset($this->backingArray()[$dim])) {
            return null;
        }
        return $this->backingArray()[$dim];
    }

    public function arrayAssign($dim, ValRef $valRef)
    {
        if ($dim === null) {
            $this->backingArray()[] = $valRef;
        } else {
            $this->backingArray()[$dim] = $valRef;
        }
    }

    public function arrayUnset($dim)
    {
        unset($this->backingArray()[$dim]);
    }

    public function __toString()
    {
        // Arrays can contain references to themselves; this is only used for
        // diagnostics, so cut the recursion short rather than overflow the stack.
        static $depth = 0;
        if ($depth >= 3) {
            return 'Array(...)';
        }
        $depth++;
        try {
            $arr = $this->backingArray();
            return 'Array(' . implode(', ', array_map(function ($key) use (&$arr) {
                return "$key => " . $arr[$key];
            }, array_keys($arr))) . ')';
        } finally {
            $depth--;
        }
    }

    public function __clone()
    {
        foreach($this->backingArray() as $name => &$ref) {
            $ref = clone $ref;
        }
    }
}
