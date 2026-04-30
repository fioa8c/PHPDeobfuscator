<?php

namespace PHPDeobfuscator\Analysis;

class Finding
{
    public string $kind;
    public string $category;
    public string $label;
    public int $line;
    public string $context;
    public ?string $note;

    public function __construct(
        string $kind,
        string $category,
        string $label,
        int $line,
        string $context,
        ?string $note = null
    ) {
        $this->kind = $kind;
        $this->category = $category;
        $this->label = $label;
        $this->line = $line;
        $this->context = $context;
        $this->note = $note;
    }

    public function isAutoExec(): bool
    {
        return $this->context === 'auto-exec';
    }
}
