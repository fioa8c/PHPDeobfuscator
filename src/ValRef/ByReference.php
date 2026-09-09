<?php

namespace PHPDeobfuscator\ValRef;

use PHPDeobfuscator\Exceptions;
use PHPDeobfuscator\Scope;
use PHPDeobfuscator\ValRef;
use PHPDeobfuscator\VarRef;

class ByReference implements ValRef
{
    private $variable;
    private $scope;

    public function __construct(VarRef $varRef, Scope $scope)
    {
        $this->variable = $varRef;
        $this->scope = $scope;
    }

    public function isMutable()
    {
        try {
            return $this->getVal()->isMutable();
        } catch (Exceptions\UnknownValueException $e) {
            return true;
        }
    }

    public function setMutable($mutable)
    {
        try {
            $this->getVal()->setMutable($mutable);
        } catch (Exceptions\UnknownValueException $e) {
            // Don't care
        }
    }

    public function getValue()
    {
        return $this->getVal()->getValue();
    }

    public function arrayFetch($dim)
    {
        return $this->getVal()->arrayFetch($dim);
    }

    public function arrayAssign($dim, ValRef $valRef)
    {
        $this->getVal()->arrayAssign($dim, $valRef);
    }

    public function arrayUnset($dim)
    {
        $this->getVal()->arrayUnset($dim);
    }

    public function propertyFetch($name)
    {
        return $this->getVal()->propertyFetch($name);
    }

    public function propertyAssign($name, ValRef $valRef)
    {
        $this->getVal()->propertyAssign($name, $valRef);
    }

    public function propertyUnset($name)
    {
        $this->getVal()->propertyUnset($name);
    }

    public function __toString()
    {
        return "ByRef{{$this->variable} in scope {$this->scope}}";
    }

    public function getVariable()
    {
        return $this->variable;
    }

    private function getVal()
    {
        // References can form cycles ($a = &$b; $b = &$a; $a['x'] = &$a): the
        // chain is followed iteratively, and resolving a hop can re-enter here
        // through an array element, so the re-entrancy depth is bounded too.
        static $depth = 0;
        if ($depth > 64) {
            throw new Exceptions\UnknownValueException("Reference cycle");
        }
        $depth++;
        try {
            $val = $this->variable->getValue($this->scope);
            $hops = 0;
            while ($val instanceof ByReference) {
                if (++$hops > 32) {
                    throw new Exceptions\UnknownValueException("Reference cycle");
                }
                $val = $val->variable->getValue($val->scope);
            }
        } finally {
            $depth--;
        }
        if ($val === null) {
            throw new Exceptions\UnknownValueException("Cannot get value of reference");
        }
        return $val;
    }

}
