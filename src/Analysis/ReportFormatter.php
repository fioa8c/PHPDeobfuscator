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

    public function formatText(Findings $f, string $filename = ''): string
    {
        $sources = $f->sortedSources();
        $sinks = $f->sortedSinks();

        $contextWidth = 12;
        foreach (array_merge($sources, $sinks) as $finding) {
            $w = strlen('[' . $finding->context . ']');
            if ($w > $contextWidth) $contextWidth = $w;
        }
        $contextWidth += 2;

        $catWidth = 4;
        foreach ($sinks as $finding) {
            if (strlen($finding->category) > $catWidth) $catWidth = strlen($finding->category);
        }
        $catWidth += 2;

        $out = [];
        $out[] = '===== Analysis =====';
        $out[] = '';
        $out[] = 'Sources (' . count($sources) . '):';
        if (count($sources) === 0) {
            $out[] = '  (none)';
        } else {
            foreach ($sources as $s) {
                $ctx = str_pad('[' . $s->context . ']', $contextWidth);
                $out[] = '  ' . $ctx . 'line ' . $s->line . '   ' . $s->label;
            }
        }
        $out[] = '';
        $out[] = 'Sinks (' . count($sinks) . '):';
        if (count($sinks) === 0) {
            $out[] = '  (none)';
        } else {
            foreach ($sinks as $s) {
                $ctx = str_pad('[' . $s->context . ']', $contextWidth);
                $cat = str_pad($s->category, $catWidth);
                $note = $s->note === null ? '' : ' (' . $s->note . ')';
                $out[] = '  ' . $ctx . $cat . 'line ' . $s->line . '   ' . $s->label . $note;
            }
        }
        $out[] = '';
        $cats = $f->categoriesPresent();
        $out[] = sprintf(
            'Summary: %d sources, %d sinks (%d auto-exec out of %d total findings). Categories present: %s.',
            count($sources),
            count($sinks),
            $f->autoExecCount(),
            $f->count(),
            count($cats) === 0 ? '(none)' : implode(', ', $cats)
        );

        return implode("\n", $out);
    }
}
