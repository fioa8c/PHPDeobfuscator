<?php

namespace PHPDeobfuscator\Analysis;

class Findings
{
    /** @var Finding[] */
    private array $sources = [];
    /** @var Finding[] */
    private array $sinks = [];
    /** @var Finding[] */
    private array $meta = [];

    public function addSource(Finding $f): void
    {
        $this->sources[] = $f;
    }

    public function addSink(Finding $f): void
    {
        $this->sinks[] = $f;
    }

    public function addMeta(Finding $f): void
    {
        $this->meta[] = $f;
    }

    /** @return Finding[] */
    public function getSources(): array
    {
        return $this->sources;
    }

    /** @return Finding[] */
    public function getSinks(): array
    {
        return $this->sinks;
    }

    /** @return Finding[] */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function count(): int
    {
        return count($this->sources) + count($this->sinks);
    }

    public function autoExecCount(): int
    {
        $n = 0;
        foreach ($this->sources as $f) if ($f->isAutoExec()) $n++;
        foreach ($this->sinks as $f) if ($f->isAutoExec()) $n++;
        return $n;
    }

    /** @return string[] */
    public function categoriesPresent(): array
    {
        $cats = [];
        foreach ($this->sinks as $f) $cats[$f->category] = true;
        $names = array_keys($cats);
        sort($names);
        return $names;
    }

    /** @return Finding[] */
    public function sortedSources(): array
    {
        return $this->sortByContextThenLine($this->sources);
    }

    /** @return Finding[] */
    public function sortedSinks(): array
    {
        return $this->sortByContextThenLine($this->sinks);
    }

    /** @param Finding[] $list @return Finding[] */
    private function sortByContextThenLine(array $list): array
    {
        usort($list, function (Finding $a, Finding $b): int {
            $ra = $a->isAutoExec() ? 0 : 1;
            $rb = $b->isAutoExec() ? 0 : 1;
            if ($ra !== $rb) return $ra - $rb;
            if ($a->line !== $b->line) return $a->line - $b->line;
            return strcmp($a->label, $b->label);
        });
        return $list;
    }
}
