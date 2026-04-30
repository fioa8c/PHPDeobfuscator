<?php

namespace PHPDeobfuscator\Analysis;

class ReportFormatter
{
    public function formatFixture(Findings $f): string
    {
        $sinks = [];
        $errors = [];
        foreach ($f->sortedSinks() as $s) {
            if ($s->kind === 'meta') $errors[] = $s; else $sinks[] = $s;
        }
        $lines = [];
        $lines[] = 'sources:';
        foreach ($f->sortedSources() as $s) {
            $lines[] = '  ' . $s->context . '  line ' . $s->line . '  ' . $s->label;
        }
        $lines[] = 'sinks:';
        foreach ($sinks as $s) {
            $note = $s->note === null ? '' : ' (' . $s->note . ')';
            $lines[] = '  ' . $s->context . '  ' . $s->category . '  line ' . $s->line . '  ' . $s->label . $note;
        }
        if (!empty($errors)) {
            $lines[] = 'errors:';
            foreach ($errors as $e) {
                $lines[] = '  ' . $e->context . '  ' . $e->category . '  ' . $e->label;
            }
        }
        return implode("\n", $lines);
    }

    public function formatText(Findings $f, string $filename = ''): string
    {
        $sources = $f->sortedSources();
        $sinks = [];
        $errors = [];
        foreach ($f->sortedSinks() as $s) {
            if ($s->kind === 'meta') $errors[] = $s; else $sinks[] = $s;
        }

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
        if (!empty($errors)) {
            $out[] = '';
            $out[] = 'Errors (' . count($errors) . '):';
            foreach ($errors as $e) {
                $out[] = '  [' . $e->category . '] ' . $e->label;
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

    public function formatJson(Findings $f, string $filename = 'input.php'): string
    {
        $sinks = [];
        $errors = [];
        foreach ($f->sortedSinks() as $s) {
            if ($s->kind === 'meta') $errors[] = $s; else $sinks[] = $s;
        }
        $payload = [
            'version' => 1,
            'filename' => $filename,
            'sources' => array_map([$this, 'findingToArray'], $f->sortedSources()),
            'sinks' => array_map([$this, 'findingToArray'], $sinks),
            'errors' => array_map([$this, 'findingToArray'], $errors),
            'summary' => [
                'source_count' => count($f->getSources()),
                'sink_count' => count($sinks),
                'auto_exec_count' => $f->autoExecCount(),
                'categories_present' => $f->categoriesPresent(),
            ],
        ];
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function findingToArray(Finding $f): array
    {
        $out = [
            'category' => $f->category,
            'label' => $f->label,
            'line' => $f->line,
            'context' => $f->context,
        ];
        if ($f->kind === 'sink' || $f->kind === 'meta') {
            $out['note'] = $f->note;
        }
        return $out;
    }
}
