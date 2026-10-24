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

class FunctionExpression extends CallExpression implements CoercesChildrenToStringInterface
{
    public function __construct(string $name, Node $arguments, int $lineno)
    {
        parent::__construct(['arguments' => $arguments], ['name' => $name, 'is_defined_test' => false], $lineno);
    }

    public function compile(Compiler $compiler)
    {
        $name = $this->getAttribute('name');
        $function = $compiler->getEnvironment()->getFunction($name);

        $this->setAttribute('name', $name);
        $this->setAttribute('type', 'function');
        $this->setAttribute('needs_environment', $function->needsEnvironment());
        $this->setAttribute('needs_context', $function->needsContext());
        $this->setAttribute('arguments', $function->getArguments());
        $callable = $function->getCallable();
        if ('constant' === $name && $this->getAttribute('is_defined_test')) {
            $callable = 'twig_constant_is_defined';
        }
        $this->setAttribute('callable', $callable);
        $this->setAttribute('is_variadic', $function->isVariadic());

        $this->compileCallable($compiler);
    }

    public function getStringCoercedChildNames(): array
    {
        // a function may coerce its arguments to string (the host PHP code is opaque to Twig)
        return ['arguments'];
    }
}

class_alias('Twig\Node\Expression\FunctionExpression', 'Twig_Node_Expression_Function');
