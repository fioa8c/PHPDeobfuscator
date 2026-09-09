<?php

namespace PHPDeobfuscator\Reducer\FuncCallReducer;

use PhpParser\Node\Expr\FuncCall;
use League\Flysystem\Filesystem;

use PHPDeobfuscator\AttrName;
use PHPDeobfuscator\Exceptions;
use PHPDeobfuscator\Utils;
use PHPDeobfuscator\ValRef\ResourceValue;

class FileSystemCall implements FunctionReducer
{
    /**
     * Reads larger than this are left as a call instead of being folded into a
     * string literal. Self-replicating malware routinely does
     * `file_get_contents(__FILE__)`; folding that inlines the whole (still
     * obfuscated) source at every read site, which buries the real code without
     * revealing anything the call itself did not already say.
     */
    public const DEFAULT_MAX_INLINE_BYTES = 65536;

    private $fileSystem;
    private int $maxInlineBytes;

    public function __construct(FileSystem $fileSystem, ?int $maxInlineBytes = null)
    {
        $this->fileSystem = $fileSystem;
        $this->maxInlineBytes = $maxInlineBytes ?? self::DEFAULT_MAX_INLINE_BYTES;
    }

    /** Null when the read is too large to be worth inlining. */
    private function readCapped(string $filename): ?string
    {
        try {
            $content = $this->fileSystem->read($filename);
        } catch (\League\Flysystem\FilesystemException $e) {
            return null;
        }
        if ($this->maxInlineBytes >= 0 && strlen($content) > $this->maxInlineBytes) {
            return null;
        }
        return $content;
    }

    public function getSupportedNames()
    {
        return array(
            'file_get_contents',
            'file',
            'fopen',
            'fread',
            'fwrite',
            'fclose',
        );
    }

    public function execute($name, array $args, FuncCall $node)
    {
        if (method_exists($this, $name . 'Prepare')) {
            $args = call_user_func(array($this, $name . 'Prepare'), $args, $node);
        } else {
            $args = Utils::refsToValues($args);
        }
        return call_user_func_array(array($this, $name), $args);
    }

    private function file_get_contents($filename, $flags = 0, $context = null, $offset = -1, $maxlen = -1)
    {
        if (Utils::safeFileExists($this->fileSystem, $filename)) {
            $content = $this->readCapped($filename);
            if ($content === null) {
                return null;
            }
            return Utils::scalarToNode($content);
        }
        return null;
    }

    private function file($filename, $flags = 0, $context = null)
    {
        if (Utils::safeFileExists($this->fileSystem, $filename)) {
            $content = $this->readCapped($filename);
            if ($content === null) {
                return null;
            }
            $lines = preg_split("/(\r\n|\r|\n)/", $content);
            return Utils::scalarToNode($lines);
        }
        return null;
    }

    private function fopenPrepare(array $args, FuncCall $node)
    {
        return array_merge(array($node), Utils::refsToValues($args));
    }

    private function fopen(FuncCall $node, $filename, $mode, $use_include_path = false, $context = null)
    {
        if (strpos($mode, 'r') !== false) {
            if (!Utils::safeFileExists($this->fileSystem, $filename)) {
                return;
            }
            $stream = $this->fileSystem->readStream($filename);
        } elseif (strpos($mode, 'w') !== false) {
            $stream = fopen('php://memory', 'w+b');
            try {
                $this->fileSystem->writeStream($filename, $stream);
            } catch (\League\Flysystem\FilesystemException $e) {
                return; // e.g. path traversal outside the virtual root
            }
        } else {
            return;
        }
        $node->setAttribute(AttrName::VALUE, new ResourceValue($filename, $stream));
    }

    private function firstArgIsResource(array $args)
    {
        $newArgs = array();
        foreach ($args as $i => $arg) {
            if ($i == 0) {
                if (!($arg instanceof ResourceValue)) {
                    throw new Exceptions\BadValueException("file handle is not a resource");
                }
                if ($arg->isClosed()) {
                    throw new Exceptions\BadValueException("file handle is closed");
                }
                $newArgs[] = $arg;
            } else {
                $newArgs[] = $arg->getValue();
            }
        }
        return $newArgs;
    }

    private function freadPrepare(array $args, FuncCall $node)
    {
        return $this->firstArgIsResource($args);
    }

    private function fread(ResourceValue $handle, $length)
    {
        return Utils::scalarToNode(fread($handle->getResource(), $length));
    }

    private function fwritePrepare(array $args, FuncCall $node)
    {
        return $this->firstArgIsResource($args);
    }

    private function fwrite(ResourceValue $handle, $string, $length = null)
    {
        if ($length !== null) {
            fwrite($handle->getResource(), $string, $length);
        } else {
            fwrite($handle->getResource(), $string);
        }
        $this->fileSystem->writeStream($handle->getFilename(), $handle->getResource());
    }

    private function fclosePrepare(array $args, FuncCall $node)
    {
        return $this->firstArgIsResource($args);
    }

    private function fclose(ResourceValue $handle)
    {
        fclose($handle->getResource());
        $handle->close();
    }
}
