<?php

namespace PHPDeobfuscator\ValRef;

use PHPDeobfuscator\ValRef;

class ScalarValue extends AbstractValRef
{
    private $value;

    public function __construct($value)
    {
        if (!(is_scalar($value) || is_null($value))) {
            throw new \InvalidArgumentException("Value not scalar!");
        }
        $this->value = $value;
    }

    public function __toString()
    {
        return "Val{{$this->value}}";
    }

    protected function getValueImpl()
    {
        return $this->value;
    }

    public function arrayFetch($dim)
    {
        $val = $this->getValue();
        if (isset($val[$dim])) {
            return new ScalarValue($val[$dim]);
        }
        return new ScalarValue(null);
    }

    public function arrayAssign($dim, ValRef $valRef)
    {
        $new = $valRef->getValue();
        if (is_string($this->value)) {
            // String offset write: PHP only accepts integer offsets and stores a
            // single byte; anything else is a runtime error, i.e. unknowable here.
            $isIntDim = is_int($dim) || (is_string($dim) && preg_match('/^-?\d+$/', $dim) === 1);
            if ($dim === null || !$isIntDim || !is_scalar($new) || (string)$new === '') {
                throw new \PHPDeobfuscator\Exceptions\BadValueException("Unsupported string offset write");
            }
            try {
                $this->value[(int)$dim] = (string)$new;
            } catch (\Throwable $e) {
                throw new \PHPDeobfuscator\Exceptions\BadValueException("Unsupported string offset write");
            }
            return;
        }
        if ($this->value !== null && $this->value !== false && !is_array($this->value)) {
            // "Cannot use a scalar value as an array"
            throw new \PHPDeobfuscator\Exceptions\BadValueException("Array write to scalar");
        }
        if ($dim === null) {
            $this->value[] = $new;
        } else {
            $this->value[$dim] = $new;
        }
    }
}
