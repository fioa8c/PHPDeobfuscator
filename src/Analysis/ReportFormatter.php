<?php

namespace PHPDeobfuscator\Analysis;

class ReportFormatter
{
    public function formatFixture(Findings $f): string
    {
        $lines = [];
        $lines[] = 'sources:';
        foreach ($f->sortedSources() as $s) {
            $lines[] = '  ' . $s->context . '  line ' . $s->line . '  ' . $s->label;
        }
        $lines[] = 'sinks:';
        foreach ($f->sortedSinks() as $s) {
            $note = $s->note === null ? '' : ' (' . $s->note . ')';
            $lines[] = '  ' . $s->context . '  ' . $s->category . '  line ' . $s->line . '  ' . $s->label . $note;
        }
        return implode("\n", $lines);
    }
}
