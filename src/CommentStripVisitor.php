<?php

namespace PHPDeobfuscator;

use PhpParser\Node;

/**
 * Removes every comment (regular, trailing and doc comments) carried by the
 * input. The pretty printer emits comments solely from the `comments` node
 * attribute, so clearing it strips them. Run before any annotation pass so
 * the -o / -a annotations, added later, are preserved.
 */
class CommentStripVisitor extends \PhpParser\NodeVisitorAbstract
{
    public function enterNode(Node $node)
    {
        $node->setAttribute('comments', array());
    }
}
