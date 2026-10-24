<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node\Expression;

use Twig\Compiler;
use Twig\Node\CoercesChildrenToStringInterface;
use Twig\Node\Node;

class TestExpression extends CallExpression implements CoercesChildrenToStringInterface
{
    public function __construct(Node $node, string $name, ?Node $arguments, int $lineno)
    {
        $nodes = ['node' => $node];
        if (null !== $arguments) {
            $nodes['arguments'] = $arguments;
        }

        parent::__construct($nodes, ['name' => $name], $lineno);
    }

    public function compile(Compiler $compiler)
    {
        $name = $this->getAttribute('name');
        $test = $compiler->getEnvironment()->getTest($name);

        $this->setAttribute('name', $name);
        $this->setAttribute('type', 'test');
        $this->setAttribute('arguments', $test->getArguments());
        $this->setAttribute('callable', $test->getCallable());
        $this->setAttribute('is_variadic', $test->isVariadic());

        $this->compileCallable($compiler);
    }

    public function getStringCoercedChildNames(): array
    {
        $names = [];

        // the `empty` test triggers an implicit string coercion through twig_test_empty()
        if ('empty' === $this->getAttribute('name')) {
            $names[] = 'node';
        }

        // a test may coerce its arguments to string (the host PHP code is opaque to Twig)
        if ($this->hasNode('arguments')) {
            $names[] = 'arguments';
        }

        return $names;
    }
}

class_alias('Twig\Node\Expression\TestExpression', 'Twig_Node_Expression_Test');
