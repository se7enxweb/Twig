<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node\Expression\Test;

use Twig\Compiler;
use Twig\Node\CoercesChildrenToStringInterface;
use Twig\Node\Expression\TestExpression;

/**
 * Checks if a variable is the same as another one (=== in PHP).
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class SameasTest extends TestExpression implements CoercesChildrenToStringInterface
{
    public function compile(Compiler $compiler)
    {
        $compiler
            ->raw('(')
            ->subcompile($this->getNode('node'))
            ->raw(' === ')
            ->subcompile($this->getNode('arguments')->getNode(0))
            ->raw(')')
        ;
    }

    public function getStringCoercedChildNames(): array
    {
        // `===` is strict, no coercion
        return [];
    }
}

class_alias('Twig\Node\Expression\Test\SameasTest', 'Twig_Node_Expression_Test_Sameas');
