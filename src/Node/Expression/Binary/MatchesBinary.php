<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node\Expression\Binary;

use Twig\Compiler;
use Twig\Node\CoercesChildrenToStringInterface;

class MatchesBinary extends AbstractBinary implements CoercesChildrenToStringInterface
{
    public function compile(Compiler $compiler)
    {
        $compiler
            ->raw('preg_match(')
            ->subcompile($this->getNode('right'))
            ->raw(', ')
            ->subcompile($this->getNode('left'))
            ->raw(')')
        ;
    }

    public function operator(Compiler $compiler)
    {
        return $compiler->raw('');
    }

    public function getStringCoercedChildNames(): array
    {
        return ['left', 'right'];
    }
}

class_alias('Twig\Node\Expression\Binary\MatchesBinary', 'Twig_Node_Expression_Binary_Matches');
