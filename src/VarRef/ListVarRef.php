<?php

namespace PHPDeobfuscator\VarRef;

use PHPDeobfuscator\Scope;
use PHPDeobfuscator\ValRef;
use PHPDeobfuscator\ValRef\ArrayVal;
use PHPDeobfuscator\VarRef;

class ListVarRef implements VarRef
{
    private array $vars;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
    }

    public function getValue(Scope $scope)
    {
        return null; // Cannot get value of list expression
    }

    public function assignValue(Scope $scope, ValRef $valRef)
    {
        // A list() destructure of an array whose contents are uncertain (e.g.
        // reassigned inside a branch) tells us nothing about the targets.
        if (!($valRef instanceof ArrayVal) || $valRef->isMutable()) {
            return false;
        }
        $didAssignAll = true;
        for ($i = count($this->vars) - 1; $i >=0; $i--) {
            $var = $this->vars[$i];
            if ($var === null) {
                continue;
            }
            try {
                $val = $valRef->arrayFetch($i);
            } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                $didAssignAll = false;
                continue;
            }
            if ($val === null) {
                continue;
            }
            $didAssignAll = $var->assignValue($scope, $val) && $didAssignAll;
        }
        return $didAssignAll;
    }

    public function unsetVar(Scope $scope)
    {
    }

    public function getVars()
    {
        return $this->vars;
    }

    public function __toString()
    {
        return "List(" . implode(', ', $this->vars) . ")";
    }

}
